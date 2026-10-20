const { test, expect } = require( '@playwright/test' );

test.describe( 'visual and source order', () => {
	test( 'homepage columns follow the same order in the DOM and layout', async ( { page } ) => {
		await page.setViewportSize( { width: 1280, height: 900 } );
		await page.goto( '', { waitUntil: 'domcontentloaded' } );

		const main = page.locator( '.groups-site-main-column-wrapper' );
		const sidebar = page.locator( '.groups-site-sidebar-column' );

		await expect( main ).toHaveCount( 1 );
		await expect( sidebar ).toHaveCount( 1 );

		const sourceOrder = await page.evaluate( () => {
			const mainColumn = document.querySelector( '.groups-site-main-column-wrapper' );
			const sidebarColumn = document.querySelector( '.groups-site-sidebar-column' );

			return mainColumn.nextElementSibling === sidebarColumn;
		} );
		expect( sourceOrder ).toBe( true );

		const desktopMainBox = await main.boundingBox();
		const desktopSidebarBox = await sidebar.boundingBox();
		expect( desktopMainBox.x ).toBeLessThan( desktopSidebarBox.x );

		await page.setViewportSize( { width: 375, height: 900 } );
		const mobileMainBox = await main.boundingBox();
		const mobileSidebarBox = await sidebar.boundingBox();
		expect( mobileMainBox.y ).toBeLessThan( mobileSidebarBox.y );
	} );
} );
