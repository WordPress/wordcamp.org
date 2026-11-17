const { test, expect } = require( '@playwright/test' );
const { login } = require( './utils/login' );

/**
 * The member directory (`wporg/group-members` block, rendered on the
 * `/members/` page) is for logged-in visitors only (#2304): people join a
 * group to hear about its events, not to be listed publicly.
 */
test.describe( 'member directory', () => {
	test( 'asks logged-out visitors to log in instead of listing members', async ( { page } ) => {
		const response = await page.goto( 'members/' );
		expect( response.status() ).toBe( 200 );

		await expect( page.locator( '.wporg-group-members__login' ) ).toBeVisible();
		await expect(
			page.locator( '.wporg-group-members__login' ).getByRole( 'link', { name: 'Log in' } )
		).toHaveAttribute( 'href', /wp-login\.php/ );
		await expect( page.locator( '.wporg-group-members__grid' ) ).toHaveCount( 0 );

		const rest = await page.request.get( 'wp-json/wporg-groups/v1/members' );
		expect( rest.status() ).toBe( 401 );
	} );

	test( 'lists members with correct role labels when logged in', async ( { page } ) => {
		await login( page, 'member1', 'password' );

		const response = await page.goto( 'members/' );
		expect( response.status() ).toBe( 200 );

		const grid = page.locator( '.wporg-group-members__grid' );
		await expect( grid ).toBeVisible();
		await expect( page.locator( '.wporg-group-members__login' ) ).toHaveCount( 0 );

		await expect( grid.locator( '.wporg-group-members__role--editor' ).first() ).toHaveText( /Organizer/ );
		await expect( grid.locator( '.wporg-group-members__role--author' ).first() ).toHaveText(
			/Event Organizer/
		);
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
