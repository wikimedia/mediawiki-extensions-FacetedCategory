<?php

namespace MediaWiki\Extension\FacetedCategory\Tests\Integration\Api;

use MediaWiki\Tests\Api\ApiTestCase;

/**
 * @group FacetedCategory
 * @group Database
 * @covers \MediaWiki\Extension\FacetedCategory\Api\ApiQueryCategoryFacets
 * @covers \MediaWiki\Extension\FacetedCategory\FacetedCategoryList::getFacets
 */
class ApiQueryCategoryFacetsTest extends ApiTestCase {

	public function testFacets() {
		$this->editPage( 'Page1', '[[Category:Job/Politician]][[Category:Field/Politics]][[Category:Politics]]' );
		$this->editPage( 'Page2', '[[Category:Job/Politician]][[Category:Job/Singer]]' );
		$this->editPage( 'Page3', '[[Category:Job/Singer]][[Category:Birth place/Seoul]][[Category:Field/Music]]' );
		// Category counts are updated by a job
		$this->runJobs( [ 'minJobs' => 0 ] );

		[ $result ] = $this->doApiRequest( [
			'action' => 'query',
			'list' => 'categoryfacets',
			'cflimit' => 2,
		] );
		$this->assertSame(
			[ [ 'facet' => 'Job', 'pages' => 4 ], [ 'facet' => 'Field', 'pages' => 2 ] ],
			$result['query']['categoryfacets']
		);
	}
}
