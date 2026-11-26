const { test, expect } = require( '@playwright/test' );
const { STORAGE_STATE } = require( './utils/accounts' );
const { siteOrigin } = require( './utils/env' );

/**
 * The full RSVP state machine, and the gate in front of it.
 *
 * event-rsvp-cancel.spec.js covers the outbound leg (RSVP → attending →
 * cancelled). What nothing covered is the return leg: RSVPing *again* after
 * cancelling. That path is distinct in the code — after a cancellation the
 * context holds `currentUserStatus === 'not_attending'`, not the
 * `'no_status'` a fresh visitor has, and GatherPress already has an RSVP
 * comment for this user to update rather than create. A regression there
 * would leave a member who changed their mind unable to come back, which is
 * exactly the kind of thing a migrated group would hit in its first week.
 *
 * The second test covers the anonymous gate. `rsvp_permissions_check()`
 * requires `is_user_logged_in()`, so there is no anonymous RSVP to exercise —
 * the thing worth asserting is that a logged-out visitor is sent to log in
 * rather than shown a control that will fail, and that the route itself
 * refuses a direct POST regardless of what the UI offers.
 *
 * Requires the `organiser1` / `password` editor-tier test user.
 */
test.describe( 'RSVP lifecycle', () => {
	test.use( { storageState: STORAGE_STATE.organiser1 } );

	// Far enough out to stay clear of the front page's capped "Upcoming events"
	// ranking that other specs' events compete for (see utils/pin-event-far-future.js).
	const DATE = '2099-05-06'; // 2099-05-06 is a Wednesday.
	const START = '12:00';

	/**
	 * Publishes an event through the front-end modal and lands on its page.
	 *
	 * Each test publishes its own rather than reading whatever the archive
	 * holds: CI seeds no events, and one another spec created could already
	 * carry RSVPs that aren't ours.
	 *
	 * @param {import('@playwright/test').Page} page
	 * @param {string}                          title
	 * @return {Promise<string>} The published event's URL.
	 */
	async function publishEvent( page, title ) {
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

		return page.url();
	}

	test( 'a member can attend, cancel, and attend again', async ( { page } ) => {
		test.slow(); // A cold boot of the inline block editor behind the modal.

		await publishEvent( page, `RSVP Lifecycle E2E ${ Date.now() }` );

		const rsvpButton = page.locator( '.wp-block-wporg-event-rsvp .wporg-event-rsvp__button' );
		const status = page.locator( '.wporg-event-rsvp__status' );
		const cancel = status.getByRole( 'button', { name: 'Cancel RSVP' } );
		const count = page.locator( '.wporg-event-rsvp__count' );
		const avatars = page.locator( '.wporg-event-rsvp__avatar' );

		// Unticked "I'm attending" leaves the organizer without an RSVP.
		await expect( rsvpButton ).toHaveText( /^RSVP$/ );
		await expect( count ).toHaveText( 'Be the first to RSVP' );

		// Attend. The count line is person-relative rather than numeric — one
		// attendee who is you reads "First one in", not "1 going" — so it is
		// worth following across the whole lifecycle, not just at the end.
		await rsvpButton.click();
		await expect( status ).toContainText( "You're attending" );
		await expect( cancel ).toBeVisible();
		await expect( count ).toHaveText( 'First one in' );

		// Cancel.
		await cancel.click();
		await expect( rsvpButton ).toHaveText( /^RSVP$/ );
		await expect( cancel ).toBeHidden();
		await expect( count ).toHaveText( 'Be the first to RSVP' );

		// Attend again. This is the leg that had no coverage: the button has
		// to submit rather than no-op now that the stored status is
		// 'not_attending' rather than absent.
		await rsvpButton.click();
		await expect( status ).toContainText( "You're attending" );
		await expect( cancel ).toBeVisible();
		await expect( page.getByRole( 'status' ) ).toHaveText( /attending/i );

		// The roster has to agree with the button. A status that round-trips
		// visually while the attendee list stays empty is the failure this
		// catches.
		await expect( count ).toHaveText( 'First one in' );
		await expect( avatars ).toHaveCount( 1 );

		// And it has to survive a reload — until now every assertion above
		// read Interactivity state that never left the browser, so a re-RSVP
		// that updated the UI without persisting would still have passed.
		await page.reload();
		await expect( status ).toContainText( "You're attending" );
		await expect( count ).toHaveText( 'First one in' );
		await expect( avatars ).toHaveCount( 1 );
	} );

	test( 'an anonymous visitor is sent to log in, and the route refuses them', async ( { page } ) => {
		test.slow();

		const eventUrl = await publishEvent( page, `RSVP Anon E2E ${ Date.now() }` );
		const eventId = await page.locator( 'body' ).evaluate( () => {
			return document.body.className.match( /postid-(\d+)/ )?.[ 1 ] ?? '';
		} );

		expect( eventId, 'the event page should expose its post ID on the body class' ).not.toBe( '' );

		// Drop the session's cookies rather than opening a second context, so
		// this is the same page an anonymous visitor lands on.
		await page.context().clearCookies();
		await page.goto( eventUrl );

		const rsvpButton = page.locator( '.wp-block-wporg-event-rsvp .wp-block-button__link' );

		// Not "RSVP": a logged-out visitor isn't a member either, so the
		// button states both steps it's about to take.
		await expect( rsvpButton ).toHaveText( /Join & RSVP/ );
		await expect( page.locator( '.wporg-event-rsvp__cancel' ) ).toHaveCount( 0 );

		await rsvpButton.click();
		await page.waitForURL( /wp-login\.php/ );
		expect( decodeURIComponent( page.url() ) ).toContain( eventUrl );

		// The UI gate is a convenience; the route is the actual boundary.
		// PHPUnit asserts the status (tests/test-rest-authorization.php) —
		// this confirms it holds over real HTTP, unauthenticated, against a
		// running site rather than a dispatched request.
		const response = await page.request.post(
			`${ siteOrigin() }/group/sunshine-coast-qld/wp-json/wporg-groups/v1/event/${ eventId }/rsvp`,
			{ data: { status: 'attending' } }
		);

		// `rsvp_permissions_check()` returns a bare `false`, which WordPress
		// renders as `rest_forbidden` — carrying a 401 rather than a 403
		// because the request is unauthenticated.
		expect( response.status() ).toBe( 401 );
		expect( ( await response.json() ).code ).toBe( 'rest_forbidden' );
	} );
} );
