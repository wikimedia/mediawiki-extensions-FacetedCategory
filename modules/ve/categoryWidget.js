// Group the categories in the category list of VisualEditor like below the page:
// by facet, then the general and the hidden categories.

const CategoryWidget = ve.ui.MWCategoryWidget;
const coreAddItems = CategoryWidget.prototype.addItems;
const coreRemoveItems = CategoryWidget.prototype.removeItems;
const coreClearItems = CategoryWidget.prototype.clearItems;

/**
 * @inheritdoc
 */
CategoryWidget.prototype.addItems = function ( items, index ) {
	return coreAddItems.call( this, items, index ).then( () => {
		this.groupItems();
	} );
};

/**
 * @inheritdoc
 */
CategoryWidget.prototype.removeItems = function ( items ) {
	const result = coreRemoveItems.call( this, items );
	this.groupItems();
	return result;
};

/**
 * @inheritdoc
 */
CategoryWidget.prototype.clearItems = function () {
	const result = coreClearItems.call( this );
	this.groupItems();
	return result;
};

/**
 * Move the category items into rows by facet. The items cannot be dragged
 * any more, as dragging needs them side by side in one list.
 */
CategoryWidget.prototype.groupItems = function () {
	if ( !this.$facetGroups ) {
		this.$facetGroups = $( '<dl>' ).addClass( 'ext-facetedcategory-ve-groups' );
		this.$group.before( this.$facetGroups );
	}
	const facets = new Map();
	const general = [];
	const hidden = [];
	this.items.forEach( ( item ) => {
		item.toggleDraggable( false );
		const slash = item.value.indexOf( '/' );
		if ( item.isHidden ) {
			hidden.push( item );
		} else if ( slash > 0 && slash < item.value.length - 1 ) {
			const facet = item.value.slice( 0, slash );
			if ( !facets.has( facet ) ) {
				facets.set( facet, [] );
			}
			facets.get( facet ).push( item );
			if ( item.getLabel() === item.value ) {
				item.setLabel( item.value.slice( slash + 1 ) );
			}
		} else {
			general.push( item );
		}
	} );

	const rows = Array.from( facets );
	if ( general.length ) {
		rows.push( [ mw.msg( 'facetedcategory-general-categories' ), general ] );
	}
	if ( hidden.length ) {
		rows.push( [ mw.msg( 'hidden-categories', hidden.length ), hidden ] );
	}
	// Keep the items attached to the document while the rows are rebuilt
	this.$facetGroups.children().detach();
	rows.forEach( ( [ label, items ] ) => {
		this.$facetGroups.append(
			$( '<dt>' ).text( label ),
			$( '<dd>' ).append( items.map( ( item ) => item.$element ) )
		);
	} );
	this.$facetGroups.toggle( rows.length > 0 );
};

/**
 * @inheritdoc
 */
CategoryWidget.prototype.fitInput = function () {
	// The input is alone below the rows, so there is no space beside an item to fit
	this.input.$element.css( 'width', '' );
};
