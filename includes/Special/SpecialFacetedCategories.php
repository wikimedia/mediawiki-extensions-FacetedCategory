<?php

namespace MediaWiki\Extension\FacetedCategory\Special;

use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\Category\CategoryViewer;
use MediaWiki\Collation\CollationFactory;
use MediaWiki\Extension\FacetedCategory\CategoryCombinations;
use MediaWiki\Extension\FacetedCategory\CategoryIntersection;
use MediaWiki\Extension\FacetedCategory\FacetedCategoryList;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\IncludableSpecialPage;
use MediaWiki\Title\Title;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Lists the facets of the faceted categories, the categories named "Facet/Member",
 * and the members of a facet, and finds the pages in several categories at once.
 *
 * The subpage is a facet, or a comma-separated list of categories the pages are
 * in, where the ones starting with a minus are categories the pages are not in.
 */
class SpecialFacetedCategories extends IncludableSpecialPage {

	/** Facets shown as cards, after which the others are only linked */
	private const CARDS = 24;
	/** Members shown on the card of a facet */
	private const CARD_MEMBERS = 5;
	/** Pages of an intersection shown at once */
	private const PAGES = 200;
	/** Most pages of an intersection counted */
	private const MAX_COUNT = 1000;
	/** Most pages of an intersection whose categories are suggested to narrow it down */
	private const REFINE_PAGES = 500;
	/** Facets suggested to narrow down an intersection */
	private const REFINE_FACETS = 6;
	/** Members of each facet suggested to narrow down an intersection */
	private const REFINE_MEMBERS = 5;

	private FacetedCategoryList $facetedCategoryList;
	private CategoryCombinations $categoryCombinations;
	private IConnectionProvider $connectionProvider;
	private LinkBatchFactory $linkBatchFactory;
	private CollationFactory $collationFactory;
	private WANObjectCache $cache;

	public function __construct(
		FacetedCategoryList $facetedCategoryList,
		CategoryCombinations $categoryCombinations,
		IConnectionProvider $connectionProvider,
		LinkBatchFactory $linkBatchFactory,
		CollationFactory $collationFactory,
		WANObjectCache $cache
	) {
		parent::__construct( 'FacetedCategories' );
		$this->facetedCategoryList = $facetedCategoryList;
		$this->categoryCombinations = $categoryCombinations;
		$this->connectionProvider = $connectionProvider;
		$this->linkBatchFactory = $linkBatchFactory;
		$this->collationFactory = $collationFactory;
		$this->cache = $cache;
	}

	/**
	 * @param string|null $subPage
	 */
	public function execute( $subPage ) {
		$this->setHeaders();
		$output = $this->getOutput();
		$output->addModuleStyles( 'ext.facetedCategory.special' );
		if ( !$this->including() ) {
			$output->addModules( 'ext.facetedCategory.special.js' );
		}
		$request = $this->getRequest();

		// The form of the old version of this page asked for a facet and a member
		$facetName = trim( $request->getText( 'facetName' ) );
		$facetMember = trim( $request->getText( 'facetMember' ) );
		if ( ( $subPage === null || $subPage === '' ) && $facetName !== '' ) {
			$subPage = $facetMember === '' ? $facetName : "$facetName/$facetMember";
		}

		// The forms of this page add categories to the ones of the subpage
		$add = $request->getArray( 'add' );
		if ( $add !== null && !$this->including() ) {
			$this->addConditions( $request->getText( 'conditions' ), $add );
			return;
		}

		$subPage = trim( str_replace( '_', ' ', $subPage ?? '' ) );
		if ( $subPage === '' ) {
			$this->showFacets();
			return;
		}
		if ( !str_contains( $subPage, ',' ) && !str_starts_with( $subPage, '-' ) && !str_contains( $subPage, '/' ) ) {
			$this->showMembers( $subPage );
			return;
		}

		[ $include, $exclude ] = self::parseConditions( $subPage );
		if ( count( $include ) === 1 && !$exclude ) {
			$output->redirect( $include[0]->getFullURL() );
		} elseif ( !$include ) {
			$output->addHTML( Html::errorBox( $this->msg( 'facetedcategory-needs-category' )->parse() ) );
			$this->showFacets();
		} else {
			$this->showIntersection( $include, $exclude );
		}
	}

