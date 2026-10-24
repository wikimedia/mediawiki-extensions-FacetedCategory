<?php

namespace MediaWiki\Extension\FacetedCategory;

use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * Finds the pages in all of some categories and in none of some others.
 */
class CategoryIntersection {

	private IConnectionProvider $connectionProvider;
	/** @var string[] DB keys of the categories the pages are in */
	private array $include;
	/** @var string[] DB keys of the categories the pages are not in */
	private array $exclude;

	/**
	 * @param IConnectionProvider $connectionProvider
	 * @param string[] $include DB keys of the categories the pages must be in
	 * @param string[] $exclude DB keys of the categories the pages must not be in
	 */
	public function __construct( IConnectionProvider $connectionProvider, array $include, array $exclude ) {
		$this->connectionProvider = $connectionProvider;
		$this->include = $include;
		$this->exclude = $exclude;
	}

	/**
	 * @param int $max
	 * @return int The number of pages, or $max + 1 when there are more than $max
	 */
	public function count( int $max ): int {
		return count( $this->getPageIds( $max + 1 ) );
	}

	/**
	 * @param int $limit
	 * @return int[] IDs of the pages, in no particular order
	 */
	public function getPageIds( int $limit ): array {
		$query = $this->newQuery( $this->connectionProvider->getReplicaDatabase() );
		if ( !$query ) {
			return [];
		}
		return array_map( 'intval', $query
			->select( 'cl0.cl_from' )
			->limit( $limit )
			->caller( __METHOD__ )
			->fetchFieldValues() );
	}

	/**
	 * @param string|null $from Sort key of the first page, from the category collation
	 * @param int $limit
	 * @return array<int,object> Rows with page_id, page_namespace, page_title,
	 *  cl_sortkey and cl_sortkey_prefix, sorted by sort key in the first category
	 */
	public function getPages( ?string $from, int $limit ): array {
		$dbr = $this->connectionProvider->getReplicaDatabase();
		$query = $this->newQuery( $dbr );
		if ( !$query ) {
			return [];
		}
		$query
			->select( [
				'page_id', 'page_namespace', 'page_title',
				'cl_sortkey' => 'cl0.cl_sortkey', 'cl_sortkey_prefix' => 'cl0.cl_sortkey_prefix',
			] )
			->join( 'page', null, 'page_id = cl0.cl_from' )
			->orderBy( [ 'cl0.cl_sortkey', 'cl0.cl_from' ], SelectQueryBuilder::SORT_ASC )
			->limit( $limit )
			->caller( __METHOD__ );
		if ( $from !== null ) {
			$query->andWhere( $dbr->expr( 'cl0.cl_sortkey', '>=', $from ) );
		}
		return iterator_to_array( $query->fetchResultSet() );
	}

	/**
	 * @param IReadableDatabase $dbr
	 * @return SelectQueryBuilder|null A query on the category links of the
	 *  pages, aliased cl0, or null when no page can match
	 */
	private function newQuery( IReadableDatabase $dbr ): ?SelectQueryBuilder {
		if ( !$this->include ) {
			return null;
		}
		$ids = [];
		$res = $dbr->newSelectQueryBuilder()
			->select( [ 'lt_id', 'lt_title' ] )
			->from( 'linktarget' )
			->where( [
				'lt_namespace' => NS_CATEGORY,
				'lt_title' => array_merge( $this->include, $this->exclude ),
			] )
			->caller( __METHOD__ )
			->fetchResultSet();
		foreach ( $res as $row ) {
			$ids[(string)$row->lt_title] = (int)$row->lt_id;
		}
		if ( array_diff( $this->include, array_keys( $ids ) ) ) {
			return null;
		}

		// Start from the smallest category
		$sizes = array_fill_keys( $this->include, 0 );
		$res = $dbr->newSelectQueryBuilder()
			->select( [ 'cat_title', 'cat_pages' ] )
			->from( 'category' )
			->where( [ 'cat_title' => $this->include ] )
			->caller( __METHOD__ )
			->fetchResultSet();
		foreach ( $res as $row ) {
			$sizes[(string)$row->cat_title] = (int)$row->cat_pages;
		}
		asort( $sizes );
		$include = array_map( 'strval', array_keys( $sizes ) );

		$query = $dbr->newSelectQueryBuilder()
			->from( 'categorylinks', 'cl0' )
			->where( [ 'cl0.cl_target_id' => $ids[$include[0]] ] );
		foreach ( array_slice( $include, 1 ) as $i => $category ) {
			$alias = 'cl' . ( $i + 1 );
			$query->join( 'categorylinks', $alias, [
				"$alias.cl_from = cl0.cl_from",
				"$alias.cl_target_id" => $ids[$category],
			] );
		}
		$exclude = array_values( array_intersect_key( $ids, array_flip( $this->exclude ) ) );
		if ( $exclude ) {
			$query
				->leftJoin( 'categorylinks', 'clx', [
					'clx.cl_from = cl0.cl_from',
					'clx.cl_target_id' => $exclude,
				] )
				->andWhere( [ 'clx.cl_from' => null ] );
		}
		return $query;
	}
}
