<?php

namespace MediaWiki\Extension\FacetedCategory;

use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Title\Title;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;

/**
 * Finds the category combinations to suggest on a page: the ones editors named
 * with the {{#categorycombination:}} parser function, and ones computed from
 * the faceted categories of the page.
 *
 * Page views only read the cache. The combinations are computed by
 * CategoryCombinationsJob, which reads the members of the small categories of
 * the page only.
 */
class CategoryCombinations {

	/** @const string Page property which holds the combinations defined on a page */
	public const PROPERTY_NAME = 'facetedcategory-combinations';

	/** Fewest pages, including the page itself, for a combination to be worth showing */
	private const MIN_PAGES = 3;
	/** Most pages for a computed combination, above which it is too broad */
	private const MAX_PAGES = 50;
	/** Number of pages computed combinations close to which are preferred */
	private const PREFERRED_PAGES = 15;
	/** Categories with more pages are never read whole */
	private const MAX_SMALL_CATEGORY_PAGES = 1000;
	/** Most pages read from the small categories of a page */
	private const MAX_LOADED_PAGES = 2000;
	/** Page IDs per query when reading the categories of pages */
	private const BATCH_SIZE = 500;
	/** Computed combinations shown on a page */
	private const MAX_COMPUTED = 3;
	/** Named combinations shown on a page */
	private const MAX_NAMED = 5;
	/** Seconds after which the cached combinations of a page are computed again */
	private const REFRESH_AFTER = WANObjectCache::TTL_DAY;

	private IConnectionProvider $connectionProvider;
	private WANObjectCache $cache;
	private JobQueueGroup $jobQueueGroup;

	public function __construct(
		IConnectionProvider $connectionProvider,
		WANObjectCache $cache,
		JobQueueGroup $jobQueueGroup
	) {
		$this->connectionProvider = $connectionProvider;
		$this->cache = $cache;
		$this->jobQueueGroup = $jobQueueGroup;
	}

	/**
	 * Parse the arguments of {{#categorycombination:name|category|category...}}.
	 *
	 * @param string[] $args
	 * @return array{name:string,categories:string[]}|null Categories are DB keys.
	 *  Null when the arguments do not make a combination.
	 */
	public static function parseArguments( array $args ): ?array {
		$name = trim( array_shift( $args ) ?? '' );
		$categories = [];
		foreach ( $args as $arg ) {
			$arg = trim( $arg );
			if ( $arg === '' ) {
				continue;
			}
			$title = Title::newFromText( $arg, NS_CATEGORY );
			if ( !$title || $title->getNamespace() !== NS_CATEGORY ) {
				return null;
			}
			$categories[] = $title->getDBkey();
		}
		$categories = array_values( array_unique( $categories ) );
		if ( $name === '' || count( $categories ) < 2 ) {
			return null;
		}
		return [ 'name' => $name, 'categories' => $categories ];
	}

	/**
	 * Get the combinations to show on a page from the cache. This never queries
	 * the database: when the cached value is missing or old, a job computes it.
	 *
	 * @param Title $title The page
	 * @param string[] $categories DB keys of the categories of the page
	 * @param string[] $direct DB keys of the categories which the page adds itself,
	 *  rather than from a parent category
	 * @return array<int,array{name:?string,categories:string[],count:int}> Categories are DB keys
	 */
	public function getForPage( Title $title, array $categories, array $direct ): array {
		$categories = self::filterFaceted( $categories );
		if ( count( $categories ) < 2 ) {
			return [];
		}
		$direct = array_values( array_intersect( $categories, $direct ) );
		$value = $this->cache->get( $this->makePageKey( $title, $categories, $direct ) );
		if (
			!is_array( $value ) ||
			( $value['named'] ?? null ) !== $this->getNamedVersion() ||
			$value['time'] < time() - self::REFRESH_AFTER
		) {
			$this->jobQueueGroup->lazyPush( new CategoryCombinationsJob( [
				'namespace' => $title->getNamespace(),
				'title' => $title->getDBkey(),
				'categories' => $categories,
				'direct' => $direct,
			] ) );
		}
		return is_array( $value ) ? $value['combinations'] : [];
	}

