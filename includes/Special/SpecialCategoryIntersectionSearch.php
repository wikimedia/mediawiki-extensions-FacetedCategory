<?php

namespace MediaWiki\Extension\FacetedCategory\Special;

use MediaWiki\Extension\FacetedCategory\CategoryIntersectionSearchViewer;
use SpecialPage;
use Title;

class SpecialCategoryIntersectionSearch extends SpecialPage {

	public function __construct() {
		parent::__construct( 'CategoryIntersectionSearch' );
	}

	/**
	 * @param string $subPage
	 */
	public function execute( $subPage ) {
		$request = $this->getRequest();
		$output = $this->getOutput();
		$this->setHeaders();

		if ( !$subPage ) {
			$output->addWikiTextAsInterface( $this->msg( 'categoryintersectionsearch-noinput' ) );
			return;
		}
		list( $categories, $exCategories ) = self::splitCategories( $subPage );

		if ( count( $categories ) == 0 && count( $exCategories ) > 0 ) {
			$output->addWikiTextAsInterface( $this->msg( 'categoryintersectionsearch-noinput' ) );
			return;
		} elseif ( count( $categories ) < 2 && count( $exCategories ) == 0 ) {
			$output->redirect( Title::newFromText( $subPage )->getFullURL(), NS_CATEGORY );
			return;
		}

		$title = implode( '", "', $categories );
		if ( count( $exCategories ) != 0 ) {
			$title .= ', -"' . implode( '", -"', $exCategories );
		}
		$title = str_replace( '_', ' ', $title );

		$output->setPageTitle( $this->msg( 'categoryintersectionsearch-page-title', $title ) );

		// Start: Copied from CategoryTree in MW 1.27
		$oldFrom = $request->getVal( 'from' );
		$oldUntil = $request->getVal( 'until' );

		$reqArray = $request->getValues();
		$from = $until = [];
		foreach ( [ 'page', 'subcat', 'file' ] as $type ) {
			$from[$type] = $request->getVal( "{$type}from", $oldFrom );
			$until[$type] = $request->getVal( "{$type}until", $oldUntil );

			// Do not want old-style from/until propagating in nav links.
			if ( !isset( $reqArray["{$type}from"] ) && isset( $reqArray["from"] ) ) {
				$reqArray["{$type}from"] = $reqArray["from"];
			}
			if ( !isset( $reqArray["{$type}to"] ) && isset( $reqArray["to"] ) ) {
				$reqArray["{$type}to"] = $reqArray["to"];
			}
		}
		unset( $reqArray["from"] );
		unset( $reqArray["to"] );
		// End: Copied from CategoryTree in MW 1.27

		$viewer = new CategoryIntersectionSearchViewer(
			Title::newFromText( $title ),
			$this->getContext(),
			$categories,
			$exCategories,
			$from,
			$until,
			$reqArray
		);
		$output->addHTML( $viewer->getHTML() );
	}

	/**
	 * @param string $term
	 * @return array
	 */
	private static function splitCategories( $term ) {
		$tokens = explode( ",", $term );
		$count = count( $tokens );
		if ( $count == 1 ) {
			return [ [], [] ];
		}

		$categories = [];
		$exCategories = [];
		for ( $i = 0; $i < $count; $i++ ) {
			if ( strpos( $tokens[$i], '/' ) === false ) {
				return [ [], [] ];
			}
			$tokens[$i] = trim( $tokens[$i], ' _' );
			$pos = strrchr( $tokens[$i], ':' );
			if ( $pos !== false ) {
				$tokens[$i] = trim( substr( $pos, 1 ) );
			}
			if ( $tokens[$i][0] != '-' ) {
				$categories[] = $tokens[$i];
			} else {
				$exCategories[] = substr( $tokens[$i], 1 );
			}
		}
		return [
			$categories,
			$exCategories,
		];
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'pages';
	}
}
