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
 * Requires the `organiser2`/`organiser3`/`organiser4` (`password`) editor-tier
 * test users, one per test (editors can use both surfaces). This environment
 * only supports one active session per user, and tests run concurrently under
 * `fullyParallel` (see tests/e2e/utils/login.js).
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
	 * The publish response redirects to the new event. The saved datetimes
	 * are checked through REST rather than the rendered text, which depends
	 * on the site locale.
	 *
	 * @param {import('@playwright/test').Page} page
	 * @param {string}                          title
	 */
	async function expectPublishedWithDatetimes( page, title ) {
		await page.waitForURL( /\/event\//, { timeout: 30000 } );
		await expect( page.getByRole( 'heading', { name: title } ) ).toBeVisible();

		const slug = new URL( page.url() ).pathname.split( '/' ).filter( Boolean ).pop();
		const response = await page.request.get( `wp-json/wp/v2/gatherpress_events?slug=${ slug }&_fields=meta` );
		expect( response.ok() ).toBe( true );
		const [ event ] = await response.json();
		expect( event.meta.gatherpress_datetime_start ).toBe( `${ DATE } ${ START }:00` );
		expect( event.meta.gatherpress_datetime_end ).toBe( `${ DATE } 13:00:00` );
	}

	/**
	 * Opens the settings dialog's create form.
	 *
	 * @param {import('@playwright/test').Page} page
	 * @return {import('@playwright/test').Locator} The form.
	 */
	async function openSettingsCreateForm( page ) {
		await page.goto( '' );
		await page.locator( '[data-wporg-settings-open]' ).click();
		await page.getByRole( 'dialog' ).getByRole( 'button', { name: '+ Create event', exact: true } ).click();
		return page.locator( 'form.wporg-event-form' );
	}

	test( 'publishes with the entered datetimes from both the modal and the settings tab', async ( { page } ) => {
		test.slow(); // Two cold boots of the inline block editor.

		await login( page, 'organiser2', 'password' );

		const modalTitle = `Event form modal E2E ${ Date.now() }`;
		await page.goto( '' );
		await page.getByRole( 'button', { name: '+ Create event', exact: true } ).click();
		const modal = page.locator( '.wporg-groups-event-modal' );
		await fillAndSubmit( modal, modalTitle );
		await expectPublishedWithDatetimes( page, modalTitle );

		const tabTitle = `Event form settings E2E ${ Date.now() }`;
		const tabForm = await openSettingsCreateForm( page );
		await fillAndSubmit( tabForm, tabTitle );
		await expectPublishedWithDatetimes( page, tabTitle );
	} );

	/**
	 * The modal autosaves a draft, and reopening it lists that draft in the
	 * picker. Publishing from there goes through `/draft/{id}/publish` with
	 * the recurrence the form-data endpoint handed back.
	 */
	test( 'publishes a draft continued from the modal picker', async ( { page } ) => {
		test.slow();

		await login( page, 'organiser3', 'password' );

		const title = `Event form draft E2E ${ Date.now() }`;
		await page.goto( '' );
		await page.getByRole( 'button', { name: '+ Create event', exact: true } ).click();
		const modal = page.locator( '.wporg-groups-event-modal' );
		await modal.getByLabel( 'Event title' ).fill( title );
		await modal.getByLabel( 'Date', { exact: true } ).fill( DATE );
		await modal.getByLabel( 'Start time' ).fill( START );
		await modal.getByLabel( 'Duration' ).selectOption( { label: '1 hour' } );
		await expect( modal ).toContainText( 'Draft saved at', { timeout: 15000 } );

		// Closing a modal with a draft asks for confirmation.
		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await modal.getByRole( 'button', { name: 'Close' } ).click();
		await expect( modal ).toBeHidden();

		await page.getByRole( 'button', { name: '+ Create event', exact: true } ).click();
		await modal.getByLabel( 'Continue from a draft' ).selectOption( { label: `${ title } — ${ DATE }` } );
		await expect( modal.getByLabel( 'Event title' ) ).toHaveValue( title );
		await modal.getByRole( 'button', { name: 'Create event' } ).click();

		await expectPublishedWithDatetimes( page, title );
	} );

	/**
	 * On the settings tab the venue editor opens in place of the form. The
	 * description lives in the inline block editor, so it must survive the
	 * form being hidden and shown again.
	 */
	test( 'keeps the description across the settings-tab venue editor', async ( { page } ) => {
		test.slow();

		await login( page, 'organiser4', 'password' );

		const title = `Event form venue round-trip E2E ${ Date.now() }`;
		const description = `Description kept ${ Date.now() }`;
		const form = await openSettingsCreateForm( page );
		await form.getByLabel( 'Event title' ).fill( title );
		await form.getByLabel( 'Date', { exact: true } ).fill( DATE );
		await form.getByLabel( 'Start time' ).fill( START );
		await form.getByLabel( 'Duration' ).selectOption( { label: '1 hour' } );

		const paragraph = form.locator( '[data-type="core/paragraph"]' );
		await paragraph.click();
		await page.keyboard.type( description );
		await expect( paragraph ).toHaveText( description );

		// Click before selecting: a `change` dispatched while focus is still in
		// the block editor is not delivered to the select's handler.
		const venue = form.getByLabel( 'Venue', { exact: true } );
		await venue.click();
		await venue.selectOption( '__new__' );
		const dialog = page.getByRole( 'dialog' );
		await expect( dialog.getByLabel( 'Venue name' ) ).toBeVisible();
		await expect( form ).toBeHidden();
		await dialog.getByRole( 'button', { name: 'Cancel' } ).click();

		await expect( form ).toBeVisible();
		await expect( paragraph ).toHaveText( description );

		await form.getByRole( 'button', { name: 'Create event' } ).click();
		await expectPublishedWithDatetimes( page, title );
		await expect( page.locator( 'body' ) ).toContainText( description );
	} );
} );
