<?php

namespace MediaWiki\Extension\FacetedCategory;

use MediaWiki\JobQueue\GenericParameterJob;
use MediaWiki\JobQueue\Job;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * Compute and cache the category combinations of a page, out of the page view.
 */
class CategoryCombinationsJob extends Job implements GenericParameterJob {

	/**
	 * @param array $params 'namespace' and 'title' of the page, 'categories' (DB keys
	 *  of its faceted categories) and 'direct' (the ones the page adds itself)
	 */
	public function __construct( array $params ) {
		parent::__construct( 'facetedCategoryCombinations', $params );
		$this->removeDuplicates = true;
	}

	/** @inheritDoc */
	public function run() {
		$title = Title::castFromPageReference( $this->getTitle() );
		if ( !$title || !$title->exists() ) {
			return true;
		}
		/** @var CategoryCombinations $categoryCombinations */
		$categoryCombinations = MediaWikiServices::getInstance()
			->getService( 'FacetedCategory.CategoryCombinations' );
		$categoryCombinations->refresh( $title, $this->params['categories'], $this->params['direct'] );
		return true;
	}
}
