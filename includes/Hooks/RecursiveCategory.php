<?php

namespace MediaWiki\Extension\FacetedCategory\Hooks;

use MediaWiki\Category\Category;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\JobQueue\Jobs\RefreshLinksJob;
use MediaWiki\Linker\LinksMigration;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IConnectionProvider;

class RecursiveCategory implements
	\MediaWiki\Page\Hook\CategoryAfterPageAddedHook,
	\MediaWiki\Content\Hook\ContentAlterParserOutputHook,
	\MediaWiki\Hook\OutputPageParserOutputHook,
	\MediaWiki\Hook\MakeGlobalVariablesScriptHook
{

	/** @const string direct-categories key. */
	public const DIRECT_CATEGORIES_PROPERTY_NAME = 'direct-categories';

	private IConnectionProvider $connectionProvider;
	private JobQueueGroup $jobQueueGroup;
	private LinksMigration $linksMigration;

	public function __construct(
		IConnectionProvider $connectionProvider,
		JobQueueGroup $jobQueueGroup,
		LinksMigration $linksMigration
	) {
		$this->connectionProvider = $connectionProvider;
		$this->jobQueueGroup = $jobQueueGroup;
		$this->linksMigration = $linksMigration;
	}

	/**
	 * @inheritDoc
	 */
	public function onCategoryAfterPageAdded( $category, $wikiPage ) {
		$title = $wikiPage->getTitle();
		if ( $title->getNamespace() !== NS_CATEGORY || !str_contains( $title->getText(), '/' ) ) {
			return true;
		}

		$dbr = $this->connectionProvider->getReplicaDatabase();
		$pages = $dbr->newSelectQueryBuilder()
			->select( 'cl_from' )
			->from( 'categorylinks' )
			->where( $this->linksMigration->getLinksConditions( 'categorylinks', $title ) )
			->caller( __METHOD__ )
			->fetchFieldValues();
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
