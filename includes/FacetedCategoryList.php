<?php

namespace MediaWiki\Extension\FacetedCategory;

use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\LikeValue;

/**
 * Searches the faceted categories, the non-empty categories named "Facet/Member".
 *
 * The whole list is read from the category table at most once an hour and kept
 * in the WAN cache, so that a search, which runs as an editor types, never
 * queries the database.
 */
class FacetedCategoryList {

	/** Most results of a search */
	public const MAX_LIMIT = 50;

	private IConnectionProvider $connectionProvider;
	private WANObjectCache $cache;

	public function __construct(
		IConnectionProvider $connectionProvider,
		WANObjectCache $cache
	) {
		$this->connectionProvider = $connectionProvider;
		$this->cache = $cache;
	}

	/**
	 * Find the faceted categories whose member contains a text. A text with a
	 * slash is split into a facet, which must match exactly, and the start of
	 * a member.
	 *
	 * @param string $search Text to search for, with spaces or underscores
	 * @param string|null $facet Only search the members of this facet
	 * @param int $limit
	 * @return array<int,array{title:string,facet:string,member:string,pages:int}> DB keys,
	 *  members starting with the text first, then the ones with more pages
	 */
	public function search( string $search, ?string $facet, int $limit ): array {
		$search = trim( str_replace( ' ', '_', $search ), '_' );
		$facet = $facet === null ? null : str_replace( ' ', '_', $facet );
		$prefixOnly = false;
		$slash = strpos( $search, '/' );
		if ( $slash !== false ) {
			$facet = substr( $search, 0, $slash );
			$search = substr( $search, $slash + 1 );
			$prefixOnly = true;
		}

		$matches = [];
		foreach ( $this->getAll() as $title => $pages ) {
			$title = (string)$title;
			$slash = strpos( $title, '/' );
			$titleFacet = substr( $title, 0, $slash );
			$member = substr( $title, $slash + 1 );
			if ( $facet !== null && $titleFacet !== $facet ) {
				continue;
			}
			$position = $search === '' ? 0 : mb_stripos( $member, $search );
			if ( $position === false || ( $prefixOnly && $position !== 0 ) ) {
				continue;
			}
			$matches[] = [
				'title' => $title,
				'facet' => $titleFacet,
				'member' => $member,
				'pages' => $pages,
				'prefix' => $position === 0,
			];
		}

		usort( $matches, static fn ( $a, $b ) => [ $b['prefix'], $b['pages'], $a['title'] ]
			<=> [ $a['prefix'], $a['pages'], $b['title'] ] );
		return array_map(
			static function ( $match ) {
				unset( $match['prefix'] );
				return $match;
			},
			array_slice( $matches, 0, $limit )
		);
	}

	/**
	 * @return array<string,int> Numbers of pages by DB key of faceted category
	 */
	private function getAll(): array {
		$fname = __METHOD__;
		// One string rather than an array, to keep the cached value small
		$value = $this->cache->getWithSetCallback(
			$this->cache->makeGlobalKey( 'facetedcategory-list' ),
			WANObjectCache::TTL_HOUR,
			function () use ( $fname ) {
				$dbr = $this->connectionProvider->getReplicaDatabase();
				$rows = $dbr->newSelectQueryBuilder()
					->select( [ 'cat_title', 'cat_pages' ] )
					->from( 'category' )
					->where( [
						$dbr->expr( 'cat_pages', '>', 0 ),
						$dbr->expr( 'cat_title', IExpression::LIKE, new LikeValue(
							$dbr->anyChar(), $dbr->anyString(), '/', $dbr->anyChar(), $dbr->anyString()
						) ),
					] )
					->caller( $fname )
					->fetchResultSet();
				$lines = [];
				foreach ( $rows as $row ) {
					$lines[] = $row->cat_title . "\t" . $row->cat_pages;
				}
				return implode( "\n", $lines );
			},
			[ 'pcTTL' => WANObjectCache::TTL_PROC_LONG ]
		);

		$all = [];
		foreach ( explode( "\n", $value ) as $line ) {
			if ( $line === '' ) {
				continue;
			}
			[ $title, $pages ] = explode( "\t", $line );
			$all[$title] = (int)$pages;
		}
		return $all;
	}
}
