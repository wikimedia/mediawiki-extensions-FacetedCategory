// Make the list of category links collapsible

function createToggleButton() {
	const catlinksToggle = document.createElement( 'button' );
	catlinksToggle.classList.add( 'fw-catlinks-toggle' );

	return catlinksToggle;
}

function main() {
	const directCats = mw.config.get( 'wgDirectCategories' );

	const catlinks = document.querySelector( '#mw-normal-catlinks' );
	const catlinkItems = document.querySelectorAll(
		'#mw-normal-catlinks li, #mw-hidden-catlinks li'
	);

	if ( !catlinks || directCats.length === catlinkItems.length ) {
		return;
	}
	for ( let i = 0, len = catlinkItems.length; i < len; i++ ) {
		if ( directCats.indexOf( catlinkItems[ i ].innerText ) === -1 ) {
			catlinkItems[ i ].classList.add( 'collapsible' );
		}
	}

	const catlinksToggle = createToggleButton();
	document.querySelector( '#mw-normal-catlinks ul' ).append( catlinksToggle );
	catlinksToggle.addEventListener( 'click', () => {
		catlinks.classList.toggle( 'collapsed' );
	} );
}

main();
