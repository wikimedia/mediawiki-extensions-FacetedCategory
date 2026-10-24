<?php

namespace MediaWiki\Extension\FacetedCategory\Tests\Integration;

use MediaWiki\Extension\FacetedCategory\Special\SpecialFacetedCategories;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Tests\Specials\SpecialPageTestBase;
use MediaWiki\Title\Title;

/**
 * @group FacetedCategory
 * @group Database
 * @covers \MediaWiki\Extension\FacetedCategory\Special\SpecialFacetedCategories
 * @covers \MediaWiki\Extension\FacetedCategory\FacetedCategoryList
 * @covers \MediaWiki\Extension\FacetedCategory\CategoryIntersection
 */
class SpecialFacetedCategoriesTest extends SpecialPageTestBase {
	/**
	 * @inheritDoc
	 */
	protected function newSpecialPage(): SpecialFacetedCategories {
		$services = $this->getServiceContainer();
		return new SpecialFacetedCategories(
			$services->getService( 'FacetedCategory.FacetedCategoryList' ),
			$services->getService( 'FacetedCategory.CategoryCombinations' ),
			$services->getConnectionProvider(),
			$services->getLinkBatchFactory(),
			$services->getCollationFactory()
		);
	}

	protected function setUp(): void {
		parent::setUp();
		$this->editPage( 'Page', '[[Category:Job/Politician]][[Category:Gender/Woman]][[Category:Plain]]' );
		$this->editPage( 'Other', '[[Category:Job/Politician]][[Category:Job/Actor]]' );
		// Category counts are updated by a job
		$this->runJobs( [ 'minJobs' => 0 ] );
	}

	public function testNoSubPage() {
		[ $html, ] = $this->executeSpecialPage( null, null, 'qqx' );

		$this->assertStringContainsString( 'facetedcategory-intro', $html );
		$this->assertStringContainsString( 'Job/Politician', $html );
		$this->assertStringContainsString( 'Gender/Woman', $html );
		$this->assertStringNotContainsString( 'Category:Plain', $html, 'A category without a facet is not listed' );
		$this->assertLessThan(
			strpos( $html, 'Gender/Woman' ),
			strpos( $html, 'Job/Politician' ),
			'The facet with more pages comes first'
		);
	}

	public function testFacet() {
		[ $html, ] = $this->executeSpecialPage( 'Job', null, 'qqx' );

		$this->assertStringContainsString( 'Job/Politician', $html );
		$this->assertStringContainsString( 'Job/Actor', $html );
		$this->assertStringNotContainsString( 'Gender/Woman', $html );
		$this->assertLessThan( strpos( $html, 'Job/Actor' ), strpos( $html, 'Job/Politician' ) );

		[ $html, ] = $this->executeSpecialPage( 'Job', new FauxRequest( [ 'sort' => 'name' ] ), 'qqx' );
		$this->assertLessThan( strpos( $html, 'Job/Politician' ), strpos( $html, 'Job/Actor' ) );

		[ $html, ] = $this->executeSpecialPage( 'Job', new FauxRequest( [ 'filter' => 'polit' ] ), 'qqx' );
		$this->assertStringContainsString( 'Job/Politician', $html );
		$this->assertStringNotContainsString( 'Job/Actor', $html );
	}

	public function testCategoryRedirects() {
		[ , $response ] = $this->executeSpecialPage( 'Job/Politician', null, 'qqx' );
		$this->assertSame(
			Title::makeTitle( NS_CATEGORY, 'Job/Politician' )->getFullURL(),
			$response->getHeader( 'Location' )
		);
	}

	public function testOldForm() {
		[ $html, ] = $this->executeSpecialPage(
			null,
			new FauxRequest( [ 'facetName' => 'Job', 'facetMember' => '' ] ),
			'qqx'
		);

		$this->assertStringContainsString( 'Job/Politician', $html );
		$this->assertStringNotContainsString( 'Gender/Woman', $html );
	}

	public function testIntersection() {
		$this->editPage( 'Both', '[[Category:Facet/A]][[Category:Facet/B]]' );
		$this->editPage( 'OnlyA', '[[Category:Facet/A]]' );
		$this->editPage( 'AllThree', '[[Category:Facet/A]][[Category:Facet/B]][[Category:Facet/C]]' );

		[ $html, ] = $this->executeSpecialPage( 'Facet/A, Facet/B', null, 'qqx' );
		$this->assertStringContainsString( Title::newFromText( 'Both' )->getLocalURL(), $html );
		$this->assertStringContainsString( Title::newFromText( 'AllThree' )->getLocalURL(), $html );
		$this->assertStringNotContainsString( Title::newFromText( 'OnlyA' )->getLocalURL(), $html );
		$this->assertStringContainsString( '(facetedcategory-result-count: 2)', $html );

		[ $html, ] = $this->executeSpecialPage( 'Facet/A, Facet/B, -Facet/C', null, 'qqx' );
		$this->assertStringContainsString( Title::newFromText( 'Both' )->getLocalURL(), $html );
		$this->assertStringNotContainsString( Title::newFromText( 'AllThree' )->getLocalURL(), $html );

		[ $html, ] = $this->executeSpecialPage( 'Facet/A, Facet/Missing', null, 'qqx' );
		$this->assertStringContainsString( 'facetedcategory-no-results', $html );
	}

	public function testAddConditions() {
		[ , $response ] = $this->executeSpecialPage(
			null,
			new FauxRequest( [ 'conditions' => 'Facet/A', 'add' => [ 'Facet/B' ] ] ),
			'qqx'
		);
		$this->assertSame(
			$this->newSpecialPage()->getPageTitle( 'Facet/A,Facet/B' )->getFullURL(),
			$response->getHeader( 'Location' )
		);
	}

	public static function provideConditions() {
		return [
			[ 'Solid', [ 'Solid' ], [] ],
			[ 'A/B, C/D', [ 'A/B', 'C/D' ], [] ],
			[ 'A/B, -C/D', [ 'A/B' ], [ 'C/D' ] ],
			[ 'Category:A/B, -Category:C_D,, -A/B', [ 'A/B' ], [ 'C_D' ] ],
		];
	}

	/**
	 * @dataProvider provideConditions
	 */
	public function testParseConditions( $text, $include, $exclude ) {
		[ $actualInclude, $actualExclude ] = SpecialFacetedCategories::parseConditions( $text );
		$keys = static fn ( $titles ) => array_map( static fn ( Title $title ) => $title->getDBkey(), $titles );
		$this->assertSame( $include, $keys( $actualInclude ) );
		$this->assertSame( $exclude, $keys( $actualExclude ) );
	}
}
