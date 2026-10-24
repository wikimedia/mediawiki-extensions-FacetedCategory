<?php

use MediaWiki\Extension\FacetedCategory\CategoryCombinations;
use MediaWiki\MediaWikiServices;

/** @phpcs-require-sorted-array */
return [
	'FacetedCategory.CategoryCombinations' => static function (
		MediaWikiServices $services
	): CategoryCombinations {
		return new CategoryCombinations(
			$services->getConnectionProvider(),
			$services->getMainWANObjectCache(),
			$services->getJobQueueGroup()
		);
	},
];
