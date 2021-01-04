<?php

namespace MediaWiki\Extension\FacetedCategory\Special;

use Html;
use IncludableSpecialPage;
use MediaWiki\Extension\FacetedCategory\FacetedCategoriesPager;

class SpecialFacetedCategories extends IncludableSpecialPage {

	public function __construct() {
		parent::__construct( 'FacetedCategories' );
	}

	/**
	 * @param string $par
	 */
	public function execute( $par ) {
		$this->setHeaders();
		$this->outputHeader();
		$this->getOutput()->allowClickjacking();

		$slash = strpos( $par, '/' );
		$left = $slash === false ? $par : substr( $par, 0, $slash );
		$right = $slash === false ? '' : substr( $par, $slash + 1, strlen( $par ) - 1 );

		$facetName = $this->getRequest()->getText( 'facetName', $left );
		$facetMember = $this->getRequest()->getText( 'facetMember', $right );
		$includeNotExactlyMatched = $this->getRequest()->getBool( 'includeNotExactlyMatched', false );

		$pager = new FacetedCategoriesPager(
			$this->getContext(),
			$facetName,
			$facetMember,
			$includeNotExactlyMatched,
			$this->getLinkRenderer(),
			$this->including()
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
