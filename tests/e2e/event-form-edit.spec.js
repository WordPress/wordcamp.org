const { test, expect } = require( '@playwright/test' );
const { login } = require( './utils/login' );

/**
 * Edit mode of the shared event form, through the group-settings Events tab.
 * The tab is the only surface with the Speakers field, and the only edit
 * surface an editor-tier user sees: the group page's modal edit button is
 * rendered for users who cannot manage group settings, so it needs its own
 * author-tier account and event and is not covered here.
 *
 * Requires the `organiser2` (`password`) editor-tier test user, and
 * `organiser1` to exist as a group member to pick as the speaker.
 */
test.describe( 'event form edit mode', () => {
	// See event-form.spec.js for why the date sits this far out.
	const DATE = '2099-03-04';
	const START = '12:00';
	const SPEAKER = 'organiser1';

	/**
	 * Reads the event's meta through REST rather than the rendered page.
	 *
	 * @param {import('@playwright/test').Page} page
	 * @param {string}                          slug
	 * @return {Promise<Object>} The event's `meta`.
	 */
	async function fetchMeta( page, slug ) {
		const response = await page.request.get( `wp-json/wp/v2/gatherpress_events?slug=${ slug }&_fields=meta` );
		expect( response.ok() ).toBe( true );
		const [ event ] = await response.json();
		return event.meta;
	}

	test( 'keeps the speakers and drops the create-only date minimum when editing', async ( { page } ) => {
		test.slow(); // Two cold boots of the inline block editor.

		await login( page, 'organiser2', 'password' );

		const title = `Event form edit E2E ${ Date.now() }`;
		await page.goto( '' );
		await page.locator( '[data-wporg-settings-open]' ).click();
		const dialog = page.getByRole( 'dialog' );
		await dialog.getByRole( 'button', { name: '+ Create event', exact: true } ).click();
		const form = page.locator( 'form.wporg-event-form' );

		// The minimum only applies when creating; the edit assertion below
		// would pass vacuously if it went missing here too.
		await expect( form.getByLabel( 'Date', { exact: true } ) ).toHaveAttribute( 'min', /^\d{4}-\d{2}-\d{2}$/ );

		await form.getByLabel( 'Event title' ).fill( title );
		await form.getByLabel( 'Date', { exact: true } ).fill( DATE );
		await form.getByLabel( 'Start time' ).fill( START );
		await form.getByLabel( 'Duration' ).selectOption( { label: '1 hour' } );

		// The suggestions arrive from a separate members request; a token
		// that matches no member is dropped silently.
		const speakers = form.getByLabel( 'Speakers' );
		await speakers.pressSequentially( SPEAKER );
		const option = form.getByRole( 'option', { name: SPEAKER, exact: true } );
		await expect( option ).toBeVisible();
		await option.click();
		await expect(
			form.locator( '.components-form-token-field__token-text', { hasText: SPEAKER } )
		).toBeVisible();

		await form.getByRole( 'button', { name: 'Create event' } ).click();
		await page.waitForURL( /\/event\//, { timeout: 30000 } );
		await expect( page.getByRole( 'heading', { name: title } ) ).toBeVisible();

		const slug = new URL( page.url() ).pathname.split( '/' ).filter( Boolean ).pop();
		const created = await fetchMeta( page, slug );
		expect( created._event_speakers ).toHaveLength( 1 );

		// The single-event page renders the settings block's edit button,
		// which opens the Events tab straight into this event.
		await page.getByRole( 'button', { name: 'Edit this event' } ).click();
		await expect( form.getByLabel( 'Event title' ) ).toHaveValue( title );
		await expect( form.getByLabel( 'Date', { exact: true } ) ).not.toHaveAttribute( 'min' );
		await expect(
			form.locator( '.components-form-token-field__token-text', { hasText: SPEAKER } )
		).toBeVisible();

		const editedTitle = `${ title } edited`;
		await form.getByLabel( 'Event title' ).fill( editedTitle );
		await form.getByRole( 'button', { name: 'Save changes' } ).click();
		// The save redirects back to the same event URL; the new heading is
		// the signal that the reload landed.
		await expect( page.getByRole( 'heading', { name: editedTitle } ) ).toBeVisible( { timeout: 30000 } );

		const edited = await fetchMeta( page, slug );
		expect( edited._event_speakers ).toEqual( created._event_speakers );
		expect( edited.gatherpress_datetime_start ).toBe( created.gatherpress_datetime_start );
	} );
} );
