const { test, expect } = require( '@playwright/test' );
const { login } = require( './utils/login' );

/**
 * The toolbar's + New → Event opens the front-end Create event form (#2027).
 *
 * It used to open the block editor, where an event with no venue shows a
 * grey "Title" and the venue picker is tucked away in the block's settings.
 * On a tablet it's the only way in that's in view: the home page's own
 * Create event button is screens down there.
 *
 * Runs as an Event Organizer (author), the narrowest role the form serves.
 * Requires the `eventorganiser7` / `password` test user, which no other spec
 * logs in as: this environment only supports one active session per user
 * (see utils/login.js).
 *
 * Follows the link's address rather than opening the menu. + New is a hover
 * menu, which is fiddly to drive and isn't what this covers.
 */
test.describe( 'toolbar + New → Event', () => {
	test( 'opens the Create event form on the home page', async ( { page } ) => {
		// Logging in leaves the page in wp-admin, so following the link from
		// there loads the home page.
		await login( page, 'eventorganiser7', 'password' );

		const link = page.locator( '#wp-admin-bar-new-gatherpress_event > a' );
		const href = await link.getAttribute( 'href' );
		expect( new URL( href ).hash ).toBe( '#create-event' );

		await page.goto( href );

		const dialog = page.getByRole( 'dialog', { name: 'Create event' } );
		await expect( dialog ).toBeVisible();

		// The fragment is dropped once read, so a reload doesn't reopen it.
		expect( new URL( page.url() ).hash ).toBe( '' );

		// Following the link again from the home page is a hash change, not a
		// page load, and has to open the form all the same.
		await dialog.getByRole( 'button', { name: 'Close', exact: true } ).click();
		await expect( dialog ).toBeHidden();

		await link.evaluate( ( anchor ) => anchor.click() );
		await expect( dialog ).toBeVisible();
	} );
} );
