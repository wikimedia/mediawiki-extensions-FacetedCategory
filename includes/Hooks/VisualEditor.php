<?php

namespace MediaWiki\Extension\FacetedCategory\Hooks;

use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\ResourceLoader\Hook\ResourceLoaderRegisterModulesHook;
use MediaWiki\ResourceLoader\ResourceLoader;

/**
 * Registers the plugin module for VisualEditor, only when VisualEditor is
 * installed because the module depends on it.
 */
class VisualEditor implements ResourceLoaderRegisterModulesHook {

	/**
	 * @inheritDoc
	 */
	public function onResourceLoaderRegisterModules( ResourceLoader $rl ): void {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'VisualEditor' ) ) {
			return;
		}
		$rl->register( 'ext.facetedCategory.visualEditor', [
			'localBasePath' => dirname( __DIR__, 2 ) . '/modules',
			'remoteExtPath' => 'FacetedCategory/modules',
			'scripts' => [ 've/categoryInput.js' ],
			'styles' => [ 've/categoryInput.less' ],
			'messages' => [
				'facetedcategory-ve-page-facets',
				'facetedcategory-ve-common-facets',
			],
			'dependencies' => [ 'ext.visualEditor.mwmeta', 'mediawiki.language' ],
		] );
	}
}
