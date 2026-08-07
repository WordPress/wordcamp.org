const { test, expect } = require( '@playwright/test' );
const { login } = require( './utils/login' );

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

	test( 'keeps the leave action in the homepage sidebar', async ( { page } ) => {
		await login( page, 'member1', 'password' );
		await page.goto( '' );

		const sidebar = page.locator( '.groups-site-sidebar' );
		await expect( sidebar.getByRole( 'button', { name: 'Leave group' } ) ).toBeVisible();

		await page.goto( 'members/' );
		await expect( page.locator( '.groups-site-sidebar' ) ).toHaveCount( 0 );
		await expect( page.locator( '.wporg-group-membership' ) ).toHaveCount( 0 );
	} );
} );
