<?php

namespace MediaWiki\Extension\FacetedCategory\Tests\Integration;

use MediaWiki\Extension\FacetedCategory\Special\SpecialFacetedCategories;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Tests\Specials\SpecialPageTestBase;

/**
 * @group FacetedCategory
 * @group Database
 * @covers \MediaWiki\Extension\FacetedCategory\Special\SpecialFacetedCategories
 */
class SpecialFacetedCategoriesTest extends SpecialPageTestBase {
	/**
	 * @inheritDoc
	 */
	protected function newSpecialPage(): SpecialFacetedCategories {
		$services = $this->getServiceContainer();
		return new SpecialFacetedCategories(
			$services->getService( 'CategoryTree.CategoryTreeFactory' ),
			$services->getLinkBatchFactory()
		);
	}

	protected function setUp(): void {
		parent::setUp();
		$this->editPage( 'Page', '[[Category:Job/Politician]][[Category:Gender/Woman]][[Category:Plain]]' );
		// Category counts are updated by a job
		$this->runJobs( [ 'minJobs' => 0 ] );
	}

	public function testNoSubPage() {
		[ $html, ] = $this->executeSpecialPage( null, null, 'qqx' );

		$this->assertStringContainsString( 'facetName', $html, 'The form is shown' );
		$this->assertStringContainsString( 'Job/Politician', $html );
		$this->assertStringContainsString( 'Gender/Woman', $html );
		$this->assertStringNotContainsString( 'Plain', $html, 'A category without a facet is not listed' );
	}

	public function testFormSubmission() {
		[ $html, ] = $this->executeSpecialPage(
			null,
			new FauxRequest( [ 'facetName' => 'Job', 'facetMember' => '' ] ),
			'qqx'
		);

		$this->assertStringContainsString( 'Job/Politician', $html );
		$this->assertStringNotContainsString( 'Gender/Woman', $html );
	}

	public function testSubPage() {
		[ $html, ] = $this->executeSpecialPage( 'Gender', null, 'qqx' );

		$this->assertStringContainsString( 'Gender/Woman', $html );
		$this->assertStringNotContainsString( 'Job/Politician', $html );
	}
}
