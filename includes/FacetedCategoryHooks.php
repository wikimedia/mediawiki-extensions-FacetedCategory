<?php

class FacetedCategoryHooks {

	/**
	 * @param OutputPage &$out
	 * @param Skin &$skin
	 * @return bool
	 */
	public static function onBeforePageDisplay( OutputPage &$out, Skin &$skin ) {
		$out->addModules( [ 'ext.facetedCategory.js' ] );

		return true;
	}
}