	/**
	 * Parse a comma-separated list of categories, where the ones starting with a
	 * minus are excluded.
	 *
	 * @param string $text
	 * @return array{0:Title[],1:Title[]} The categories to include and to exclude
	 */
	public static function parseConditions( string $text ): array {
		$include = [];
		$exclude = [];
		foreach ( explode( ',', $text ) as $token ) {
			$token = trim( $token, " _\t" );
			$excluded = str_starts_with( $token, '-' );
			if ( $excluded ) {
				$token = ltrim( substr( $token, 1 ), ' _' );
			}
			$title = $token === '' ? null : Title::newFromText( $token, NS_CATEGORY );
			if ( !$title || $title->getNamespace() !== NS_CATEGORY ) {
				continue;
			}
			if ( $excluded ) {
				$exclude[$title->getDBkey()] = $title;
			} else {
				$include[$title->getDBkey()] = $title;
			}
		}
		$exclude = array_diff_key( $exclude, $include );
		return [ array_values( $include ), array_values( $exclude ) ];
	}

	/**
	 * @param Title[] $include
	 * @param Title[] $exclude
	 * @return Title The page of the intersection of the categories
	 */
	private function getConditionsTitle( array $include, array $exclude ): Title {
		$items = array_merge(
			array_map( static fn ( Title $title ) => $title->getText(), $include ),
			array_map( static fn ( Title $title ) => '-' . $title->getText(), $exclude )
		);
		return $this->getPageTitle( implode( ',', $items ) );
	}

	/**
	 * Redirect to the intersection of the categories of the subpage and some more.
	 *
	 * @param string $conditions Categories of the subpage
	 * @param string[] $add Categories to add, each possibly a comma-separated list
	 */
	private function addConditions( string $conditions, array $add ): void {
		[ $include, $exclude ] = self::parseConditions( implode( ',', array_merge( [ $conditions ], $add ) ) );
		$output = $this->getOutput();
		if ( count( $include ) === 1 && !$exclude ) {
			$output->redirect( $include[0]->getFullURL() );
		} elseif ( !$include ) {
			$output->redirect( $this->getPageTitle()->getFullURL() );
		} else {
			$output->redirect( $this->getConditionsTitle( $include, $exclude )->getFullURL() );
		}
	}

	/**
	 * Show a card for each facet with its members with the most pages.
	 */
	private function showFacets(): void {
		$output = $this->getOutput();
		$summaries = $this->facetedCategoryList->getFacetSummaries( PHP_INT_MAX, self::CARD_MEMBERS );
		$this->addLinkBatch( $summaries );

		if ( $this->including() ) {
			$items = '';
			foreach ( $summaries as $summary ) {
				$items .= Html::rawElement( 'li', [], $this->makeFacetLink( $summary['facet'] ) );
			}
			$output->addHTML( Html::rawElement( 'ul', [], $items ) );
			return;
		}

		$output->addHTML( Html::element(
			'p',
			[ 'class' => 'ext-facetedcategory-lead' ],
			$this->msg( 'facetedcategory-intro' )->text()
		) );
		$output->addHTML( $this->makeConditionBox( [], [] ) );
		$output->addHTML( Html::element(
			'p',
			[ 'class' => 'ext-facetedcategory-hint' ],
			$this->msg( 'facetedcategory-find-hint' )->text()
		) );

		$cards = '';
		foreach ( array_slice( $summaries, 0, self::CARDS ) as $summary ) {
			$members = '';
			foreach ( $summary['members'] as $member ) {
				$members .= Html::rawElement(
					'li',
					[],
					$this->makeCategoryLink( $summary['facet'], $member['member'] ) . ' ' .
						$this->makeCount( $member['pages'] )
				);
			}
			$cards .= Html::rawElement(
				'section',
				[ 'class' => 'ext-facetedcategory-card' ],
				Html::rawElement(
					'h2',
					[],
					$this->makeFacetLink( $summary['facet'] ) . ' ' . $this->makeCount( $summary['pages'] )
				) .
				Html::rawElement( 'ul', [], $members ) .
				Html::rawElement(
					'p',
					[ 'class' => 'ext-facetedcategory-card-more' ],
					$this->getLinkRenderer()->makeKnownLink(
						$this->getPageTitle( $summary['facet'] ),
						$this->msg( 'facetedcategory-all-members' )->numParams( $summary['count'] )->text()
					)
				)
			);
		}
		$output->addHTML( Html::rawElement( 'div', [ 'class' => 'ext-facetedcategory-cards' ], $cards ) );

		$others = array_slice( $summaries, self::CARDS );
		if ( $others ) {
			$items = '';
			foreach ( $others as $summary ) {
				$items .= Html::rawElement(
					'li',
					[],
					$this->makeFacetLink( $summary['facet'] ) . ' ' . $this->makeCount( $summary['pages'] )
				);
			}
			$output->addHTML(
				Html::element( 'h2', [], $this->msg( 'facetedcategory-other-facets' )->text() ) .
				Html::rawElement( 'ul', [ 'class' => 'ext-facetedcategory-other-facets' ], $items )
			);
		}
	}

