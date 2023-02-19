<?php

namespace MediaWiki\Extension\FacetedCategory\Tests\Integration\Hooks;

use MediaWiki\Extension\FacetedCategory\Hooks\Main;
use MediaWikiUnitTestCase;
use Wikimedia\TestingAccessWrapper;

/**
 * @group FacetedCategory
 */
class MainTest extends MediaWikiUnitTestCase {

	public function provideSplitTerm() {
		return [
			[ null, 'plain text' ],
			[ [ 'fo/o', 'ba/r' ], 'fo/o, ba/r' ],
		];
	}

	/**
	 * @covers \MediaWiki\Extension\FacetedCategory\Hooks\Main::splitTerm
	 * @dataProvider provideSplitTerm
	 *
	 * @param array|null $expected
	 * @param string $term
	 */
	public function testSplitTerm( $expected, $term ) {
		$w = TestingAccessWrapper::newFromClass( Main::class );
		$this->assertEquals( $expected, $w->splitTerm( $term ) );
	}

}
