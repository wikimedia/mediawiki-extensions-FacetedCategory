<?php

namespace MediaWiki\Extension\FacetedCategory\Tests\Integration;

use MediaWiki\Extension\FacetedCategory\Special\SpecialCategoryIntersectionSearch;
use MediaWiki\Title\Title;
use ReflectionMethod;
use SpecialPageTestBase;

/**
 * @group FacetedCategory
 * @group Database
 */
class SpecialCategoryIntersectionSearchTest extends SpecialPageTestBase {
	/**
	 * @inheritDoc
	 */
	protected function newSpecialPage(): SpecialCategoryIntersectionSearch {
		$services = $this->getServiceContainer();
		return new SpecialCategoryIntersectionSearch(
			$services->getDBLoadBalancer(),
			$services->getLinksMigration()
		);
	}

	/**
	 * @covers \MediaWiki\Extension\FacetedCategory\Special\SpecialCategoryIntersectionSearch::execute
	 */
	public function testEmptySubPage() {
		[ $html, ] = $this->executeSpecialPage( '', null, 'qqx' );

		$this->assertStringContainsString( 'categoryintersectionsearch-noinput', $html );
	}

	/**
	 * @covers \MediaWiki\Extension\FacetedCategory\CategoryIntersectionSearchViewer::doCategoryQuery
	 */
	public function testIntersection() {
		$this->editPage( 'Both', '[[Category:Facet/A]][[Category:Facet/B]]' );
		$this->editPage( 'OnlyA', '[[Category:Facet/A]]' );
		$this->editPage( 'AllThree', '[[Category:Facet/A]][[Category:Facet/B]][[Category:Facet/C]]' );

		[ $html, ] = $this->executeSpecialPage( 'Facet/A, Facet/B', null, 'qqx' );
		$this->assertStringContainsString( Title::newFromText( 'Both' )->getLocalURL(), $html );
		$this->assertStringContainsString( Title::newFromText( 'AllThree' )->getLocalURL(), $html );
		$this->assertStringNotContainsString( Title::newFromText( 'OnlyA' )->getLocalURL(), $html );

		[ $html, ] = $this->executeSpecialPage( 'Facet/A, Facet/B, -Facet/C', null, 'qqx' );
		$this->assertStringContainsString( Title::newFromText( 'Both' )->getLocalURL(), $html );
		$this->assertStringNotContainsString( Title::newFromText( 'AllThree' )->getLocalURL(), $html );
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
		$rt = $method->invoke( null, $term );
		$this->assertEquals( $expected, $rt );
	}
}