	/**
	 * Show a table of the members of a facet.
	 *
	 * @param string $facet
	 */
	private function showMembers( string $facet ): void {
		$output = $this->getOutput();
		$request = $this->getRequest();
		$members = $this->facetedCategoryList->getMembers( str_replace( ' ', '_', $facet ) );
		$this->addLinkBatch( [ [ 'facet' => $facet, 'members' => $members ] ] );

		if ( $this->including() ) {
			$items = '';
			foreach ( $members as $member ) {
				$items .= Html::rawElement( 'li', [], $this->makeCategoryLink( $facet, $member['member'] ) );
			}
			$output->addHTML( Html::rawElement( 'ul', [], $items ) );
			return;
		}

		$output->setPageTitleMsg( $this->msg( 'rawmessage' )->plaintextParams( $facet ) );
		$output->addBacklinkSubtitle( $this->getPageTitle() );
		$output->addHTML( Html::element(
			'p',
			[ 'class' => 'ext-facetedcategory-lead' ],
			$this->msg( 'facetedcategory-facet-summary' )
				->numParams( count( $members ), array_sum( array_column( $members, 'pages' ) ) )
				->text()
		) );

		$filter = trim( $request->getText( 'filter' ) );
		$sort = $request->getVal( 'sort' ) === 'name' ? 'name' : 'pages';
		$output->addHTML( $this->makeToolbar( $facet, $filter, $sort ) );

		if ( $filter !== '' ) {
			$members = array_values( array_filter(
				$members,
				static fn ( $member ) => mb_stripos( str_replace( '_', ' ', $member['member'] ), $filter ) !== false
			) );
		}
		if ( $sort === 'name' ) {
			$collation = $this->collationFactory->getCategoryCollation();
			usort( $members, static fn ( $a, $b ) => strcmp(
				$collation->getSortKey( $a['member'] ),
				$collation->getSortKey( $b['member'] )
			) );
		}

		if ( !$members ) {
			$output->addHTML( Html::element(
				'p',
				[ 'class' => 'ext-facetedcategory-empty' ],
				$this->msg( 'facetedcategory-no-members' )->text()
			) );
			return;
		}

		$rows = Html::rawElement(
			'tr',
			[],
			Html::element( 'th', [ 'class' => 'ext-facetedcategory-check' ] ) .
			Html::element( 'th', [], $this->msg( 'facetedcategory-member' )->text() ) .
			Html::element(
				'th',
				[ 'class' => 'ext-facetedcategory-number' ],
				$this->msg( 'facetedcategory-pages' )->text()
			)
		);
		foreach ( $members as $i => $member ) {
			$id = "ext-facetedcategory-member-$i";
			$rows .= Html::rawElement(
				'tr',
				[],
				Html::rawElement(
					'td',
					[ 'class' => 'ext-facetedcategory-check' ],
					Html::check( 'add[]', false, [
						'id' => $id,
						'value' => $facet . '/' . str_replace( '_', ' ', $member['member'] ),
						'aria-label' => str_replace( '_', ' ', $member['member'] ),
					] )
				) .
				Html::rawElement( 'td', [], $this->makeCategoryLink( $facet, $member['member'] ) ) .
				Html::element(
					'td',
					[ 'class' => 'ext-facetedcategory-number' ],
					$this->getLanguage()->formatNum( $member['pages'] )
				)
			);
		}
		$output->addHTML( Html::rawElement(
			'form',
			[ 'method' => 'get', 'action' => wfScript() ],
			Html::hidden( 'title', $this->getPageTitle()->getPrefixedText() ) .
			Html::rawElement( 'table', [ 'class' => 'ext-facetedcategory-members' ], $rows ) .
			Html::rawElement(
				'div',
				[ 'class' => 'ext-facetedcategory-members-submit' ],
				Html::element(
					'button',
					[
						'type' => 'submit',
						'class' => 'cdx-button cdx-button--action-progressive cdx-button--weight-primary',
					],
					$this->msg( 'facetedcategory-show-intersection' )->text()
				)
			)
		) );
	}

