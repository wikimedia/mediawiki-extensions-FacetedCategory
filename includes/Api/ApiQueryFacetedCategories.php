<?php

namespace MediaWiki\Extension\FacetedCategory\Api;

use MediaWiki\Api\ApiQuery;
use MediaWiki\Api\ApiQueryBase;
use MediaWiki\Extension\FacetedCategory\FacetedCategoryList;
use MediaWiki\Title\Title;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;

/**
 * List the faceted categories whose member contains a text, for the category
 * input of VisualEditor.
 */
class ApiQueryFacetedCategories extends ApiQueryBase {

	private FacetedCategoryList $facetedCategoryList;

	public function __construct(
		ApiQuery $query,
		string $moduleName,
		FacetedCategoryList $facetedCategoryList
	) {
		parent::__construct( $query, $moduleName, 'fc' );
		$this->facetedCategoryList = $facetedCategoryList;
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$params = $this->extractRequestParams();
		$matches = $this->facetedCategoryList->search(
			$params['search'],
			$params['facet'],
			$params['limit']
		);

		$result = $this->getResult();
		foreach ( $matches as $match ) {
			$title = Title::makeTitle( NS_CATEGORY, $match['title'] );
			$result->addValue( [ 'query', $this->getModuleName() ], null, [
				'title' => $title->getPrefixedText(),
				'facet' => strtr( $match['facet'], '_', ' ' ),
				'member' => strtr( $match['member'], '_', ' ' ),
				'pages' => $match['pages'],
			] );
		}
		$result->addIndexedTagName( [ 'query', $this->getModuleName() ], 'c' );
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
			'search' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => '',
			],
			'facet' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
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
			'action=query&list=facetedcategories&fcsearch=politic'
				=> 'apihelp-query+facetedcategories-example-search',
			'action=query&list=facetedcategories&fcsearch=Job/P'
				=> 'apihelp-query+facetedcategories-example-facet',
		];
	}

	/**
	 * @inheritDoc
	 */
	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/Special:MyLanguage/Extension:FacetedCategory';
	}
}
