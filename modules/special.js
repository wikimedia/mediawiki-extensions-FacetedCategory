// Suggest categories in the box of Special:FacetedCategories as one types,
// finding faceted categories by their member too, so that typing "politic"
// suggests "Job/Politician".

/** Delay before asking for suggestions, in milliseconds */
const DELAY = 150;
/** Faceted categories to suggest */
const FACETED = 8;
/** Other categories to suggest */
const GENERAL = 4;

/**
 * @param {string} tag
 * @param {string} className
 * @param {string} text
 * @return {HTMLElement}
 */
function element( tag, className, text ) {
	const el = document.createElement( tag );
	el.className = className;
	el.textContent = text;
	return el;
}

/**
 * @param {Object} c Category with name, and facet, member and pages if faceted
 * @param {string} id
 * @return {HTMLElement}
 */
function makeOption( c, id ) {
	const option = document.createElement( 'li' );
	option.id = id;
	option.className = 'ext-facetedcategory-suggestion';
	option.setAttribute( 'role', 'option' );
	option.setAttribute( 'aria-selected', 'false' );
	option.dataset.name = c.name;
	const label = document.createElement( 'span' );
	if ( c.facet ) {
		label.append( element( 'span', 'ext-facetedcategory-chip-facet', c.facet + '/' ) );
	}
	label.append( element( 'b', '', c.facet ? c.member : c.name ) );
	option.append( label );
	if ( c.pages !== undefined ) {
		option.append( element(
			'span', 'ext-facetedcategory-count', mw.language.convertNumber( c.pages )
		) );
	}
	return option;
}

/**
 * @param {HTMLFormElement} form
 */
function enhance( form ) {
	const api = new mw.Api();
	const input = form.querySelector( '.ext-facetedcategory-conditions-input' );
	const listId = 'ext-facetedcategory-suggestions';
	const list = document.createElement( 'ul' );
	list.id = listId;
	list.className = 'ext-facetedcategory-suggestions';
	list.setAttribute( 'role', 'listbox' );
	list.hidden = true;
	form.append( list );
	let request = null;
	let timer = null;
	let selected = -1;

	input.setAttribute( 'role', 'combobox' );
	input.setAttribute( 'autocomplete', 'off' );
	input.setAttribute( 'aria-autocomplete', 'list' );
	input.setAttribute( 'aria-expanded', 'false' );
	input.setAttribute( 'aria-controls', listId );

	function close() {
		list.hidden = true;
		list.replaceChildren();
		input.setAttribute( 'aria-expanded', 'false' );
		input.removeAttribute( 'aria-activedescendant' );
		selected = -1;
	}

	/**
	 * @param {number} index
	 */
	function select( index ) {
		Array.prototype.forEach.call( list.children, ( child, i ) => {
			child.setAttribute( 'aria-selected', String( i === index ) );
		} );
		selected = index;
		if ( index < 0 ) {
			input.removeAttribute( 'aria-activedescendant' );
			return;
		}
		const option = list.children[ index ];
		input.setAttribute( 'aria-activedescendant', option.id );
		option.scrollIntoView( { block: 'nearest' } );
	}

	/**
	 * @param {string} name Category name, without the namespace
	 */
	function choose( name ) {
		input.value = name;
		close();
		form.submit();
	}

	/**
	 * @param {Object[]} categories
	 */
	function show( categories ) {
		if ( !categories.length ) {
			close();
			return;
		}
		list.replaceChildren( ...categories.map( ( c, i ) => makeOption( c, listId + '-' + i ) ) );
		list.hidden = false;
		input.setAttribute( 'aria-expanded', 'true' );
		select( -1 );
	}

	function suggest() {
		const search = input.value.trim();
		if ( request ) {
			request.abort();
			request = null;
		}
		if ( search === '' ) {
			close();
			return;
		}
		request = api.get( {
			action: 'query',
			list: [ 'facetedcategories', 'allcategories' ],
			fcsearch: search,
			fclimit: FACETED,
			acprefix: search,
			aclimit: GENERAL,
			formatversion: 2
		} );
		request.then( ( data ) => {
			const query = data.query || {};
			const seen = new Set();
			const categories = [];
			( query.facetedcategories || [] ).forEach( ( c ) => {
				const name = c.facet + '/' + c.member;
				seen.add( name );
				categories.push( { name: name, facet: c.facet, member: c.member, pages: c.pages } );
			} );
			( query.allcategories || [] ).forEach( ( c ) => {
				if ( !seen.has( c.category ) ) {
					categories.push( { name: c.category } );
				}
			} );
			show( categories );
		} );
	}

	input.addEventListener( 'input', () => {
		clearTimeout( timer );
		timer = setTimeout( suggest, DELAY );
	} );
	input.addEventListener( 'keydown', ( e ) => {
		const count = list.children.length;
		if ( e.key === 'ArrowDown' && count ) {
			e.preventDefault();
			select( ( selected + 1 ) % count );
		} else if ( e.key === 'ArrowUp' && count ) {
			e.preventDefault();
			select( selected <= 0 ? count - 1 : selected - 1 );
		} else if ( e.key === 'Enter' && selected >= 0 ) {
			e.preventDefault();
			choose( list.children[ selected ].dataset.name );
		} else if ( e.key === 'Escape' ) {
			close();
		} else if ( e.key === 'Backspace' && input.value === '' ) {
			// Remove the last category
			const removes = form.querySelectorAll( '.ext-facetedcategory-chip-remove' );
			if ( removes.length ) {
				location.href = removes[ removes.length - 1 ].href;
			}
		}
	} );
	input.addEventListener( 'blur', close );
	list.addEventListener( 'mousedown', ( e ) => {
		// Keep the focus in the input
		e.preventDefault();
		const option = e.target.closest( '.ext-facetedcategory-suggestion' );
		if ( option ) {
			choose( option.dataset.name );
		}
	} );
}

Array.prototype.forEach.call( document.querySelectorAll( '.ext-facetedcategory-conditions' ), enhance );
