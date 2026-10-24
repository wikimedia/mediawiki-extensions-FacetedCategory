<?php

namespace MediaWiki\Extension\FacetedCategory\Tests\Integration;

use MediaWiki\Extension\FacetedCategory\CategoryCombinations;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * @group Database
 * @covers \MediaWiki\Extension\FacetedCategory\CategoryCombinations
 * @covers \MediaWiki\Extension\FacetedCategory\CategoryCombinationsJob
 * @covers \MediaWiki\Extension\FacetedCategory\Hooks\CategoryCombination
 */
class CategoryCombinationsTest extends MediaWikiIntegrationTestCase {

	public static function provideParseArguments() {
		return [
			'named' => [
				[ 'Women politicians', 'Job/Politician', ' Category:Gender/Woman ' ],
				[ 'name' => 'Women politicians', 'categories' => [ 'Job/Politician', 'Gender/Woman' ] ],
			],
			'one category' => [ [ 'Politicians', 'Job/Politician', '' ], null ],
			'no name' => [ [ '', 'Job/Politician', 'Gender/Woman' ], null ],
			'not a category' => [ [ 'Politicians', 'Job/Politician', 'Template:Foo' ], null ],
		];
	}

	/**
	 * @dataProvider provideParseArguments
	 */
	public function testParseArguments( array $args, ?array $expected ) {
		$this->assertSame( $expected, CategoryCombinations::parseArguments( $args ) );
	}

	private function getCombinations( string $page ): array {
		$title = Title::newFromText( $page );
		$categories = array_keys( $title->getParentCategories() );
		$categories = array_map(
			static fn ( $category ) => Title::newFromText( $category )->getDBkey(),
			$categories
		);
		$service = $this->getServiceContainer()->getService( 'FacetedCategory.CategoryCombinations' );
		// The first view only queues the job
		$this->assertSame( [], $service->getForPage( $title, $categories, $categories ) );
		$this->runJobs( [ 'minJobs' => 0 ], [ 'type' => 'facetedCategoryCombinations' ] );
		return $service->getForPage( $title, $categories, $categories );
	}

	private function refreshCombinations( string $page ): array {
		$title = Title::newFromText( $page );
		$categories = array_map(
			static fn ( $category ) => Title::newFromText( $category )->getDBkey(),
			array_keys( $title->getParentCategories() )
		);
		$service = $this->getServiceContainer()->getService( 'FacetedCategory.CategoryCombinations' );
		$service->getForPage( $title, $categories, $categories );
		$this->runJobs( [ 'minJobs' => 0 ], [ 'type' => 'facetedCategoryCombinations' ] );
		return $service->getForPage( $title, $categories, $categories );
	}

	/**
	 * @param string $politician1 Extra wikitext of the first politician page
	 */
	private function createPages( string $politician1 = '' ): void {
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->editPage(
				"Politician$i",
				'[[Category:Job/Politician]][[Category:Gender/Woman]][[Category:Born/1955]]' .
					( $i === 1 ? $politician1 : '' )
			);
		}
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->editPage( "Man$i", '[[Category:Job/Politician]][[Category:Gender/Man]][[Category:Born/1955]]' );
		}
		$this->editPage( 'Woman1', '[[Category:Job/Writer]][[Category:Gender/Woman]][[Category:Born/1955]]' );
		$this->runJobs();
	}

	public function testComputedCombinations() {
		$this->createPages();
		$this->assertSame(
			[ [ 'name' => null, 'categories' => [ 'Gender/Woman', 'Job/Politician' ], 'count' => 3 ] ],
			$this->getCombinations( 'Politician1' ),
			'The combination of the categories which narrow the pages down'
		);
	}

	public function testNamedCombinations() {
		$this->createPages( '{{#categorycombination:Women politicians|Job/Politician|Gender/Woman}}' );
		$this->assertSame(
			[ [ 'name' => 'Women politicians', 'categories' => [ 'Job/Politician', 'Gender/Woman' ], 'count' => 3 ] ],
			$this->getCombinations( 'Politician2' ),
			'A named combination replaces the computed one of the same pages, on every page in it'
		);
	}

	public function testNamedCombinationAdded() {
		$this->createPages();
		$this->getCombinations( 'Politician2' );

		$this->editPage(
			'Politician1',
			'[[Category:Job/Politician]][[Category:Gender/Woman]][[Category:Born/1955]]' .
				'{{#categorycombination:Women politicians|Job/Politician|Gender/Woman}}'
		);
		$this->runJobs();
		$this->assertSame(
			[ [ 'name' => 'Women politicians', 'categories' => [ 'Job/Politician', 'Gender/Woman' ], 'count' => 3 ] ],
			$this->refreshCombinations( 'Politician2' ),
			'A combination named on one page is used at once on the other pages in it'
		);
	}
}
