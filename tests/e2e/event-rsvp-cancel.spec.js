const { test, expect } = require( '@playwright/test' );
const { login } = require( './utils/login' );

/**
 * Withdrawing from an event without hunting for the action.
 *
 * Cancelling used to be reachable only by pressing the "Attending" button —
 * which reports your status rather than offering an action — and then finding
 * "Cancel RSVP" in the modal that opened (#2058). The event page now carries
 * the control itself.
 *
 * It ships in the DOM and hidden rather than being rendered on demand,
 * because the RSVP button changes status without a reload. So the thing worth
 * testing in a browser is the part PHPUnit can't see: that the Interactivity
 * API reveals and hides it as the status changes, and that pressing it
 * actually withdraws.
 *
 * Requires the `organiser6` / `password` editor-tier test user. One account
 * per test: this environment only supports a single active session per user
 * and specs run concurrently under `fullyParallel` (see utils/login.js).
 */
test.describe( 'cancelling an RSVP', () => {
	// Far enough out to stay clear of the front page's capped "Upcoming events"
	// ranking that other specs' events compete for (see utils/pin-event-far-future.js).
	const DATE = '2099-03-04'; // 2099-03-04 is a Wednesday.
	const START = '12:00';

	test( 'the event page offers it while attending, and it withdraws', async ( { page } ) => {
		test.slow(); // A cold boot of the inline block editor behind the modal.

		await login( page, 'organiser6', 'password' );

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
		await modal.getByRole( 'button', { name: 'Create event' } ).click();
		await page.waitForURL( /\/event\//, { timeout: 30000 } );

		const rsvpButton = page.locator( '.wp-block-wporg-event-rsvp .wp-block-button__link' );
		const cancel = page.locator( '.wporg-event-rsvp__cancel' );

		// Publishing an event doesn't RSVP the organizer to it, so this starts
		// from the same state any member would.
		await expect( rsvpButton ).toHaveText( /^RSVP$/ );
		await expect( cancel ).toHaveCount( 1 );
		await expect( cancel ).toBeHidden();

		await rsvpButton.click();

		await expect( rsvpButton ).toHaveText( /Attending/ );
		await expect( cancel ).toBeVisible();
		await expect( cancel ).toHaveText( 'Cancel RSVP' );

		// A real button, not a styled span: it has to be reachable by keyboard
		// and announced as an action.
		expect( await cancel.evaluate( ( element ) => element.tagName ) ).toBe( 'BUTTON' );

		await cancel.click();

		await expect( rsvpButton ).toHaveText( /^RSVP$/ );
		await expect( cancel ).toBeHidden();
		await expect( page.getByRole( 'status' ) ).toHaveText( /cancelled/i );
	} );
} );
