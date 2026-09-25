const { test, expect } = require( '@playwright/test' );
const { login } = require( './utils/login' );

/**
 * Creating a venue, reusing it on a second event, and editing it afterwards.
 *
 * PHPUnit covers where a new venue's address is stored
 * (tests/test-rest.php — `gatherpress_address` meta rather than
 * `post_content`, so GatherPress's geocode handler picks it up). What it
 * cannot cover is the part that decides whether a group ends up with one
 * venue or a pile of near-duplicates: `resolve_venue_id()`
 * (mu-plugins/wporg-groups-frontend/inc/rest.php) reuses a venue only when
 * the form sends back a `venue_id`, and creates a brand new post whenever it
 * receives a name instead. There is no dedupe on the name.
 *
 * So reuse is entirely a question of whether `VenueField` round-trips the ID
 * through the picker, which is a browser concern. This spec asserts the
 * second event attaches to the *same* venue rather than a copy of it — the
 * failure mode a Meetup import would otherwise multiply across every event at
 * a group's regular venue (#1863, #1788).
 *
 * Everything is asserted through the organiser-facing UI rather than by
 * reading the REST collection directly: `wp/v2/gatherpress_venues` needs an
 * `X-WP-Nonce` for cookie auth, which isn't localized on the event page, and
 * the Venues tab is the surface this is meant to cover anyway.
 *
 * Requires the `organiser11` / `password` editor-tier test user. One account
 * per test: this environment only supports a single active session per user
 * and specs run concurrently under `fullyParallel` (see utils/login.js).
 */
