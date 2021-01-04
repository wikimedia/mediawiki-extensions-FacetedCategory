<?php

namespace MediaWiki\Extension\FacetedCategory;

use Title;

class FacetedCategoryHooks implements
	\MediaWiki\Hook\BeforePageDisplayHook,
	\MediaWiki\Hook\SpecialSearchResultsPrependHook
	{

	/**
	 * @inheritDoc
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$out->addModules( [ 'ext.facetedCategory.js' ] );
	}

	/**
	 * @inheritDoc
	 */
	public function onSpecialSearchResultsPrepend( $specialSearch, $output, $term ) {
		if ( $term === null || $term === '' || strpos( $term, "/" ) === false ) {
			return true;
		}

		$title = Title::newFromText( 'category:' . $term );
		if ( $title === null ) {
			return true;
		} elseif ( $title->exists() ) {
			$output->redirect( $title->getFullURL() );
			return false;
		}

		$categories = self::splitTerm( $term );
		if ( $categories !== null ) {
			$par = '';
			foreach ( $categories as $key => $value ) {
				if ( $key !== 0 ) { $par .= ', ';
				}
				$par .= $value;
			}
			$url = Title::newFromText( 'Special:CategoryIntersectionSearch/' . $par )->getFullURL();
			$output->redirect( $url );
			return false;
		}
		return true;
	}

	/**
	 * @param string $term
	 * @return array|null
	 */
	private static function splitTerm( $term ) {
		if ( strpos( $term, "," ) === false ) {
			return null;
		}

		$categories = explode( ",", $term );
		foreach ( $categories as $i => $value ) {
			if ( strpos( $value, "/" ) === false ) {
				return null;
			}
			$categories[$i] = trim( $value );
			$pos = strrchr( $value, ":" );
			if ( $pos !== false ) {
				$categories[$i] = substr( $pos, 1 );
			}
		}

		return $categories;
	}
}
