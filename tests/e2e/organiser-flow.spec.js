const { test, expect } = require( '@playwright/test' );
const { demoBeat, demoUse, getRestNonce, installDemoCursor, loginForDemo } = require( './utils/demo' );

test.use( demoUse );

const eventTitle = 'CFT Demo — Online WordPress Meetup';

/**
 * Remove prior copies of the stable demo event through the public REST API so
 * repeated local recordings stay deterministic.
 *
 * @param {import('@playwright/test').Page} page
 */
async function removePriorDemoEvents( page ) {
	const nonce = await getRestNonce( page );
	const headers = { 'X-WP-Nonce': nonce };
	const response = await page.request.get(
		`wp-json/wp/v2/gatherpress_events?context=edit&status=any&per_page=100&search=${ encodeURIComponent(
			eventTitle
		) }`,
		{ headers }
	);

	if ( ! response.ok() ) {
		throw new Error( 'Could not load prior demo events.' );
	}

	for ( const event of await response.json() ) {
		await page.request.delete( `wp-json/wp/v2/gatherpress_events/${ event.id }?force=true`, {
			headers,
		} );
	}
}

test.describe( 'organiser journey', () => {
	test( 'can create an online event and message its audience', async ( { page } ) => {
		test.slow();
		await loginForDemo( page, 'grouptestorganizer', 'password' );
		await removePriorDemoEvents( page );
		await installDemoCursor( page );

		await page.goto( '', { waitUntil: 'domcontentloaded' } );
		const settings = page.getByRole( 'button', { name: 'Settings' } );
		await expect( settings ).toBeVisible();
		await demoBeat( page, 650 );

		await settings.click();
		const groupSettings = page.getByRole( 'dialog', { name: 'WordPress Sunshine Coast' } );
		await expect( groupSettings ).toBeVisible();
		await expect( groupSettings.getByText( 'Manage your group events.' ) ).toBeVisible();
		await demoBeat( page, 750 );

		await groupSettings.getByRole( 'button', { name: '+ Create event' } ).click();
		await expect( groupSettings.getByLabel( 'Event title' ) ).toBeVisible();
		await demoBeat( page, 500 );

		await groupSettings.getByLabel( 'Event title' ).fill( eventTitle );
		await groupSettings.getByLabel( 'Date' ).fill( '2099-10-20' );
		await groupSettings.getByLabel( 'Start time' ).fill( '18:00' );
		await groupSettings.getByLabel( 'Duration' ).selectOption( '60' );
		await groupSettings.getByRole( 'checkbox', { name: 'This is an online event' } ).check();
		await groupSettings.getByLabel( 'Online event link' ).fill( 'https://wordpress.org/' );
		await demoBeat( page, 850 );

		await groupSettings.getByRole( 'button', { name: 'Create event', exact: true } ).click();
		await expect( page.getByRole( 'heading', { name: eventTitle } ) ).toBeVisible( { timeout: 20000 } );
		await expect( page.getByRole( 'button', { name: 'Message attendees' } ) ).toBeVisible();
		await demoBeat( page, 900 );

		await page.getByRole( 'button', { name: 'Message attendees' } ).click();
		const attendees = page.getByRole( 'dialog', { name: 'Message event attendees' } );
		await expect( attendees ).toBeVisible();
		await expect( attendees.getByRole( 'checkbox', { name: 'Attending', exact: true } ) ).toBeChecked();
		await demoBeat( page, 800 );
		await attendees.getByRole( 'button', { name: 'Close' } ).click();

		await page.getByRole( 'button', { name: 'Message all members' } ).click();
		const allMembers = page.getByRole( 'dialog', { name: 'Message all members' } );
		await expect( allMembers ).toBeVisible();
		await allMembers.getByLabel( 'Message' ).fill( 'Our next WordPress meetup is ready — see you online!' );
		await demoBeat( page, 650 );
		await allMembers.getByRole( 'button', { name: 'Send message' } ).click();
		await expect( allMembers.getByText( 'Your message has been scheduled for delivery.' ) ).toBeVisible();
		await demoBeat( page, 1000 );
	} );
} );