	/**
	 * Compute the combinations of a page and cache them.
	 *
	 * @param Title $title The page
	 * @param string[] $categories DB keys of the categories of the page
	 * @param string[] $direct DB keys of the categories which the page adds itself
	 */
	public function refresh( Title $title, array $categories, array $direct ): void {
		$categories = self::filterFaceted( $categories );
		if ( count( $categories ) < 2 ) {
			return;
		}
		$direct = array_values( array_intersect( $categories, $direct ) );
		// Read the version first, so that a change during the computation refreshes again
		$version = $this->getNamedVersion();
		$this->cache->set(
			$this->makePageKey( $title, $categories, $direct ),
			[
				'time' => time(),
				'named' => $version,
				'combinations' => $this->compute( $categories, $direct, $version ),
			],
			WANObjectCache::TTL_WEEK
		);
	}

	/**
	 * @param string[] $categories DB keys
	 * @return string|null The name editors gave to the combination of exactly these
	 *  categories
	 */
	public function findName( array $categories ): ?string {
		sort( $categories );
		foreach ( $this->getNamed( $this->getNamedVersion() ) as $combination ) {
			$set = $combination['categories'];
			sort( $set );
			if ( $set === $categories ) {
				return $combination['name'];
			}
		}
		return null;
	}

	/**
	 * Invalidate the cached named combinations, after a page added or removed some.
	 *
	 * This changes a version rather than touching a check key, because check keys
	 * only keep whole seconds: a list cached earlier in the same second as the
	 * change would still count as fresh.
	 */
	public function touchNamed(): void {
		$this->cache->set( $this->getNamedVersionKey(), self::newVersion(), WANObjectCache::TTL_INDEFINITE );
	}

	/**
	 * @return string The version of the named combinations, which changes when a page
	 *  adds or removes some
	 */
	private function getNamedVersion(): string {
		return $this->cache->getWithSetCallback(
			$this->getNamedVersionKey(),
			WANObjectCache::TTL_INDEFINITE,
			static fn () => self::newVersion()
		);
	}

	private function getNamedVersionKey(): string {
		return $this->cache->makeGlobalKey( 'facetedcategory-named-combinations', 'version' );
	}

	private static function newVersion(): string {
		return bin2hex( random_bytes( 8 ) );
	}

	/**
	 * @param Title $title
	 * @param string[] $categories
	 * @param string[] $direct
	 * @return string
	 */
	private function makePageKey( Title $title, array $categories, array $direct ): string {
		// The touched time changes when the page is edited, purged, or re-categorized
		return $this->cache->makeKey(
			'facetedcategory-combinations',
			$title->getArticleID(),
			$title->getTouched(),
			md5( implode( '|', $categories ) . '#' . implode( '|', $direct ) )
		);
	}

	/**
	 * @param string[] $categories DB keys of the faceted categories of the page
	 * @param string[] $direct DB keys of the ones the page adds itself
	 * @param string $version The version of the named combinations
	 * @return array<int,array{name:?string,categories:string[],count:int}>
	 */
	private function compute( array $categories, array $direct, string $version ): array {
		$dbr = $this->connectionProvider->getReplicaDatabase();
		[ $members, $sizes, $small ] = $this->loadMembers( $dbr, $categories );
		$result = [];
		// Page sets already shown, so that no two combinations show the same pages
		$shown = [];

		foreach ( $this->getNamed( $version ) as $combination ) {
			if ( count( $result ) >= self::MAX_NAMED ) {
				break;
			}
			$set = $combination['categories'];
			// Pages can be counted only when a small category is in the set
			if ( array_diff( $set, $categories ) || !array_intersect( $set, $small ) ) {
				continue;
			}
			$pages = self::intersect( $members, $set );
			$key = self::makeSetKey( array_keys( $pages ) );
			if ( count( $pages ) < self::MIN_PAGES || isset( $shown[$key] ) ) {
				continue;
			}
			$shown[$key] = true;
			$result[] = [
				'name' => $combination['name'],
				'categories' => $set,
				'count' => count( $pages ),
			];
		}

		$computed = 0;
		foreach ( self::computeCombinations( $members, $sizes, $small, $direct ) as [ $set, $pages ] ) {
			if ( $computed >= self::MAX_COMPUTED ) {
				break;
			}
			$key = self::makeSetKey( array_keys( $pages ) );
			if ( isset( $shown[$key] ) ) {
				continue;
			}
			$shown[$key] = true;
			$computed++;
			$result[] = [
				'name' => null,
				'categories' => $set,
				'count' => count( $pages ),
			];
		}

		return $result;
	}

