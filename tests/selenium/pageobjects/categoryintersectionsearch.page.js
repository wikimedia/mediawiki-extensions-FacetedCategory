'use strict';

const Page = require( 'wdio-mediawiki/Page' );

class CategoryIntersectionSearchPage extends Page {
	get pages() {
		return $( '#mw-pages li' );
	}

	async open( subPage = false ) {
		let title = 'Special:CategoryIntersectionSearch';
		if ( subPage ) {
			title += '/' + subPage;
		}
		return super.openTitle( title );
	}
}

module.exports = new CategoryIntersectionSearchPage();
