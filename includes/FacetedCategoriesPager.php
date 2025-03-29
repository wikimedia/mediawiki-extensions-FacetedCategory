<?php

namespace MediaWiki\Extension\FacetedCategory;

use AlphabeticPager;
use IContextSource;
use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\Extension\CategoryTree\CategoryTree;
use MediaWiki\Html\Html;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IConnectionProvider;
use Xml;

class FacetedCategoriesPager extends AlphabeticPager {

	private LinkBatchFactory $linkBatchFactory;
	private IConnectionProvider $dbProvider;
	private CategoryTree $tree;
	private string $facetName;
	private string $facetMember;
	private bool $includeNotExactlyMatched;
	private bool $including;

	public function __construct(
		IContextSource $context,
		string $facetName,
		string $facetMember,
		bool $includeNotExactlyMatched,
		bool $including,
		LinkBatchFactory $linkBatchFactory,
		IConnectionProvider $dbProvider
	) {
		parent::__construct( $context );
		$facetName = str_replace( ' ', '_', $facetName );
		$facetMember = str_replace( ' ', '_', $facetMember );

		$this->facetName = $facetName;
		$this->facetMember = $facetMember;
		$this->includeNotExactlyMatched = $includeNotExactlyMatched;
		$this->including = $including;

		if ( $this->including ) {
			$this->setLimit( 200 );
			$this->includeNotExactlyMatched = false;
		}

		$this->linkBatchFactory = $linkBatchFactory;
		$this->dbProvider = $dbProvider;
	}

	/**
	 * @return array
	 */
	public function getQueryInfo() {
		$query = [
			'tables' => [ 'category' ],
			'fields' => [ 'cat_title' ],
			'conds' => [ 'cat_pages > 0' ],
			'options' => [ 'USE INDEX' => 'cat_title' ],
		];
		$anyString = $this->mDb->anyString();

		if ( $this->includeNotExactlyMatched ) {
			$query['conds'][] = 'cat_title' . $this->mDb->buildLike(
				$anyString,
				$this->facetName,
				$anyString,
				'/',
				$anyString,
				$this->facetMember,
				$anyString
			);
		} else {
			if ( $this->facetName != '' && $this->facetMember != '' ) {
				$query['conds'][] = 'cat_title' . $this->mDb->buildLike( $this->facetName . '/' . $this->facetMember );
			} elseif ( $this->facetName != '' && $this->facetMember == '' ) {
				$query['conds'][] = 'cat_title' . $this->mDb->buildLike( $this->facetName . '/', $anyString );
			} elseif ( $this->facetName == '' && $this->facetMember != '' ) {
				$query['conds'][] = 'cat_title' . $this->mDb->buildLike( $anyString, '/' . $this->facetMember );
			} else {
				$query['conds'][] = 'cat_title' . $this->mDb->buildLike( $anyString, '/', $anyString );
			}
		}

		return $query;
	}

	/**
	 * @inheritDoc
	 */
	public function getIndexField() {
		return 'cat_title';
	}

	/**
	 * @inheritDoc
	 */
	public function getDefaultQuery() {
		parent::getDefaultQuery();

		return $this->mDefaultQuery;
	}

	/**
	 * getBody to apply LinksBatch on resultset before actually outputting anything.
	 * @inheritDoc
	 */
	public function getBody() {
		$batch = $this->linkBatchFactory->newLinkBatch();

		$this->mResult->rewind();

		foreach ( $this->mResult as $row ) {
			$batch->addObj( Title::makeTitleSafe( NS_CATEGORY, $row->cat_title ) );
		}
		$batch->execute();
		CategoryTree::setHeaders( $this->getOutput() );
		$this->mResult->rewind();

		return parent::getBody();
	}

	/**
	 * @inheritDoc
	 */
	public function formatRow( $result ) {
		/*
		$title = new TitleValue( NS_CATEGORY, $result->cat_title );
		$text = $title->getText();
		$link = $this->getLinkRenderer()->renderHtmlLink( $title, $text );

		$count = $this->msg( 'nmembers' )->numParams( $result->cat_pages )->escaped();
		*/

		$defaultOptions = $this->getConfig()->get( 'CategoryTreeDefaultOptions' );
		$specialPageOptions = $this->getConfig()->get( 'CategoryTreeSpecialPageOptions' );

		$title = Title::makeTitle( NS_CATEGORY, $result->cat_title );

		$options = [];
		// grab all known options from the request. Normalization is done by the CategoryTree class
		foreach ( $defaultOptions as $option => $default ) {
			if ( isset( $specialPageOptions[$option] ) ) {
				$default = $specialPageOptions[$option];
			}
			$options[$option] = $default;
		}
		$options['mode'] = 'categories';
		$this->tree = new CategoryTree( $options, $this->getConfig(), $this->dbProvider, $this->getLinkRenderer() );

		return $this->tree->renderNode( $title );
	}

	/**
	 * @param string $facetName
	 * @param string $facetMember
	 * @param bool $includeNotExactlyMatched
	 * @return string
	 */
	public function getStartForm( $facetName, $facetMember, $includeNotExactlyMatched ) {
		return $this->including ? '' : Xml::tags(
			'form',
			[ 'method' => 'get', 'action' => wfScript() ],
			Html::hidden( 'title', $this->getTitle()->getPrefixedText() ) .
			Xml::fieldset(
				$this->msg( 'categories' )->text(),
				$this->msg( 'facetedcategory-search-for' )->escaped() .
				' ' .
				Xml::input(
					'facetName', 10, $facetName, [ 'class' => 'mw-ui-input-inline' ] ) .
				' / ' .
				Xml::input(
					'facetMember', 10, $facetMember, [ 'class' => 'mw-ui-input-inline' ] ) .
				' ' .
				Html::submitButton(
					$this->msg( 'categories-submit' )->text(),
					[], [ 'mw-ui-progressive' ]
				) .
				' ' .
				Xml::checkLabel(
					$this->msg( 'facetedcategory-not-only-match-exactly' )->text(), 'includeNotExactlyMatched',
					'includeNotExactlyMatched', $includeNotExactlyMatched, [] )
			)
		);
	}
}