	/**
	 * Read which of the categories of the page each page of its small categories
	 * is in. Any combination containing a small category can be counted from
	 * this, without reading the large categories.
	 *
	 * @param IReadableDatabase $dbr
	 * @param string[] $categories
	 * @return array{0:array<string,array<int,true>>,1:array<string,int>,2:string[]}
	 *  Members of each category among the read pages, the number of pages in each
	 *  category, and the small categories
	 */
	private function loadMembers( IReadableDatabase $dbr, array $categories ): array {
		$sizes = [];
		$res = $dbr->newSelectQueryBuilder()
			->select( [ 'cat_title', 'cat_pages' ] )
			->from( 'category' )
			->where( [ 'cat_title' => $categories ] )
			->caller( __METHOD__ )
			->fetchResultSet();
		foreach ( $res as $row ) {
			if ( (int)$row->cat_pages >= self::MIN_PAGES ) {
				$sizes[(string)$row->cat_title] = (int)$row->cat_pages;
			}
		}

		// The smallest categories, while their pages add up to a few
		asort( $sizes );
		$small = [];
		$total = 0;
		foreach ( $sizes as $category => $size ) {
			if ( $size > self::MAX_SMALL_CATEGORY_PAGES || $total + $size > self::MAX_LOADED_PAGES ) {
				break;
			}
			$small[] = (string)$category;
			$total += $size;
		}
		if ( !$small ) {
			return [ [], $sizes, [] ];
		}

		// Pages of the small categories, from the index on the category
		$pageIds = array_map( 'intval', $dbr->newSelectQueryBuilder()
			->select( 'cl_from' )
			->distinct()
			->from( 'categorylinks' )
			->join( 'linktarget', null, 'cl_target_id = lt_id' )
			->where( [
				'lt_namespace' => NS_CATEGORY,
				'lt_title' => $small,
				'cl_type' => 'page',
			] )
			->caller( __METHOD__ )
			->fetchFieldValues() );

		// Their categories among the categories of the page, from the primary key
		$members = [];
		foreach ( array_chunk( $pageIds, self::BATCH_SIZE ) as $batch ) {
			$res = $dbr->newSelectQueryBuilder()
				->select( [ 'cl_from', 'lt_title' ] )
				->from( 'categorylinks' )
				->join( 'linktarget', null, 'cl_target_id = lt_id' )
				->where( [
					'cl_from' => $batch,
					'lt_namespace' => NS_CATEGORY,
					'lt_title' => array_map( 'strval', array_keys( $sizes ) ),
				] )
				->caller( __METHOD__ )
				->fetchResultSet();
			foreach ( $res as $row ) {
				$members[(string)$row->lt_title][(int)$row->cl_from] = true;
			}
		}

		// All pages of the small categories were read, so their sizes are exact
		foreach ( $small as $category ) {
			$sizes[$category] = count( $members[$category] ?? [] );
		}
		return [ $members, $sizes, $small ];
	}

