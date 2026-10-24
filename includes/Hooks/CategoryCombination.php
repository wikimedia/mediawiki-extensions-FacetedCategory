<?php

namespace MediaWiki\Extension\FacetedCategory\Hooks;

use MediaWiki\Deferred\LinksUpdate\LinksUpdate;
use MediaWiki\Extension\FacetedCategory\CategoryCombinations;
use MediaWiki\Html\Html;
use MediaWiki\Parser\Parser;

class CategoryCombination implements
	\MediaWiki\Hook\ParserFirstCallInitHook,
	\MediaWiki\Hook\LinksUpdateCompleteHook,
	\MediaWiki\Hook\MakeGlobalVariablesScriptHook
{

	private CategoryCombinations $categoryCombinations;

	public function __construct( CategoryCombinations $categoryCombinations ) {
		$this->categoryCombinations = $categoryCombinations;
	}

	/**
	 * @inheritDoc
	 */
	public function onParserFirstCallInit( $parser ) {
		$parser->setFunctionHook( 'categorycombination', [ $this, 'renderCategoryCombination' ] );
	}

	/**
	 * {{#categorycombination:name|category|category...}} names a combination of
	 * categories, which is suggested on every page in all of them.
	 *
	 * @param Parser $parser
	 * @param string ...$args
	 * @return string
	 */
	public function renderCategoryCombination( Parser $parser, ...$args ): string {
		$combination = CategoryCombinations::parseArguments( $args );
		if ( $combination === null ) {
			return Html::errorBox(
				wfMessage( 'facetedcategory-combination-invalid' )->inContentLanguage()->escaped()
			);
		}
		$output = $parser->getOutput();
		$combinations = $output->getExtensionData( CategoryCombinations::PROPERTY_NAME ) ?? [];
		$combinations[] = $combination;
		$output->setExtensionData( CategoryCombinations::PROPERTY_NAME, $combinations );
		$output->setUnsortedPageProperty(
			CategoryCombinations::PROPERTY_NAME,
			json_encode( $combinations, JSON_UNESCAPED_UNICODE )
		);
		return '';
	}

	/**
	 * @inheritDoc
	 */
	public function onLinksUpdateComplete( $linksUpdate, $ticket ) {
		/** @var LinksUpdate $linksUpdate */
		$name = CategoryCombinations::PROPERTY_NAME;
		if ( array_key_exists( $name, $linksUpdate->getAddedProperties() )
			|| array_key_exists( $name, $linksUpdate->getRemovedProperties() )
		) {
			$this->categoryCombinations->touchNamed();
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onMakeGlobalVariablesScript( &$vars, $out ): void {
		$title = $out->getTitle();
		if ( !$out->isArticle() || !$title || !$title->exists() || !$title->isContentPage() ) {
			return;
		}
		$toDBkey = static fn ( $category ) => str_replace( ' ', '_', $category );
		$categories = array_map( $toDBkey, $out->getCategories( 'normal' ) );
		$direct = $out->getProperty( RecursiveCategory::DIRECT_CATEGORIES_PROPERTY_NAME );
		$direct = is_array( $direct ) ? array_map( $toDBkey, $direct ) : $categories;
		$combinations = $this->categoryCombinations->getForPage( $title, $categories, $direct );
		if ( !$combinations ) {
			return;
		}
		$vars['wgFacetedCategoryCombinations'] = array_map( static function ( $combination ) {
			return [
				'name' => $combination['name'],
				'categories' => array_map(
					static fn ( $category ) => str_replace( '_', ' ', $category ),
					$combination['categories']
				),
				'count' => $combination['count'],
			];
		}, $combinations );
	}
}
