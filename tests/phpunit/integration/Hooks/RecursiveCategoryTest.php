<?php

namespace MediaWiki\Extension\FacetedCategory\Tests\Integration\Hooks;

use CommentStoreComment;
use MediaWikiIntegrationTestCase;
use Title;
use WikitextContent;

/**
 * @group Database
 * @covers MediaWiki\Extension\FacetedCategory\Hooks\RecursiveCategory
 */
class RecursiveCategoryTest extends MediaWikiIntegrationTestCase {
	protected function assertCategory( $title, $expected, string $message = '' ) {
		$actual = $this->db->selectFieldValues(
			'categorylinks',
			'cl_to',
			[
				'cl_from' => $title->getId(),
			],
		);
		$this->assertEqualsCanonicalizing( $expected, $actual, $message );
	}

	public function testCategorizedUsingParent() {
		$parent = $this->createTitle( 'Category:Facet/Parent1', '[[Category:Facet/Cat1]][[Category:Facet/Cat2]]' );
		$child = $this->createTitle( 'Child1', '[[Category:Facet/Parent1]]' );
		$this->assertCategory( $child, [
			'Facet/Cat1',
			'Facet/Cat2',
			'Facet/Parent1',
		],
		'Should be registered to the specified category including the grandparent categories.'
	);
	}

	public function testLateAddingCategoryToParent() {
		$parentText = 'Facet/Parent-' . mt_rand();
		$child = $this->createTitle( 'Child-' . mt_rand(), "[[Category:$parentText]]" );
		$parent = $this->createTitle( "Category:$parentText", '[[Category:Facet/Cat1]]' );
		$this->assertCategory( $child, [ $parentText ] );

		$this->runJobs();

		$this->assertCategory( $child, [
			'Facet/Cat1',
			$parentText,
		],
		'Should be registered to the specified category including the grandparent categories'
		);
	}

	public function testGrandParentCategoryChange() {
		$suffix = mt_rand();
		$child = $this->createTitle( "Child-$suffix", "[[Category:Facet/Parent-$suffix]]" );
		$parent = $this->createTitle( "Category:Facet/Parent-$suffix", "[[Category:Facet/GrandParent-$suffix]]" );
		$grandParent = $this->createTitle( "Category:Facet/GrandParent-$suffix", "[[Category:Facet/Cat1-$suffix]]" );

		$this->runJobs();

		$this->assertCategory( $child, [
			"Facet/Cat1-$suffix",
			"Facet/Parent-$suffix",
			"Facet/GrandParent-$suffix",
		] );
	}

	/**
	 * @param string $name
	 * @param string $content
	 * @return Title
	 */
	protected function createTitle( $name, $content ) {
		$content = new WikitextContent( $content );

		$title = Title::newFromText( $name );
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $title );

		$updater = $page->newPageUpdater( $this->getTestUser()->getUser() );
		$updater->setContent( 'main', $content );
		$updater->saveRevision( CommentStoreComment::newUnsavedComment( 'Test' ) );

		return $page->getTitle();
	}
}