	/**
	 * Show the pages in all the categories to include and in none to exclude.
	 *
	 * @param Title[] $include
	 * @param Title[] $exclude
	 */
	private function showIntersection( array $include, array $exclude ): void {
		$output = $this->getOutput();
		$request = $this->getRequest();
		$keys = array_map( static fn ( Title $title ) => $title->getDBkey(), $include );
		$name = $exclude ? null : $this->categoryCombinations->findName( $keys );
		$output->setPageTitleMsg( $name !== null
			? $this->msg( 'rawmessage' )->plaintextParams( $name )
			: $this->msg( 'rawmessage' )->plaintextParams( $this->describeConditions( $include, $exclude ) )
		);
		$intersection = new CategoryIntersection(
			$this->connectionProvider,
			$keys,
			array_map( static fn ( Title $title ) => $title->getDBkey(), $exclude )
		);
		if ( $this->including() ) {
			$output->addHTML( $this->makeResults( $intersection, $include, $exclude, null ) );
			return;
		}
		$output->addBacklinkSubtitle( $this->getPageTitle() );
		$count = $intersection->count( self::MAX_COUNT );
		$lead = $count > self::MAX_COUNT
			? $this->msg( 'facetedcategory-result-count-more' )->numParams( self::MAX_COUNT )->text()
			: $this->msg( 'facetedcategory-result-count' )->numParams( $count )->text();
		if ( $name !== null ) {
			$lead = $this->msg( 'facetedcategory-named-combination' )->text() .
				$this->msg( 'comma-separator' )->text() . $lead;
		}
		$output->addHTML( Html::element( 'p', [ 'class' => 'ext-facetedcategory-lead' ], $lead ) );
		$output->addHTML( $this->makeConditionBox( $include, $exclude ) );

		if ( $count === 0 ) {
			$output->addHTML( Html::element(
				'p',
				[ 'class' => 'ext-facetedcategory-empty' ],
				$this->msg( 'facetedcategory-no-results' )->text()
			) );
			return;
		}
		$results = $this->makeResults( $intersection, $include, $exclude, $request->getVal( 'pagefrom' ) );
		$refine = $count <= self::REFINE_PAGES
			? $this->makeRefinements( $intersection, $include, $exclude, $count )
			: '';
		$output->addHTML( $refine === ''
			? $results
			: Html::rawElement( 'div', [ 'class' => 'ext-facetedcategory-refined' ], $refine . $results )
		);
	}

