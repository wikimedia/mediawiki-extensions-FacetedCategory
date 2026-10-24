<?php

namespace MediaWiki\Extension\FacetedCategory\Special;

use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\Collation\CollationFactory;
use MediaWiki\Extension\FacetedCategory\FacetedCategoryList;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\IncludableSpecialPage;
use MediaWiki\Title\Title;

/**
 * Lists the facets of the faceted categories, the categories named "Facet/Member",
 * and the members of a facet.
 */
class SpecialFacetedCategories extends IncludableSpecialPage {

	/** Facets shown as cards, after which the others are only linked */
	private const CARDS = 24;
	/** Members shown on the card of a facet */
	private const CARD_MEMBERS = 5;

	private FacetedCategoryList $facetedCategoryList;
	private LinkBatchFactory $linkBatchFactory;
	private CollationFactory $collationFactory;

	public function __construct(
		FacetedCategoryList $facetedCategoryList,
		LinkBatchFactory $linkBatchFactory,
		CollationFactory $collationFactory
	) {
		parent::__construct( 'FacetedCategories' );
		$this->facetedCategoryList = $facetedCategoryList;
		$this->linkBatchFactory = $linkBatchFactory;
		$this->collationFactory = $collationFactory;
	}

	/**
	 * @param string|null $subPage
	 */
	public function execute( $subPage ) {
		$this->setHeaders();
		$output = $this->getOutput();
		$output->addModuleStyles( 'ext.facetedCategory.special' );
		$request = $this->getRequest();

		// The form of the old version of this page asked for a facet and a member
		$facetName = trim( $request->getText( 'facetName' ) );
		$facetMember = trim( $request->getText( 'facetMember' ) );
		if ( ( $subPage === null || $subPage === '' ) && $facetName !== '' ) {
			$subPage = $facetMember === '' ? $facetName : "$facetName/$facetMember";
		}

		$subPage = trim( str_replace( '_', ' ', $subPage ?? '' ) );
		if ( $subPage === '' ) {
			$this->showFacets();
		} elseif ( str_contains( $subPage, '/' ) ) {
			$title = Title::makeTitleSafe( NS_CATEGORY, $subPage );
			if ( $title ) {
				$output->redirect( $title->getFullURL() );
			} else {
				$this->showFacets();
			}
		} else {
			$this->showMembers( $subPage );
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
			Html::element( 'th', [], $this->msg( 'facetedcategory-member' )->text() ) .
			Html::element(
				'th',
				[ 'class' => 'ext-facetedcategory-number' ],
				$this->msg( 'facetedcategory-pages' )->text()
			)
		);
		foreach ( $members as $member ) {
			$rows .= Html::rawElement(
				'tr',
				[],
				Html::rawElement( 'td', [], $this->makeCategoryLink( $facet, $member['member'] ) ) .
				Html::element(
					'td',
					[ 'class' => 'ext-facetedcategory-number' ],
					$this->getLanguage()->formatNum( $member['pages'] )
				)
			);
		}
		$output->addHTML( Html::rawElement( 'table', [ 'class' => 'ext-facetedcategory-members' ], $rows ) );
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
