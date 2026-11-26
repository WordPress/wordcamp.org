const { test, expect } = require( '@playwright/test' );
const { STORAGE_STATE } = require( './utils/accounts' );

/**
 * Withdrawing from an event without hunting for the action.
 *
 * Once you RSVP, the button gives way to a status panel with Cancel RSVP
 * beside it (#2058). Both ship in the DOM and the Interactivity API swaps
 * them without a reload, which is the part PHPUnit can't see.
 *
 * Requires the `organiser1` / `password` editor-tier test user.
 */
test.describe( 'cancelling an RSVP', () => {
	test.use( { storageState: STORAGE_STATE.organiser1 } );

	// Far enough out to stay clear of the front page's capped "Upcoming events"
	// ranking that other specs' events compete for (see utils/pin-event-far-future.js).
	const DATE = '2099-03-04'; // 2099-03-04 is a Wednesday.
	const START = '12:00';

	test( 'the event page offers it while attending, and it withdraws', async ( { page } ) => {
		test.slow(); // A cold boot of the inline block editor behind the modal.

		// Publish an event of our own: CI seeds none, and an event another
		// spec created could already carry RSVPs that aren't ours.
		const title = `Cancel RSVP E2E ${ Date.now() }`;
		await page.goto( '' );
		await page.getByRole( 'button', { name: '+ Create event', exact: true } ).click();

		const modal = page.locator( '.wporg-groups-event-modal' );
		await modal.getByLabel( 'Event title' ).fill( title );
		await modal.getByLabel( 'Date', { exact: true } ).fill( DATE );
		await modal.getByLabel( 'Start time' ).fill( START );
		await modal.getByLabel( 'Duration' ).selectOption( { label: '1 hour' } );
		// Publishing RSVPs the organizer unless they untick this (#2255). Start
		// from the state any member would see.
		await modal.getByLabel( "I'm attending" ).uncheck();
		await modal.getByRole( 'button', { name: 'Create event' } ).click();
		await page.waitForURL( /\/event\//, { timeout: 30000 } );

		const rsvpButton = page.locator( '.wp-block-wporg-event-rsvp .wporg-event-rsvp__button' );
		const status = page.locator( '.wporg-event-rsvp__status' );
		const cancel = status.getByRole( 'button', { name: 'Cancel RSVP' } );

		// Unticked "I'm attending" leaves the organizer without an RSVP.
		await expect( rsvpButton ).toHaveText( /^RSVP$/ );
		await expect( status ).toBeHidden();

		await rsvpButton.click();

		await expect( rsvpButton ).toBeHidden();
		await expect( status ).toContainText( "You're attending" );
		await expect( cancel ).toBeVisible();

		// No capacity, so nobody else is waiting for the seat: no confirmation.
		await cancel.click();

		await expect( rsvpButton ).toHaveText( /^RSVP$/ );
		await expect( status ).toBeHidden();
		await expect( page.getByRole( 'status' ) ).toHaveText( /canceled/i );
	} );
} );
