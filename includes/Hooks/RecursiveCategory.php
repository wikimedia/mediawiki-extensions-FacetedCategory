<?php

namespace MediaWiki\Extension\FacetedCategory\Hooks;

use Category;
use Title;

class RecursiveCategory implements
	\MediaWiki\Content\Hook\ContentAlterParserOutputHook,
	\MediaWiki\Hook\OutputPageParserOutputHook,
	\MediaWiki\Hook\MakeGlobalVariablesScriptHook
{

	/**
	 * direct-categories key.
	 */
	public const DIRECT_CATEGORIES_PROPERTY_NAME = 'direct-categories';

	/**
	 * When adding categories to a page, include the parent of the category also.
	 * @inheritDoc
	 */
	public function onContentAlterParserOutput( $content, $title, $parserOutput ) {
		$cats = array_keys( $parserOutput->getCategories() );
		if ( !$cats ) {
			return;
		}
		$parserOutput->setExtensionData( self::DIRECT_CATEGORIES_PROPERTY_NAME, $cats );
		foreach ( $cats as $text ) {
			if ( !str_contains( $text, '/' ) ) {
				continue;
			}
			$cat = Category::newFromName( $text );
			$title = Title::castFromPageIdentity( $cat->getPage() );
			foreach ( array_keys( $title->getParentCategories() ) as $parentText ) {
				if ( !str_contains( $parentText, '/' ) ) {
					continue;
				}
				$parentTitle = Title::newFromText( $parentText );
				$parserOutput->addCategory(
					$parentTitle->getText(),
					$parserOutput->getPageProperty( 'defaultsort' ) ?: ''
				);
			}
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onOutputPageParserOutput( $out, $parserOutput ): void {
		$cats = $parserOutput->getExtensionData( self::DIRECT_CATEGORIES_PROPERTY_NAME );
		if ( $cats ) {
			$out->setProperty( self::DIRECT_CATEGORIES_PROPERTY_NAME, $cats );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onMakeGlobalVariablesScript( &$vars, $out ): void {
		$cats = $out->getProperty( self::DIRECT_CATEGORIES_PROPERTY_NAME );
		if ( $cats ) {
			$vars['wgDirectCategories'] = $cats;
		}
	}
}
