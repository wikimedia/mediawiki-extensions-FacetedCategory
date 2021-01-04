<?php

namespace MediaWiki\Extension\CategoryIntersectionSearch\Tests\Integration;

use MediaWiki\Extension\FacetedCategory\FacetedCategoryHookHandler;
use MediaWikiUnitTestCase;
use Wikimedia\TestingAccessWrapper;

/**
 * @group FacetedCategory
 */
class FacetedCategoryHookHandlerTest extends MediaWikiUnitTestCase {

	public function provideSplitTerm() {
		return [
			[ null, 'plain text' ],
			[ [ 'fo/o', 'ba/r' ], 'fo/o, ba/r' ],
		];
	}

	/**
	 * @covers \MediaWiki\Extension\FacetedCategory\FacetedCategoryHookHandler::splitTerm
	 * @dataProvider provideSplitTerm
	 *
	 * @param array|null $expected
	 * @param string $term
	 */
	public function testSplitTerm( $expected, $term ) {
		$w = TestingAccessWrapper::newFromClass( FacetedCategoryHookHandler::class );
		$this->assertEquals( $expected, $w->splitTerm( $term ) );
	}

}
