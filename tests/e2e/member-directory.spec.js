const { test, expect } = require( '@playwright/test' );

/**
 * The public member directory (`wporg/group-members` block, rendered on
 * the `/members/` page) should list seeded members with correct role
 * labels, with no login required.
 */
test.describe( 'member directory', () => {
	test( 'lists members with correct role labels', async ( { page } ) => {
		const response = await page.goto( 'members/' );
		expect( response.status() ).toBe( 200 );

		const grid = page.locator( '.wporg-group-members__grid' );
		await expect( grid ).toBeVisible();

		await expect( grid.locator( '.wporg-group-members__role--editor' ).first() ).toHaveText( /Organiser/ );
		await expect( grid.locator( '.wporg-group-members__role--author' ).first() ).toHaveText( /Event Organiser/ );
		await expect( grid.locator( '.wporg-group-members__role--subscriber' ).first() ).toHaveText( /Member/ );
	} );
} );
