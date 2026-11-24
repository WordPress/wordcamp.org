const { test, expect } = require( '@playwright/test' );
const { STORAGE_STATE } = require( './utils/accounts' );

/**
 * The surface "Edit this event" opens.
 *
 * Editing one event used to open the whole group-settings modal — six
 * group-wide tabs with the event form inside the first one (#2073) — which
 * put unrelated configuration one click away from an edit. The modal is now
 * that event's own editing surface, while the group page's Settings button
 * still opens the tabbed one.
 *
 * Requires the `organiser1` / `password` editor-tier test user.
 *
 * Both tests read: the first publishes an event to have one to edit, and
 * neither saves a change.
 */
test.describe( 'event editing surface', () => {
	test.use( { storageState: STORAGE_STATE.organiser1 } );

	// Far enough out to stay clear of the front page's capped "Upcoming events"
	// ranking that other specs' events compete for (see utils/pin-event-far-future.js).
	const DATE = '2099-03-04'; // 2099-03-04 is a Wednesday.
	const START = '12:00';
	test( 'edits one event without the group-wide tabs', async ( { page } ) => {
		test.slow(); // A cold boot of the inline block editor behind the modal.

		/*
		 * Publish an event of our own rather than reading whatever the archive
		 * happens to hold. CI seeds no events at all, and the specs that create
		 * them run concurrently with this one.
		 */
		const title = `Event edit surface E2E ${ Date.now() }`;
		await page.goto( '' );
		await page.getByRole( 'button', { name: '+ Create event', exact: true } ).click();

		const createModal = page.locator( '.wporg-groups-event-modal' );
		await createModal.getByLabel( 'Event title' ).fill( title );
		await createModal.getByLabel( 'Date', { exact: true } ).fill( DATE );
		await createModal.getByLabel( 'Start time' ).fill( START );
		await createModal.getByLabel( 'Duration' ).selectOption( { label: '1 hour' } );
		await createModal.getByRole( 'button', { name: 'Create event' } ).click();
		await page.waitForURL( /\/event\//, { timeout: 30000 } );

		const eventUrl = page.url();
		await page.getByRole( 'button', { name: 'Edit this event' } ).click();

		const dialog = page.getByRole( 'dialog' );
		await expect( dialog.getByRole( 'heading', { name: 'Edit event' } ) ).toBeVisible();
		await expect( dialog.getByRole( 'tablist' ) ).toHaveCount( 0 );

		const form = page.locator( 'form.wporg-event-form' );
		await expect( form.getByLabel( 'Event title' ) ).toHaveValue( title );

		// Backing out of the form returns to the event, not to a list that
		// was never behind it.
		await dialog.getByRole( 'button', { name: 'Back to event' } ).click();
		await expect( dialog ).toHaveCount( 0 );
		await expect( page ).toHaveURL( eventUrl );
	} );

	test( 'keeps the tabs on the group Settings button', async ( { page } ) => {
		await page.goto( '' );
		await page.locator( '[data-wporg-settings-open]' ).click();

		const dialog = page.getByRole( 'dialog' );
		await expect( dialog.getByRole( 'tab', { name: 'Venues' } ) ).toBeVisible();
		await expect( dialog.getByRole( 'tab', { name: 'Members' } ) ).toBeVisible();
	} );
} );
