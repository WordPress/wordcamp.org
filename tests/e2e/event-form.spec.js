const { test, expect } = require( '@playwright/test' );
const { login } = require( './utils/login' );

/**
 * The front-end event form is one shared component rendered on two surfaces:
 * the "Create event" modal on the group page, and the Events tab of the group
 * settings dialog. Each surface publishes through its own route, and the
 * published event must carry the date and time that were entered - the
 * regression class behind #1909, where an event could be published before its
 * datetimes were saved.
 *
 * Requires the `organiser2` / `password` editor-tier test user (editors can use
 * both surfaces). This environment only supports one active session per user,
 * and specs run concurrently under `fullyParallel` (see tests/e2e/utils/login.js).
 */
test.describe( 'event form surfaces', () => {
	// Far enough out to stay clear of the front page's capped "Upcoming events"
	// ranking that other specs' events compete for (see utils/pin-event-far-future.js).
	const DATE = '2099-03-04'; // 2099-03-04 is a Wednesday.
	const START = '12:00';

	/**
	 * Fills the shared form's required fields and submits it.
	 *
	 * @param {import('@playwright/test').Locator} form
	 * @param {string}                             title
	 */
	async function fillAndSubmit( form, title ) {
		await form.getByLabel( 'Event title' ).fill( title );
		await form.getByLabel( 'Date', { exact: true } ).fill( DATE );
		await form.getByLabel( 'Start time' ).fill( START );
		await form.getByLabel( 'Duration' ).selectOption( { label: '1 hour' } );
		await form.getByRole( 'button', { name: 'Create event' } ).click();
	}

	/**
	 * The publish response redirects to the new event, whose page renders
	 * the saved datetimes.
	 *
	 * @param {import('@playwright/test').Page} page
	 * @param {string}                          title
	 */
	async function expectPublishedWithDatetimes( page, title ) {
		await page.waitForURL( /\/event\//, { timeout: 30000 } );
		await expect( page.getByRole( 'heading', { name: title } ) ).toBeVisible();
		await expect( page.locator( 'body' ) ).toContainText( 'Wednesday, March 4' );
		await expect( page.locator( 'body' ) ).toContainText( '12:00 PM to 1:00 PM' );
	}

	test( 'publishes with the entered datetimes from both the modal and the settings tab', async ( { page } ) => {
		test.slow(); // Two cold boots of the inline block editor.

		await login( page, 'organiser2', 'password' );

		const modalTitle = `Event form modal E2E ${ Date.now() }`;
		await page.goto( '' );
		await page.getByRole( 'button', { name: '+ Create event' } ).click();
		const modal = page.locator( '.wporg-groups-event-modal' );
		await fillAndSubmit( modal, modalTitle );
		await expectPublishedWithDatetimes( page, modalTitle );

		const tabTitle = `Event form settings E2E ${ Date.now() }`;
		await page.goto( '' );
		await page.getByRole( 'button', { name: /Settings/ } ).click();
		await page.getByRole( 'dialog' ).getByRole( 'button', { name: '+ Create event' } ).click();
		const tabForm = page.locator( 'form.wporg-event-form' );
		await fillAndSubmit( tabForm, tabTitle );
		await expectPublishedWithDatetimes( page, tabTitle );
	} );
} );