	/**
	 * @param array<string,array<int,true>> $members
	 * @param array<string,int> $sizes
	 * @param string[] $small
	 * @param string[] $direct
	 * @return array<int,array{0:string[],1:array<int,true>}> Combinations of two or
	 *  three categories from different facets, each with its pages, best first
	 */
	private static function computeCombinations(
		array $members, array $sizes, array $small, array $direct
	): array {
		$names = array_map( 'strval', array_keys( $members ) );
		$isSmall = array_fill_keys( $small, true );
		$n = count( $names );
		$found = [];
		$pairCounts = [];
		for ( $i = 0; $i < $n; $i++ ) {
			for ( $j = $i + 1; $j < $n; $j++ ) {
				$pair = [ $names[$i], $names[$j] ];
				if ( !self::hasDistinctFacets( $pair ) ) {
					continue;
				}
				$pages = self::intersect( $members, $pair );
				if ( count( $pages ) < self::MIN_PAGES ) {
					continue;
				}
				// Only a set with a small category has all its pages read
				if ( isset( $isSmall[$names[$i]] ) || isset( $isSmall[$names[$j]] ) ) {
					$pairCounts[self::makeSetKey( $pair )] = count( $pages );
					$found[] = [ $pair, $pages ];
				}
				for ( $k = $j + 1; $k < $n; $k++ ) {
					$triple = [ $names[$i], $names[$j], $names[$k] ];
					if ( !self::hasDistinctFacets( $triple ) || !array_intersect( $triple, $small ) ) {
						continue;
					}
					$triplePages = array_intersect_key( $pages, $members[$names[$k]] );
					if ( count( $triplePages ) >= self::MIN_PAGES ) {
						$found[] = [ $triple, $triplePages ];
					}
				}
			}
		}

		$combinations = [];
		foreach ( $found as [ $set, $pages ] ) {
			$count = count( $pages );
			if ( $count > self::MAX_PAGES ) {
				continue;
			}
			// Leave out a combination when one of its categories does not narrow it down
			foreach ( $set as $category ) {
				$rest = array_values( array_diff( $set, [ $category ] ) );
				$restCount = count( $rest ) === 1
					? $sizes[$rest[0]]
					: ( $pairCounts[self::makeSetKey( $rest )] ?? PHP_INT_MAX );
				if ( $restCount === $count ) {
					continue 2;
				}
			}
			// Categories which the page adds itself describe it better than their parents
			$directCount = count( array_intersect( $set, $direct ) );
			if ( count( $set ) - $directCount > 1 ) {
				continue;
			}
			$combinations[] = [
				$set,
				$pages,
				$directCount,
				abs( log( $count / self::PREFERRED_PAGES ) ),
			];
		}

		// More categories first, then more direct ones, then a number of pages close
		// to the preferred one
		usort( $combinations, static function ( $a, $b ) {
			return [ count( $b[0] ), $b[2], $a[3] ] <=> [ count( $a[0] ), $a[2], $b[3] ];
		} );

		// Leave out a combination which contains or is contained in a better one
		$picked = [];
		foreach ( $combinations as [ $set, $pages ] ) {
			foreach ( $picked as [ $other ] ) {
				if ( !array_diff( $set, $other ) || !array_diff( $other, $set ) ) {
					continue 2;
				}
			}
			$picked[] = [ $set, $pages ];
		}
		return $picked;
	}

	/**
	 * @param array<string,array<int,true>> $members
	 * @param string[] $set
	 * @return array<int,true> Pages in all the categories
	 */
	private static function intersect( array $members, array $set ): array {
		$pages = null;
		foreach ( $set as $category ) {
			$categoryPages = $members[$category] ?? [];
			$pages = $pages === null ? $categoryPages : array_intersect_key( $pages, $categoryPages );
		}
		return $pages ?? [];
	}

	/**
	 * @param string $version The version of the named combinations
	 * @return array<int,array{name:string,categories:string[]}>
	 */
	private function getNamed( string $version ): array {
		$fname = __METHOD__;
		return $this->cache->getWithSetCallback(
			$this->cache->makeGlobalKey( 'facetedcategory-named-combinations', 'list', $version ),
			WANObjectCache::TTL_DAY,
			function () use ( $fname ) {
				$values = $this->connectionProvider->getReplicaDatabase()->newSelectQueryBuilder()
					->select( 'pp_value' )
					->from( 'page_props' )
					->where( [ 'pp_propname' => self::PROPERTY_NAME ] )
					->caller( $fname )
					->fetchFieldValues();
				$named = [];
				foreach ( $values as $value ) {
					$combinations = json_decode( $value, true );
					if ( !is_array( $combinations ) ) {
						continue;
					}
					foreach ( $combinations as $combination ) {
						if ( isset( $combination['name'] ) && isset( $combination['categories'] ) ) {
							$named[] = $combination;
						}
					}
				}
				return $named;
			}
		);
	}

	/**
	 * @param string[] $categories
	 * @return string[] The faceted categories, sorted
	 */
	private static function filterFaceted( array $categories ): array {
		$categories = array_values( array_unique( array_filter(
			$categories,
			static fn ( $category ) => self::getFacet( $category ) !== null
		) ) );
		sort( $categories );
		return $categories;
	}

	/**
	 * @param string $category
	 * @return string|null The facet of a category named "Facet/Member"
	 */
	public static function getFacet( string $category ): ?string {
		$slash = strpos( $category, '/' );
		if ( $slash === false || $slash === 0 || $slash === strlen( $category ) - 1 ) {
			return null;
		}
		return substr( $category, 0, $slash );
	}

	/**
	 * @param string[] $categories
	 * @return bool
	 */
	private static function hasDistinctFacets( array $categories ): bool {
		$facets = array_map( [ self::class, 'getFacet' ], $categories );
		return count( array_unique( $facets ) ) === count( $facets );
	}

	/**
	 * @param array $items Category names or page IDs
	 * @return string
	 */
	private static function makeSetKey( array $items ): string {
		sort( $items );
		return implode( '|', $items );
	}
}
