<?php

namespace MediaWiki\Extension\FacetedCategory;

use IContextSource;
use MediaWiki\Category\Category;
use MediaWiki\Category\CategoryViewer;
use MediaWiki\HookContainer\ProtectedHookAccessorTrait;
use MediaWiki\Linker\LinksMigration;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\Rdbms\IResultWrapper;

class CategoryIntersectionSearchViewer extends CategoryViewer {
	use ProtectedHookAccessorTrait;

	private array $categories;
	private array $exCategories;
	private ILoadBalancer $loadBalancer;
	private LinksMigration $linksMigration;

	/**
	 * @inheritDoc
	 * @param array $categories
	 * @param array $exCategories
	 * @param ILoadBalancer $loadBalancer
	 * @param LinksMigration $linksMigration
	 */
	public function __construct(
		Title $title,
		IContextSource $context,
		array $from,
		array $until,
		array $query,
		array $categories,
		array $exCategories,
		ILoadBalancer $loadBalancer,
		LinksMigration $linksMigration
	) {
		parent::__construct( $title, $context, $from, $until, $query );
		$this->categories = $categories;
		$this->exCategories = $exCategories;
		$this->loadBalancer = $loadBalancer;
		$this->linksMigration = $linksMigration;
	}

	public function doCategoryQuery() {
		// 여기서부터 아래는 mediawiki 1.27의 CategoryViewer.php의 doCategoryQuery()과 동일
		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );

		$this->nextPage = [
			'page' => null,
			'subcat' => null,
			'file' => null,
		];
		$this->prevPage = [
			'page' => null,
			'subcat' => null,
			'file' => null,
		];

		$this->flip = [
			'page' => false,
			'subcat' => false,
			'file' => false,
		];

		foreach ( [ 'page', 'subcat', 'file' ] as $type ) {
			$extraConds = [ 'cl_type' => $type ];
			$from = $this->from[$type] ?? null;
			$until = $this->until[$type] ?? null;
			if ( $from !== null ) {
				$extraConds[] = 'cl_sortkey >= '
					. $dbr->addQuotes( $this->collation->getSortKey( $from ) );
			} elseif ( $until !== null ) {
				$extraConds[] = 'cl_sortkey < '
					. $dbr->addQuotes( $this->collation->getSortKey( $until ) );
				$this->flip[$type] = true;
			}
			// 위에서 여기까지는 mediawiki 1.27의 CategoryViewer.php의 doCategoryQuery()과 동일

			$res = $this->selectCategories( $dbr, $type, $extraConds );

			// 여기서부터 아래는 mediawiki 1.27의 CategoryViewer.php의 doCategoryQuery()과 동일
			$this->getHookRunner()->onCategoryViewer__doCategoryQuery( $type, $res );

			$count = 0;
			foreach ( $res as $row ) {
				$title = Title::newFromRow( $row );
				$humanSortkey = $title->getCategorySortkey( $row->cl_sortkey_prefix );

				if ( ++$count > $this->limit ) {
					# We've reached the one extra which shows that there
					# are additional pages to be had. Stop here...
					$this->nextPage[$type] = $humanSortkey;
					break;
				}
				if ( $count == $this->limit ) {
					$this->prevPage[$type] = $humanSortkey;
				}

				if ( $title->getNamespace() == NS_CATEGORY ) {
					$cat = Category::newFromRow( $row, $title );
					$this->addSubcategoryObject( $cat, $humanSortkey, $row->page_len );
				} elseif ( $title->getNamespace() == NS_FILE ) {
					$this->addImage( $title, $humanSortkey, $row->page_len, $row->page_is_redirect );
				} else {
					$this->addPage( $title, $humanSortkey, $row->page_len, $row->page_is_redirect );
				}
			}
			// 위에서 여기까지는 mediawiki 1.27의 CategoryViewer.php의 doCategoryQuery()과 동일
		}
	}

	private function selectCategories( IDatabase $dbr, string $type, array $extraConds ): IResultWrapper {
		$queryInfo = $this->linksMigration->getQueryInfo( 'categorylinks' );
		$conds = [
			'lt_namespace' => NS_CATEGORY,
			'lt_title' => $this->categories,
		];
		if ( $this->exCategories ) {
			$excludeCategories = $dbr->selectSQLText(
				$queryInfo['tables'],
				[
					'cl_from',
				],
				[
					'lt_namespace' => NS_CATEGORY,
					'lt_title' => $this->exCategories,
				],
				__METHOD__,
				[
					'GROUP BY' => 'cl_from',
					'ORDER BY' => 'cl_sortkey'
				],
				$queryInfo['joins']
			);
			$conds[] = "cl_from NOT IN ({$excludeCategories})";
		}
		$categorySubQuery = $dbr->buildSelectSubquery(
			$queryInfo['tables'],
			[
				'cl_from',
				'match_count' => 'COUNT(*)',
			],
			$conds,
			__METHOD__,
			[
				# Aggregated query must be with GROUP BY; column 'femiwiki.categorylinks.cl_from' is nonaggregated.
				'GROUP BY' => 'cl_from',
			],
			$queryInfo['joins']
		);
		$rows = $dbr->select(
			[
				'page',
				'matches' => $categorySubQuery,
				'categorylinks',
				'category',
			],
			[
				'page_id',
				'page_title',
				'page_namespace',
				'page_len',
				'page_is_redirect',
				'cat_id',
				'cat_title',
				'cat_subcats',
				'cat_pages',
				'cat_files',
				'cl_sortkey',
				'cl_sortkey_prefix',
			],
			$extraConds,
			__METHOD__,
			[
				'DISTINCT',
				'LIMIT' => $this->limit + 1,
				'ORDER BY' => $this->flip[$type] ? 'cl_sortkey DESC' : 'cl_sortkey',

			],
			[
				'matches' => [ 'INNER JOIN', [
					'page_id = matches.cl_from',
					'match_count' => count( $this->categories ),
				] ],
				'categorylinks' => [ 'JOIN', 'page_id = categorylinks.cl_from' ],
				'category' => [ 'LEFT JOIN', [
					'cat_title = page_title',
					'page_namespace' => NS_CATEGORY,
				] ],
			]
		);
		return $rows;
	}
}
