<?php

namespace MediaWiki\Extension\FacetedCategory\Tests\Integration;

use MediaWiki\Extension\FacetedCategory\Special\SpecialCategoryIntersectionSearch;
use ReflectionMethod;
use SpecialPageTestBase;

/**
 * @group FacetedCategory
 */
class SpecialCategoryIntersectionSearchTest extends SpecialPageTestBase {
	/**
	 * @inheritDoc
	 */
	protected function newSpecialPage(): SpecialCategoryIntersectionSearch {
		$loadBalancer = $this->getServiceContainer()->getDBLoadBalancer();
		return new SpecialCategoryIntersectionSearch( $loadBalancer );
	}

	/**
	 * @covers \MediaWiki\Extension\FacetedCategory\Special\SpecialCategoryIntersectionSearch::execute
	 */
	public function testEmptySubPage() {
		[ $html, ] = $this->executeSpecialPage( '', null, 'qqx' );

		$this->assertStringContainsString( 'categoryintersectionsearch-noinput', $html );
	}

	/**
	 * @return array
	 */
	public function provideTerms() {
		return [
			[ 'Solid', [ [], [] ] ],
			[ 'Bad, Query/example', [ [], [] ] ],
			[ 'A/B, C/D', [ [ "A/B", "C/D" ], [] ] ],
			[ 'A/B, -C/D', [ [ "A/B" ], [ "C/D" ] ] ],
		];
	}

	/**
	 * @covers \MediaWiki\Extension\FacetedCategory\Special\SpecialCategoryIntersectionSearch::splitCategories
	 * @dataProvider provideTerms
	 *
	 * @param string $term
	 * @param string[] $expected
	 */
	public function testSplitCategories( $term, $expected ) {
		$method = new ReflectionMethod( SpecialCategoryIntersectionSearch::class, 'splitCategories' );
		$method->setAccessible( true );
		$rt = $method->invoke( null, $term );
		$this->assertEquals( $expected, $rt );
	}
}
