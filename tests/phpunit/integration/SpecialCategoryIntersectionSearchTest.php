<?php

namespace MediaWiki\Extension\FacetedCategory\Tests\Integration;

use MediaWiki\Extension\FacetedCategory\Special\SpecialCategoryIntersectionSearch;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWikiIntegrationTestCase;

/**
 * @group FacetedCategory
 * @covers \MediaWiki\Extension\FacetedCategory\Special\SpecialCategoryIntersectionSearch
 */
class SpecialCategoryIntersectionSearchTest extends MediaWikiIntegrationTestCase {

	public function testRedirect() {
		$page = new SpecialCategoryIntersectionSearch();
		$this->assertTrue(
			SpecialPage::getTitleFor( 'FacetedCategories', 'Facet/A, -Facet/B' )
				->equals( $page->getRedirect( 'Facet/A, -Facet/B' ) )
		);
		$this->assertTrue( SpecialPage::getTitleFor( 'FacetedCategories' )->equals( $page->getRedirect( null ) ) );
	}
}
