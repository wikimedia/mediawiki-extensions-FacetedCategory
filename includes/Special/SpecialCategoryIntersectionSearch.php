<?php

namespace MediaWiki\Extension\FacetedCategory\Special;

use MediaWiki\SpecialPage\RedirectSpecialPage;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

/**
 * Redirects to Special:FacetedCategories, which now shows the pages in several
 * categories, so that links to this page keep working.
 */
class SpecialCategoryIntersectionSearch extends RedirectSpecialPage {

	public function __construct() {
		parent::__construct( 'CategoryIntersectionSearch' );
		$this->mAllowedRedirectParams = [ 'pagefrom' ];
	}

	/**
	 * @param string|null $subpage
	 * @return Title
	 */
	public function getRedirect( $subpage ) {
		return SpecialPage::getTitleFor( 'FacetedCategories', $subpage ?: false );
	}
}