	/**
	 * @param CategoryIntersection $intersection
	 * @param Title[] $include
	 * @param Title[] $exclude
	 * @param int $count Number of the pages
	 * @return string HTML of links that add the faceted categories of the pages
	 *  to the categories, or an empty string when there are none
	 */
	private function makeRefinements(
		CategoryIntersection $intersection, array $include, array $exclude, int $count
	): string {
		$keys = array_map(
			static fn ( Title $title ) => $title->getDBkey(),
			array_merge( $include, $exclude )
		);
		$conditions = $this->getConditionsTitle( $include, $exclude )->getDBkey();
		$counts = $this->cache->getWithSetCallback(
			$this->cache->makeKey( 'facetedcategory-refine', sha1( $conditions ) ),
			WANObjectCache::TTL_MINUTE * 10,
			static fn () => $intersection->getFacetedCategoryCounts( self::REFINE_PAGES ),
			[ 'version' => 1 ]
		);
		if ( !$counts ) {
			return '';
		}
		$facets = [];
		foreach ( $counts as $category => $pages ) {
			$category = (string)$category;
			// A category all the pages are in does not narrow them down
			if ( in_array( $category, $keys, true ) || $pages >= $count ) {
				continue;
			}
			[ $facet, $member ] = explode( '/', $category, 2 );
			$facets[$facet][$member] = $pages;
		}
		foreach ( $facets as &$members ) {
			uksort( $members, static fn ( $a, $b ) =>
				$members[$b] <=> $members[$a] ?: strcmp( (string)$a, (string)$b ) );
		}
		unset( $members );
		uksort( $facets, static fn ( $a, $b ) =>
			max( $facets[$b] ) <=> max( $facets[$a] ) ?: strcmp( (string)$a, (string)$b ) );

		$groups = '';
		foreach ( array_slice( $facets, 0, self::REFINE_FACETS, true ) as $facet => $members ) {
			$items = '';
			foreach ( array_slice( $members, 0, self::REFINE_MEMBERS, true ) as $member => $pages ) {
				$title = Title::makeTitle( NS_CATEGORY, "$facet/$member" );
				$text = strtr( (string)$member, '_', ' ' );
				$items .= Html::rawElement(
					'li',
					[],
					Html::element(
						'a',
						[
							'href' => $this->getConditionsTitle( [ ...$include, $title ], $exclude )->getLocalURL(),
							'title' => $this->msg( 'facetedcategory-refine-add', $title->getText() )->text(),
						],
						$text
					) .
					$this->makeCount( $pages )
				);
			}
			$groups .= Html::element( 'h3', [], strtr( (string)$facet, '_', ' ' ) ) .
				Html::rawElement( 'ul', [], $items );
		}
		if ( $groups === '' ) {
			return '';
		}
		return Html::rawElement(
			'nav',
			[ 'class' => 'ext-facetedcategory-refine', 'aria-labelledby' => 'ext-facetedcategory-refine-heading' ],
			Html::element(
				'h2',
				[ 'id' => 'ext-facetedcategory-refine-heading' ],
				$this->msg( 'facetedcategory-refine' )->text()
			) .
			$groups .
			Html::element(
				'p',
				[ 'class' => 'ext-facetedcategory-refine-hint' ],
				$this->msg( 'facetedcategory-refine-hint' )->text()
			)
		);
	}

	/**
	 * @param CategoryIntersection $intersection
	 * @param Title[] $include
	 * @param Title[] $exclude
	 * @param string|null $pageFrom Human-readable sort key of the first page to show
	 * @return string HTML of the pages, grouped by their first letter, with a
	 *  link to the next ones
	 */
	private function makeResults(
		CategoryIntersection $intersection, array $include, array $exclude, ?string $pageFrom
	): string {
		$collation = $this->collationFactory->getCategoryCollation();
		$rows = $intersection->getPages(
			$pageFrom === null ? null : $collation->getSortKey( $pageFrom ),
			self::PAGES + 1
		);
		$next = null;
		if ( count( $rows ) > self::PAGES ) {
			$last = array_pop( $rows );
			$next = Title::makeTitle( $last->page_namespace, $last->page_title )
				->getCategorySortkey( $last->cl_sortkey_prefix );
		}

		$links = [];
		$letters = [];
		foreach ( $rows as $row ) {
			$title = Title::makeTitle( $row->page_namespace, $row->page_title );
			$links[] = $this->getLinkRenderer()->makeKnownLink( $title );
			$letters[] = $collation->getFirstLetter( $title->getCategorySortkey( $row->cl_sortkey_prefix ) );
		}
		$html = count( $links ) > 6
			? CategoryViewer::columnList( $links, $letters )
			: CategoryViewer::shortList( $links, $letters );

		$self = $this->getConditionsTitle( $include, $exclude );
		$nav = [];
		if ( $pageFrom !== null ) {
			$nav[] = $this->getLinkRenderer()->makeKnownLink( $self, $this->msg( 'page_first' )->text() );
		}
		if ( $next !== null ) {
			$nav[] = $this->getLinkRenderer()->makeKnownLink(
				$self,
				$this->msg( 'nextn' )->numParams( self::PAGES )->text(),
				[],
				[ 'pagefrom' => $next ]
			);
		}
		if ( $nav && !$this->including() ) {
			$html .= Html::rawElement(
				'p',
				[ 'class' => 'ext-facetedcategory-paging' ],
				$this->getLanguage()->pipeList( $nav )
			);
		}
		return Html::rawElement( 'div', [ 'class' => 'ext-facetedcategory-results mw-category-generated' ], $html );
	}

