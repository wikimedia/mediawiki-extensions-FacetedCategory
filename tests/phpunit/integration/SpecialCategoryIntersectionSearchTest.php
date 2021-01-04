<?php

namespace MediaWiki\Extension\CategoryIntersectionSearch\Tests\Units;

use MediaWiki\Extension\FacetedCategory\Special\SpecialCategoryIntersectionSearch;
use SpecialPageTestBase;

/**
 * @group FacetedCategory
 */
class SpecialCategoryIntersectionSearchTest extends SpecialPageTestBase {

	/**
	 * @inheritDoc
	 */
	protected function newSpecialPage() {
		return new SpecialCategoryIntersectionSearch();
	}

	/**
	 * @covers \MediaWiki\Extension\FacetedCategory\Special\SpecialCategoryIntersectionSearch::execute
	 */
	public function testEmptySubPage() {
		list( $html, ) = $this->executeSpecialPage( '', null, 'qqx' );

		$this->assertStringContainsString( 'categoryintersectionsearch-noinput', $html );
	}

}
