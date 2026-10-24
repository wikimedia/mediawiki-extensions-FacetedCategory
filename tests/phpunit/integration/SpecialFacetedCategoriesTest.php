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
 */
class SpecialFacetedCategoriesTest extends SpecialPageTestBase {
	/**
	 * @inheritDoc
	 */
	protected function newSpecialPage(): SpecialFacetedCategories {
		$services = $this->getServiceContainer();
		return new SpecialFacetedCategories(
			$services->getService( 'FacetedCategory.FacetedCategoryList' ),
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
}
