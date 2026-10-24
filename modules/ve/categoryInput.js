// Let the category input of VisualEditor find faceted categories by their member,
// so that typing "politic" suggests "Job/Politician" too, and suggest facets while
// the input is empty.

const CategoryInput = ve.ui.MWCategoryInputWidget;
const coreGetLookupRequest = CategoryInput.prototype.getLookupRequest;
const coreGetLookupCacheDataFromResponse = CategoryInput.prototype.getLookupCacheDataFromResponse;
const coreGetCategoryWidgetFromName = CategoryInput.prototype.getCategoryWidgetFromName;
const coreGetLookupMenuOptionsFromData = CategoryInput.prototype.getLookupMenuOptionsFromData;
const corePopulateLookupMenu = CategoryInput.prototype.populateLookupMenu;
const coreOnLookupMenuChoose = CategoryInput.prototype.onLookupMenuChoose;

/** Common facets suggested while the input is empty */
const COMMON_FACETS = 12;

/**
 * @param {string} name Category name
 * @return {string|null} The facet, or null if the category has none
 */
function getFacet( name ) {
	const slash = name.indexOf( '/' );
	return slash > 0 && slash < name.length - 1 ? name.slice( 0, slash ) : null;
}

/**
 * @inheritdoc
 */
CategoryInput.prototype.populateLookupMenu = function () {
	if ( !this.facetEventsBound ) {
		// Suggest facets while the input is empty, once the editor clicks it or
		// presses the down arrow key, rather than as soon as the dialog focuses it
		this.$input.on( {
			click: () => this.suggestFacets(),
			keydown: ( e ) => {
				if ( e.key === 'ArrowDown' && !this.lookupMenu.isVisible() ) {
					this.suggestFacets();
				}
			},
			blur: () => {
				this.allowSuggestionsWhenEmpty = false;
			}
		} );
		this.facetEventsBound = true;
	}
	return corePopulateLookupMenu.call( this );
};

/**
 * Suggest facets if the input is empty.
 */
CategoryInput.prototype.suggestFacets = function () {
	this.allowSuggestionsWhenEmpty = true;
	if ( this.value === '' && !this.lookupMenu.isVisible() ) {
		corePopulateLookupMenu.call( this );
	}
};

/**
 * @inheritdoc
 */
CategoryInput.prototype.getLookupRequest = function () {
	if ( this.value === '' ) {
		const request = this.api.get( {
			action: 'query',
			list: 'categoryfacets',
			cflimit: COMMON_FACETS
		} );
		return request
			.then( ( data ) => ( { facets: ( data.query && data.query.categoryfacets ) || [] } ) )
			.promise( { abort: request.abort } );
	}
	const title = mw.Title.newFromText( this.value );
	const search = title && title.getNamespaceId() === mw.config.get( 'wgNamespaceIds' ).category ?
		title.getMainText() :
		this.value;
	const requests = [
		coreGetLookupRequest.call( this ),
		this.api.get( {
			action: 'query',
			list: 'facetedcategories',
			fcsearch: search,
			fclimit: 20
		} )
	];
	return $.when( ...requests )
		.then( ( core, faceted ) => ( { core: core[ 0 ], faceted: faceted[ 0 ] } ) )
		.promise( {
			abort: () => {
				requests.forEach( ( request ) => request.abort() );
			}
		} );
};

/**
 * @inheritdoc
 */
CategoryInput.prototype.getLookupCacheDataFromResponse = function ( data ) {
	if ( data.facets ) {
		return data;
	}
	const names = coreGetLookupCacheDataFromResponse.call( this, data.core );
	const faceted = ( data.faceted.query && data.faceted.query.facetedcategories ) || [];
	const linkCacheUpdate = {};
	this.facetedCategories = this.facetedCategories || {};

	const facetedNames = faceted.map( ( category ) => {
		const name = mw.Title.newFromText( category.title ).getMainText();
		this.facetedCategories[ name ] = category;
		linkCacheUpdate[ category.title ] = { missing: false };
		return name;
	} );
	ve.init.platform.linkCache.set( linkCacheUpdate );

	// Faceted categories first, as they are sorted by relevance
	return facetedNames.concat( names.filter( ( name ) => !facetedNames.includes( name ) ) );
};

/**
 * @inheritdoc
 */
CategoryInput.prototype.getCategoryWidgetFromName = function ( name ) {
	const widget = coreGetCategoryWidgetFromName.call( this, name );
	const category = this.facetedCategories && this.facetedCategories[ name ];
	if ( !category ) {
		return widget;
	}
	widget.setLabel( $( '<span>' ).addClass( 'ext-facetedcategory-ve-option' ).append(
		$( '<span>' ).append(
			$( '<span>' ).addClass( 'ext-facetedcategory-ve-facet' ).text( category.facet + ' / ' ),
			document.createTextNode( category.member )
		),
		$( '<span>' ).addClass( 'ext-facetedcategory-ve-pages' )
			.text( mw.language.convertNumber( category.pages ) )
	) );
	return widget;
};

/**
 * @inheritdoc
 */
CategoryInput.prototype.getLookupMenuOptionsFromData = function ( data ) {
	if ( !data.facets ) {
		const options = coreGetLookupMenuOptionsFromData.call( this, data );
		// "Facet/" alone, as after choosing a facet, is no category to create
		if ( /\/$/.test( this.value ) && options.length && options[ 0 ].getData() === 'newCategory' ) {
			options.splice( 0, 2 );
		}
		return options;
	}
	const pageFacets = [];
	this.categoryWidget.getCategories().forEach( ( name ) => {
		const facet = getFacet( name );
		if ( facet !== null && !pageFacets.includes( facet ) ) {
			pageFacets.push( facet );
		}
	} );
	const commonFacets = data.facets.map( ( facet ) => facet.facet )
		.filter( ( facet ) => !pageFacets.includes( facet ) );

	const items = [];
	[
		{ label: mw.msg( 'facetedcategory-ve-page-facets' ), facets: pageFacets },
		{ label: mw.msg( 'facetedcategory-ve-common-facets' ), facets: commonFacets }
	].forEach( ( section ) => {
		if ( !section.facets.length ) {
			return;
		}
		items.push( new OO.ui.MenuSectionOptionWidget( { label: section.label } ) );
		section.facets.forEach( ( facet ) => {
			items.push( new OO.ui.MenuOptionWidget( {
				data: { facet: facet },
				label: facet + '/'
			} ) );
		} );
	} );
	return items;
};

/**
 * @inheritdoc
 */
CategoryInput.prototype.onLookupMenuChoose = function ( item ) {
	const data = item.getData();
	if ( data && data.facet ) {
		// Suggest the members of the chosen facet, once the menu has closed
		this.setValue( data.facet + '/' );
		setTimeout( () => {
			this.focus();
			this.populateLookupMenu();
		} );
		return;
	}
	coreOnLookupMenuChoose.call( this, item );
};
