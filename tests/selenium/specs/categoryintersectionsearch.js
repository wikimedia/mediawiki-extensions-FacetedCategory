import { createApiClient } from 'wdio-mediawiki/Api.js';
import CategoryIntersectionSearchPage from '../pageobjects/categoryintersectionsearch.page.js';

describe( 'Special:CategoryIntersectionSearch', () => {
	before( async () => {
		const apiClient = await createApiClient();
		await apiClient.edit(
			'Categorized',
			'[[Category:A/B]][[Category:C/D]][[Category:C/Foo bar]]'
		);
	} );

	it( 'shows a page if valid subpage is given', async () => {
		await CategoryIntersectionSearchPage.open( 'A/B, C/D' );

		await expect( await CategoryIntersectionSearchPage.pages ).toHaveText( 'Categorized' );
	} );

	it( 'shows a page if the category contains a space', async () => {
		await CategoryIntersectionSearchPage.open( 'A/B, C/Foo bar' );

		await expect( await CategoryIntersectionSearchPage.pages ).toHaveText( 'Categorized' );
	} );
} );
