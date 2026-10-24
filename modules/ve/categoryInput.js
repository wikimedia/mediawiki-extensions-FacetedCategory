// Let the category input of VisualEditor find faceted categories by their member,
// so that typing "politic" suggests "Job/Politician" too.

const CategoryInput = ve.ui.MWCategoryInputWidget;
const coreGetLookupRequest = CategoryInput.prototype.getLookupRequest;
const coreGetLookupCacheDataFromResponse = CategoryInput.prototype.getLookupCacheDataFromResponse;
const coreGetCategoryWidgetFromName = CategoryInput.prototype.getCategoryWidgetFromName;

/**
 * @inheritdoc
 */
CategoryInput.prototype.getLookupRequest = function () {
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
