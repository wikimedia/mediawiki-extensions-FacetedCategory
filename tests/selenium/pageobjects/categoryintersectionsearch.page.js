import Page from 'wdio-mediawiki/Page.js';

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

export default new CategoryIntersectionSearchPage();
