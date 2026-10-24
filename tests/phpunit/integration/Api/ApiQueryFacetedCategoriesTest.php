<?php

namespace MediaWiki\Extension\FacetedCategory\Tests\Integration\Api;

use MediaWiki\Tests\Api\ApiTestCase;

/**
 * @group FacetedCategory
 * @group Database
 * @covers \MediaWiki\Extension\FacetedCategory\Api\ApiQueryFacetedCategories
 * @covers \MediaWiki\Extension\FacetedCategory\FacetedCategoryList
 */
class ApiQueryFacetedCategoriesTest extends ApiTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->editPage( 'Page1', '[[Category:Job/Politician]][[Category:Field/Politics]][[Category:Politics]]' );
		$this->editPage( 'Page2', '[[Category:Job/Politician]][[Category:Job/Political scientist]]' );
		$this->editPage( 'Page3', '[[Category:Job/Politician]][[Category:Job/Singer]][[Category:Field/Geopolitics]]' );
		// Category counts are updated by a job
		$this->runJobs( [ 'minJobs' => 0 ] );
	}

	/**
	 * @param array $params
	 * @return string[] Titles found
	 */
	private function search( array $params ): array {
		[ $result ] = $this->doApiRequest( [
			'action' => 'query',
			'list' => 'facetedcategories',
		] + $params );
		return array_column( $result['query']['facetedcategories'], 'title' );
	}

	public function testSearchByMember() {
		$this->assertSame(
			[
				'Category:Job/Politician',
				'Category:Field/Politics',
				'Category:Job/Political scientist',
				'Category:Field/Geopolitics',
			],
			$this->search( [ 'fcsearch' => 'polit' ] ),
			'Members starting with the text come first, then the ones with more pages; ' .
				'categories without a facet are left out'
		);
	}

	public function testSearchInFacet() {
		$this->assertSame(
			[ 'Category:Job/Politician', 'Category:Job/Political scientist' ],
			$this->search( [ 'fcsearch' => 'Job/Pol' ] )
		);
		$this->assertSame(
			[ 'Category:Job/Politician', 'Category:Job/Political scientist', 'Category:Job/Singer' ],
			$this->search( [ 'fcsearch' => '', 'fcfacet' => 'Job' ] )
		);
	}

	public function testResult() {
		[ $result ] = $this->doApiRequest( [
			'action' => 'query',
			'list' => 'facetedcategories',
			'fcsearch' => 'political scientist',
		] );
		$this->assertSame(
			[ [ 'title' => 'Category:Job/Political scientist', 'facet' => 'Job',
				'member' => 'Political scientist', 'pages' => 1 ] ],
			$result['query']['facetedcategories']
		);
	}

	public function testLimit() {
		$this->assertCount( 2, $this->search( [ 'fcsearch' => 'polit', 'fclimit' => 2 ] ) );
	}
}
