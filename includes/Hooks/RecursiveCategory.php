<?php

namespace MediaWiki\Extension\FacetedCategory\Hooks;

use Category;
use JobQueueGroup;
use RefreshLinksJob;
use Title;
use Wikimedia\Rdbms\ILoadBalancer;

class RecursiveCategory implements
	\MediaWiki\Page\Hook\CategoryAfterPageAddedHook,
	\MediaWiki\Content\Hook\ContentAlterParserOutputHook,
	\MediaWiki\Hook\OutputPageParserOutputHook,
	\MediaWiki\Hook\MakeGlobalVariablesScriptHook
{

	/** @const string direct-categories key. */
	public const DIRECT_CATEGORIES_PROPERTY_NAME = 'direct-categories';

	/** @var ILoadBalancer */
	private $loadBalancer;

	/** @var JobQueueGroup */
	private $jobQueueGroup;

	/**
	 * @param ILoadBalancer $loadBalancer
	 * @param JobQueueGroup $jobQueueGroup
	 */
	public function __construct( ILoadBalancer $loadBalancer, JobQueueGroup $jobQueueGroup ) {
		$this->loadBalancer = $loadBalancer;
		$this->jobQueueGroup = $jobQueueGroup;
	}

	/**
	 * @inheritDoc
	 */
	public function onCategoryAfterPageAdded( $category, $wikiPage ) {
		$title = $wikiPage->getTitle();
		if ( $title->getNamespace() !== NS_CATEGORY || !str_contains( $title->getText(), '/' ) ) {
			return true;
		}

		$dbr = $this->loadBalancer->getConnectionRef( ILoadBalancer::DB_REPLICA );
		$pages = $dbr->selectFieldValues(
			'categorylinks',
			'cl_from',
			[ 'cl_to' => $title->getDBKey() ],
			__METHOD__
		);
		foreach ( $pages as $id ) {
			$title = Title::newFromId( $id );
			$job = new RefreshLinksJob( $title, [ 'parseThreshold' => 0 ] );
			$this->jobQueueGroup->push( $job );
		}
	}

	/**
	 * When adding categories to a page, include the parent of the category also.
	 * @inheritDoc
	 */
	public function onContentAlterParserOutput( $content, $title, $parserOutput ) {
		$cats = $parserOutput->getCategoryNames();
		if ( !$cats ) {
			return;
		}
		$cats = array_map( static function ( $cat ) {
			return str_replace( '_', ' ', $cat );
		}, $cats );
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
				$sort = $parserOutput->getPageProperty( 'defaultsort' ) ?? '';
				$parserOutput->addCategory(
					$parentTitle->getDBkey(),
					$sort
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
