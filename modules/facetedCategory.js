// Group the list of category links by facet.
//
// A category named "Facet/Member" is shown as "Member" in the row of "Facet".
// Categories which the page does not add directly, but which come from a parent
// category, are shown after the direct ones in parentheses.

/**
 * @param {string} name Category name
 * @return {{facet: string|null, member: string}}
 */
function splitName( name ) {
	const slash = name.indexOf( '/' );
	if ( slash <= 0 || slash === name.length - 1 ) {
		return { facet: null, member: name };
	}
	return {
		facet: name.slice( 0, slash ),
		member: name.slice( slash + 1 )
	};
}

/**
 * @param {Array} entries
 * @return {HTMLUListElement}
 */
function createList( entries ) {
	const list = document.createElement( 'ul' );
	entries.forEach( ( entry ) => {
		const item = document.createElement( 'li' );
		const link = entry.link.cloneNode( true );
		link.textContent = entry.member;
		item.append( link );
		list.append( item );
	} );
	return list;
}

/**
 * @param {string} label
 * @param {Array} entries
 * @return {DocumentFragment}
 */
function createRow( label, entries ) {
	const row = document.createDocumentFragment();
	const term = document.createElement( 'dt' );
	const description = document.createElement( 'dd' );
	term.textContent = label;
	const direct = entries.filter( ( entry ) => !entry.inferred );
	const inferred = entries.filter( ( entry ) => entry.inferred );
	if ( direct.length ) {
		description.append( createList( direct ) );
	}
	// Categories which come from a parent category, in parentheses
	if ( inferred.length ) {
		const wrapper = document.createElement( 'span' );
		wrapper.className = 'ext-facetedcategory-inferred';
		wrapper.append(
			mw.msg( 'parentheses-start' ),
			createList( inferred ),
			mw.msg( 'parentheses-end' )
		);
		description.append( wrapper );
	}
	row.append( term, description );
	return row;
}

/**
 * List the category combinations suggested for the page.
 *
 * @return {HTMLElement|null}
 */
function createCombinations() {
	const combinations = mw.config.get( 'wgFacetedCategoryCombinations' );
	if ( !Array.isArray( combinations ) || !combinations.length ) {
		return null;
	}
	const list = document.createElement( 'ul' );
	combinations.forEach( ( combination ) => {
		const link = document.createElement( 'a' );
		link.href = mw.util.getUrl(
			'Special:CategoryIntersectionSearch/' + combination.categories.join( ', ' )
		);
		link.title = combination.categories.join( ', ' );
		// A computed combination has no name, so show its members
		link.textContent = combination.name ||
			combination.categories.map( ( name ) => splitName( name ).member ).join( ' · ' );
		const count = document.createElement( 'span' );
		count.className = 'ext-facetedcategory-combination-count';
		count.textContent = mw.language.convertNumber( combination.count );
		const item = document.createElement( 'li' );
		item.append( link, ' ', count );
		list.append( item );
	} );

	const heading = document.createElement( 'a' );
	heading.href = mw.util.getUrl( 'Special:CategoryIntersectionSearch' );
	heading.textContent = mw.msg( 'facetedcategory-combinations' );
	const container = document.createElement( 'div' );
	container.id = 'ext-facetedcategory-combinations';
	container.className = 'ext-facetedcategory-combinations';
	container.append( heading, mw.msg( 'colon-separator' ), list );
	return container;
}

/**
 * @param {HTMLElement} catlinks
 */
function groupCategories( catlinks ) {
	const normal = catlinks.querySelector( '#mw-normal-catlinks' );
	const list = normal && normal.querySelector( ':scope > ul' );
	if ( !list ) {
		return;
	}
	const direct = mw.config.get( 'wgDirectCategories' );
	const facets = new Map();

	Array.prototype.forEach.call( list.querySelectorAll( ':scope > li' ), ( item ) => {
		const link = item.querySelector( 'a' );
		if ( !link ) {
			return;
		}
		const name = link.textContent;
		const parts = splitName( name );
		if ( parts.facet === null ) {
			return;
		}
		if ( !facets.has( parts.facet ) ) {
			facets.set( parts.facet, [] );
		}
		facets.get( parts.facet ).push( {
			link: link,
			member: parts.member,
			inferred: Array.isArray( direct ) && !direct.includes( name )
		} );
		// Leave only the categories without a facet in the core list
		item.remove();
	} );

	if ( !facets.size ) {
		return;
	}

	// Facets which the page sets directly first, then the inferred-only ones
	const facetNames = Array.from( facets.keys() );
	const hasDirect = ( facet ) => facets.get( facet ).some( ( entry ) => !entry.inferred );
	const groups = document.createElement( 'dl' );
	facetNames.filter( hasDirect )
		.concat( facetNames.filter( ( facet ) => !hasDirect( facet ) ) )
		.forEach( ( facet ) => {
			groups.append( createRow( facet, facets.get( facet ) ) );
		} );

	const heading = document.createElement( 'a' );
	heading.href = mw.util.getUrl( 'Special:FacetedCategories' );
	heading.textContent = mw.msg( 'facetedcategory-faceted-categories' );
	const faceted = document.createElement( 'div' );
	faceted.id = 'ext-facetedcategory-catlinks';
	faceted.className = 'ext-facetedcategory-catlinks';
	faceted.append( heading, mw.msg( 'colon-separator' ), groups );
	normal.before( faceted );

	const combinations = createCombinations();
	if ( combinations ) {
		faceted.after( combinations );
	}

	// Hide the core list when every category has a facet
	if ( !list.querySelector( 'li' ) ) {
		normal.hidden = true;
		return;
	}
	const normalHeading = normal.querySelector( ':scope > a' );
	if ( normalHeading ) {
		normalHeading.textContent = mw.msg( 'facetedcategory-general-categories' );
	}
}

mw.hook( 'wikipage.categories' ).add( ( $catlinks ) => {
	if ( $catlinks && $catlinks[ 0 ] ) {
		groupCategories( $catlinks[ 0 ] );
	}
} );
