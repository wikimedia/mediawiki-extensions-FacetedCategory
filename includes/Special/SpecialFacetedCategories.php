<?php

namespace MediaWiki\Extension\FacetedCategory\Special;

use IncludableSpecialPage;
use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\Extension\CategoryTree\CategoryTreeFactory;
use MediaWiki\Extension\FacetedCategory\FacetedCategoriesPager;
use MediaWiki\Html\Html;

class SpecialFacetedCategories extends IncludableSpecialPage {

	private CategoryTreeFactory $categoryTreeFactory;
	private LinkBatchFactory $linkBatchFactory;

	public function __construct(
		CategoryTreeFactory $categoryTreeFactory,
		LinkBatchFactory $linkBatchFactory
	) {
		parent::__construct( 'FacetedCategories' );
		$this->categoryTreeFactory = $categoryTreeFactory;
		$this->linkBatchFactory = $linkBatchFactory;
	}

	/**
	 * @param string|null $subPage
	 */
	public function execute( $subPage ) {
		$this->setHeaders();
		$this->outputHeader();
		$this->getOutput()->setPreventClickjacking( false );

		if ( $subPage === null ) {
			return;
		}
		$slash = strpos( $subPage, '/' );
		$left = $slash === false ? $subPage : substr( $subPage, 0, $slash );
		$right = $slash === false ? '' : substr( $subPage, $slash + 1, strlen( $subPage ) - 1 );

		$facetName = $this->getRequest()->getText( 'facetName', $left );
		$facetMember = $this->getRequest()->getText( 'facetMember', $right );
		$includeNotExactlyMatched = $this->getRequest()->getBool( 'includeNotExactlyMatched', false );

		$pager = new FacetedCategoriesPager(
			$this->getContext(),
			$facetName,
			$facetMember,
			$includeNotExactlyMatched,
			(bool)$this->including(),
			$this->categoryTreeFactory,
			$this->linkBatchFactory
		);
		$pager->doQuery();

		$html = ( $this->including() ? '' : $this->msg( 'categoriespagetext', $pager->getNumRows() )->parseAsBlock() );
		$html .= $pager->getStartForm( $facetName, $facetMember, $includeNotExactlyMatched );
		$html .= ( $this->including() ? '' : $pager->getNavigationBar() );
		$html .= Html::rawElement( 'ul', [], $pager->getBody() );
		$html .= ( $this->including() ? '' : $pager->getNavigationBar() );

		$html = Html::rawElement( 'div', [ 'class' => 'mw-spcontent' ], $html );
		$this->getOutput()->addHTML( $html );
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'pages';
	}
}
