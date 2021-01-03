<?php

use Wikimedia\TestingAccessWrapper;

/**
 * @group FacetedCategory
 */
class CategoryIntersectionSearchHooksTest extends MediaWikiUnitTestCase {

	public function provideSplitTerm() {
		return [
			[ null, 'plain text' ],
			[ [ 'fo/o', 'ba/r' ], 'fo/o, ba/r' ],
		];
	}

	/**
	 * @covers \CategoryIntersectionSearchHooks::splitTerm
	 * @dataProvider provideSplitTerm
	 *
	 * @param array|null $expected
	 * @param string $term
	 */
	public function testSplitTerm( $expected, $term ) {
		$w = TestingAccessWrapper::newFromClass( CategoryIntersectionSearchHooks::class );
		$this->assertEquals( $expected, $w->splitTerm( $term ) );
	}

}
