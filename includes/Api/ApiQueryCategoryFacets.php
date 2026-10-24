<?php

namespace MediaWiki\Extension\FacetedCategory\Api;

use MediaWiki\Api\ApiQuery;
use MediaWiki\Api\ApiQueryBase;
use MediaWiki\Extension\FacetedCategory\FacetedCategoryList;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;

/**
 * List the facets in which pages are categorized the most, for the category
 * input of VisualEditor.
 */
class ApiQueryCategoryFacets extends ApiQueryBase {

	private FacetedCategoryList $facetedCategoryList;

	public function __construct(
		ApiQuery $query,
		string $moduleName,
		FacetedCategoryList $facetedCategoryList
	) {
		parent::__construct( $query, $moduleName, 'cf' );
		$this->facetedCategoryList = $facetedCategoryList;
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$params = $this->extractRequestParams();
		$result = $this->getResult();
		foreach ( $this->facetedCategoryList->getFacets( $params['limit'] ) as $facet ) {
			$result->addValue( [ 'query', $this->getModuleName() ], null, [
				'facet' => strtr( $facet['facet'], '_', ' ' ),
				'pages' => $facet['pages'],
			] );
		}
		$result->addIndexedTagName( [ 'query', $this->getModuleName() ], 'f' );
	}

	/**
	 * @inheritDoc
	 */
	public function getCacheMode( $params ) {
		return 'public';
	}

	/**
	 * @inheritDoc
	 */
	protected function getAllowedParams() {
		return [
			'limit' => [
				ParamValidator::PARAM_TYPE => 'limit',
				ParamValidator::PARAM_DEFAULT => 10,
				IntegerDef::PARAM_MIN => 1,
				IntegerDef::PARAM_MAX => FacetedCategoryList::MAX_LIMIT,
				IntegerDef::PARAM_MAX2 => FacetedCategoryList::MAX_LIMIT,
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	protected function getExamplesMessages() {
		return [
			'action=query&list=categoryfacets&cflimit=20'
				=> 'apihelp-query+categoryfacets-example-limit',
		];
	}

	/**
	 * @inheritDoc
	 */
	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/Special:MyLanguage/Extension:FacetedCategory';
	}
}