	/**
	 * @param Title[] $include
	 * @param Title[] $exclude
	 * @return string The categories, as in "Facet: Member, not Facet: Member"
	 */
	private function describeConditions( array $include, array $exclude ): string {
		$describe = function ( Title $title ): string {
			$text = $title->getText();
			$slash = strpos( $text, '/' );
			return $slash === false || $slash === 0 || $slash === strlen( $text ) - 1
				? $text
				: $this->msg( 'facetedcategory-condition' )
					->plaintextParams( substr( $text, 0, $slash ), substr( $text, $slash + 1 ) )
					->text();
		};
		$items = array_map( $describe, $include );
		foreach ( $exclude as $title ) {
			$items[] = $this->msg( 'facetedcategory-condition-excluded' )
				->plaintextParams( $describe( $title ) )
				->text();
		}
		return $this->getLanguage()->commaList( $items );
	}

	/**
	 * @param Title[] $include
	 * @param Title[] $exclude
	 * @return string HTML of the categories, each with links to exclude or include
	 *  it and to remove it, and a form to add more
	 */
	private function makeConditionBox( array $include, array $exclude ): string {
		$chips = '';
		$all = array_merge(
			array_map( static fn ( $title ) => [ $title, false ], $include ),
			array_map( static fn ( $title ) => [ $title, true ], $exclude )
		);
		foreach ( $all as [ $title, $excluded ] ) {
			$text = $title->getText();
			$slash = strpos( $text, '/' );
			$label = $slash === false
				? Html::element( 'b', [], $text )
				: Html::element(
					'span',
					[ 'class' => 'ext-facetedcategory-chip-facet' ],
					substr( $text, 0, $slash + 1 )
				) .
					Html::element( 'b', [], substr( $text, $slash + 1 ) );
			if ( $excluded ) {
				$label = Html::element(
					'span',
					[ 'class' => 'ext-facetedcategory-chip-facet' ],
					$this->msg( 'facetedcategory-excluded' )->text()
				) . ' ' . $label;
			}
			$toggled = self::toggle( $include, $exclude, $title );
			$removed = self::remove( $include, $exclude, $title );
			$chips .= Html::rawElement(
				'span',
				[ 'class' => [ 'ext-facetedcategory-chip', 'ext-facetedcategory-chip-excluded' => $excluded ] ],
				Html::rawElement(
					'a',
					[
						'href' => $toggled[0] ? $this->getConditionsTitle( ...$toggled )->getLocalURL() : false,
						'title' => $this->msg(
							$excluded ? 'facetedcategory-include' : 'facetedcategory-exclude', $text
						)->text(),
					],
					$label
				) .
				Html::element(
					'a',
					[
						'href' => $removed[0]
							? $this->getConditionsTitle( ...$removed )->getLocalURL()
							: $this->getPageTitle()->getLocalURL(),
						'class' => 'ext-facetedcategory-chip-remove',
						'title' => $this->msg( 'facetedcategory-remove', $text )->text(),
						'aria-label' => $this->msg( 'facetedcategory-remove', $text )->text(),
					],
					'×'
				)
			);
		}

		return Html::rawElement(
			'form',
			[ 'method' => 'get', 'action' => wfScript(), 'class' => 'ext-facetedcategory-conditions' ],
			Html::hidden( 'title', $this->getPageTitle()->getPrefixedText() ) .
			Html::hidden( 'conditions', implode( ',', array_merge(
				array_map( static fn ( Title $title ) => $title->getText(), $include ),
				array_map( static fn ( Title $title ) => '-' . $title->getText(), $exclude )
			) ) ) .
			$chips .
			Html::input( 'add', '', 'text', [
				'class' => 'ext-facetedcategory-conditions-input',
				'placeholder' => $this->msg( $all ? 'facetedcategory-add' : 'facetedcategory-find' )->text(),
				'aria-label' => $this->msg( $all ? 'facetedcategory-add' : 'facetedcategory-find' )->text(),
			] ) .
			Html::element(
				'button',
				[ 'type' => 'submit', 'class' => 'cdx-button ext-facetedcategory-conditions-submit' ],
				$this->msg( 'facetedcategory-add-submit' )->text()
			)
		);
	}