test.describe( 'venue lifecycle', () => {
	// Far enough out to stay clear of the front page's capped "Upcoming events"
	// ranking that other specs' events compete for (see utils/pin-event-far-future.js).
	const DATE = '2099-07-01'; // 2099-07-01 is a Wednesday.
	const START = '12:00';

	/**
	 * Opens the front-end create-event modal and fills the date fields.
	 *
	 * @param {import('@playwright/test').Page} page
	 * @param {string}                          title
	 * @return {Promise<import('@playwright/test').Locator>} The open modal.
	 */
	async function openEventModal( page, title ) {
		await page.goto( '' );
		await page.getByRole( 'button', { name: '+ Create event', exact: true } ).click();

		const modal = page.locator( '.wporg-groups-event-modal' );
		await modal.getByLabel( 'Event title' ).fill( title );
		await modal.getByLabel( 'Date', { exact: true } ).fill( DATE );
		await modal.getByLabel( 'Start time' ).fill( START );
		await modal.getByLabel( 'Duration' ).selectOption( { label: '1 hour' } );

		return modal;
	}

	/**
	 * Opens the group settings modal on the Venues tab.
	 *
	 * @param {import('@playwright/test').Page} page
	 * @return {Promise<import('@playwright/test').Locator>} The open dialog.
	 */
	async function openVenuesTab( page ) {
		await page.goto( '' );
		await page.locator( '[data-wporg-settings-open]' ).click();

		const dialog = page.getByRole( 'dialog' );
		await dialog.getByRole( 'tab', { name: 'Venues' } ).click();

		return dialog;
	}

	test( 'a venue is created once, reused by ID, and stays editable', async ( { page } ) => {
		test.slow(); // Several cold boots of the inline block editor behind the modal.

		await login( page, 'organiser11', 'password' );

		// Unique per run: these specs share a site and CI reruns the suite
		// against the same database.
		const venueName = `Venue Lifecycle Hall ${ Date.now() }`;
		const originalAddress = '1 Original Street, Testville';
		const updatedAddress = '2 Updated Avenue, Testville';

		// ---- Create, inline from the event modal ----------------------
		const firstModal = await openEventModal( page, `${ venueName } Event One` );
		await firstModal.getByLabel( 'Venue' ).selectOption( '__new__' );

		const editor = page.locator( '.wporg-groups-venue-editor' );
		await editor.getByLabel( 'Venue name' ).fill( venueName );
		await editor.getByLabel( 'Address' ).fill( originalAddress );
		await editor.getByRole( 'button', { name: 'Create venue' } ).click();
		// Same geocode round trip as the save further down — the editor stays
		// mounted, with its button disabled, until the write returns.
		await expect( editor ).toBeHidden( { timeout: 30000 } );

		// The new venue comes back selected, rather than dropping the
		// organiser to "— No venue —" with their typing lost.
		const firstPicker = firstModal.getByLabel( 'Venue' );
		const venueId = await firstPicker.inputValue();
		expect( venueId, 'the new venue should be selected on return' ).toMatch( /^\d+$/ );

		await firstModal.getByRole( 'button', { name: 'Create event' } ).click();
		await page.waitForURL( /\/event\//, { timeout: 30000 } );
		await expect( page.locator( '.groups-site-event-info-card' ) ).toContainText( venueName );

		// ---- Reuse, on a second event ---------------------------------
		const secondModal = await openEventModal( page, `${ venueName } Event Two` );
		const secondPicker = secondModal.getByLabel( 'Venue' );

		// The venue the first event created is offered rather than having to
		// be typed again — and offered exactly once.
		await expect( secondPicker.locator( `option[value="${ venueId }"]` ) ).toHaveCount( 1 );
		await expect(
			secondPicker.locator( 'option', { hasText: venueName } ),
			'the venue should appear once in the picker, not once per event that used it'
		).toHaveCount( 1 );

		await secondPicker.selectOption( venueId );
		await secondModal.getByRole( 'button', { name: 'Create event' } ).click();
		await page.waitForURL( /\/event\//, { timeout: 30000 } );
		await expect( page.locator( '.groups-site-event-info-card' ) ).toContainText( venueName );

		// ---- Confirm no duplicate was created -------------------------
		// The assertion this spec exists for. `resolve_venue_id()` creates a
		// new post for any submission carrying a name instead of an ID, so a
		// picker regression shows up as a second venue with the same name.
		let dialog = await openVenuesTab( page );
		await expect(
			dialog.getByRole( 'button', { name: venueName } ),
			'reusing a venue must not create a second venue with the same name'
		).toHaveCount( 1 );
		await expect( dialog.getByRole( 'button', { name: venueName } ) ).toContainText( originalAddress );

		// ---- Edit, from the Settings → Venues tab ---------------------
		await dialog.getByRole( 'button', { name: venueName } ).click();

		// The settings tab renders the same editor with `inline`, which swaps
		// the wrapper class entirely rather than adding a modifier alongside
		// it — `.wporg-groups-venue-editor` does not match here.
		const settingsEditor = dialog.locator( '.wporg-groups-venue-editor--inline' );
		await expect( settingsEditor.getByLabel( 'Venue name' ) ).toHaveValue( venueName );
		// The existing address is loaded into the form, not silently blanked —
		// saving over an empty field would drop the venue's coordinates.
		await expect( settingsEditor.getByLabel( 'Address' ) ).toHaveValue( originalAddress );

		await settingsEditor.getByLabel( 'Address' ).fill( updatedAddress );
		await settingsEditor.getByRole( 'button', { name: 'Save venue' } ).click();
		// Saving an address sends GatherPress off to geocode it server-side,
		// which is an outbound HTTP call — slow, and slower still on a cold
		// local stack. The editor only unmounts once the write returns, so
		// this needs more than the default 5s assertion timeout.
		await expect( settingsEditor ).toBeHidden( { timeout: 30000 } );

		// Reopen from scratch: the list updates its own state optimistically
		// on save, so re-reading it is the only way to prove the edit landed
		// server-side rather than only in React state.
		dialog = await openVenuesTab( page );
		await expect( dialog.getByRole( 'button', { name: venueName } ) ).toHaveCount( 1 );
		await expect( dialog.getByRole( 'button', { name: venueName } ) ).toContainText( updatedAddress );
	} );
} );