	/**
	 * @param Title[] $include
	 * @param Title[] $exclude
	 * @param Title $title One of them
	 * @return array{0:Title[],1:Title[]} The categories with the given one moved
	 *  from the ones to include to the ones to exclude, or back. Nothing is
	 *  included when it was the only one.
	 */
	private static function toggle( array $include, array $exclude, Title $title ): array {
		$wasExcluded = in_array( $title, $exclude, true );
		[ $include, $exclude ] = self::remove( $include, $exclude, $title );
		if ( $wasExcluded ) {
			$include[] = $title;
		} else {
			$exclude[] = $title;
		}
		return [ $include, $exclude ];
	}

	/**
	 * @param Title[] $include
	 * @param Title[] $exclude
	 * @param Title $title One of them
	 * @return array{0:Title[],1:Title[]} The categories without the given one
	 */
	private static function remove( array $include, array $exclude, Title $title ): array {
		$other = static fn ( Title $t ) => $t !== $title;
		return [ array_values( array_filter( $include, $other ) ), array_values( array_filter( $exclude, $other ) ) ];
	}

	/**
	 * @param string $facet
	 * @param string $filter
	 * @param string $sort 'pages' or 'name'
	 * @return string HTML of a form to filter the members and links to sort them
	 */
	private function makeToolbar( string $facet, string $filter, string $sort ): string {
		$sorts = '';
		foreach ( [ 'pages', 'name' ] as $value ) {
			$query = array_filter( [ 'filter' => $filter, 'sort' => $value === 'name' ? 'name' : null ] );
			$sorts .= Html::element(
				'a',
				[
					'href' => $this->getPageTitle( $facet )->getLocalURL( $query ),
					'class' => [
						'cdx-button', 'cdx-button--fake-button', 'cdx-button--fake-button--enabled',
						'cdx-button--action-progressive' => $sort === $value,
					],
					'aria-current' => $sort === $value ? 'true' : false,
				],
				// Messages: facetedcategory-sort-pages, facetedcategory-sort-name
				$this->msg( "facetedcategory-sort-$value" )->text()
			);
		}
		return Html::rawElement(
			'div',
			[ 'class' => 'ext-facetedcategory-toolbar' ],
			Html::rawElement(
				'form',
				[ 'method' => 'get', 'action' => wfScript() ],
				Html::hidden( 'title', $this->getPageTitle( $facet )->getPrefixedText() ) .
				( $sort === 'name' ? Html::hidden( 'sort', 'name' ) : '' ) .
				Html::rawElement(
					'div',
					[ 'class' => 'cdx-text-input' ],
					Html::input( 'filter', $filter, 'search', [
						'class' => 'cdx-text-input__input',
						'placeholder' => $this->msg( 'facetedcategory-filter' )->text(),
						'aria-label' => $this->msg( 'facetedcategory-filter' )->text(),
					] )
				) .
				Html::element(
					'button',
					[ 'type' => 'submit', 'class' => 'cdx-button' ],
					$this->msg( 'facetedcategory-filter-submit' )->text()
				)
			) .
			Html::rawElement( 'div', [ 'class' => 'ext-facetedcategory-sort' ], $sorts )
		);
	}

	/**
	 * @param array $summaries Facets with their members
	 */
	private function addLinkBatch( array $summaries ): void {
		$batch = $this->linkBatchFactory->newLinkBatch();
		foreach ( $summaries as $summary ) {
			foreach ( $summary['members'] as $member ) {
				$batch->add( NS_CATEGORY, str_replace( ' ', '_', $summary['facet'] ) . '/' . $member['member'] );
			}
		}
		$batch->execute();
	}

	private function makeFacetLink( string $facet ): string {
		return $this->getLinkRenderer()->makeKnownLink(
			$this->getPageTitle( str_replace( '_', ' ', $facet ) ),
			str_replace( '_', ' ', $facet )
		);
	}

	private function makeCategoryLink( string $facet, string $member ): string {
		return $this->getLinkRenderer()->makeLink(
			Title::makeTitle( NS_CATEGORY, str_replace( ' ', '_', $facet ) . '/' . $member ),
			str_replace( '_', ' ', $member )
		);
	}

	private function makeCount( int $pages ): string {
		return Html::element(
			'span',
			[ 'class' => 'ext-facetedcategory-count' ],
			$this->getLanguage()->formatNum( $pages )
		);
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'pages';
	}
}
