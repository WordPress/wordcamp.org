const { test, expect } = require( '@playwright/test' );
const { login } = require( './utils/login' );

/**
 * The surface "Edit this event" opens.
 *
 * Editing one event used to open the whole group-settings modal — six
 * group-wide tabs with the event form inside the first one (#2073) — which
 * put unrelated configuration one click away from an edit. The modal is now
 * that event's own editing surface, while the group page's Settings button
 * still opens the tabbed one.
 *
 * Requires the `organiser2` (`password`) editor-tier test user and at least
 * one published event on the group; it edits nothing, so it leaves no
 * fixtures behind.
 */
test.describe( 'event editing surface', () => {
	test( 'edits one event without the group-wide tabs', async ( { page } ) => {
		await login( page, 'organiser2', 'password' );

		// Any published event will do — the surface is the same for all of
		// them, and reusing one keeps the spec off the slow create path.
		const response = await page.request.get(
			'wp-json/wp/v2/gatherpress_events?per_page=1&status=publish&_fields=link,title'
		);
		expect( response.ok() ).toBe( true );
		const [ event ] = await response.json();
		expect( event, 'the group needs a published event' ).toBeTruthy();

		await page.goto( event.link );
		await page.getByRole( 'button', { name: 'Edit this event' } ).click();

		const dialog = page.getByRole( 'dialog' );
		await expect( dialog.getByRole( 'heading', { name: 'Edit event' } ) ).toBeVisible();
		await expect( dialog.getByRole( 'tablist' ) ).toHaveCount( 0 );

		const form = page.locator( 'form.wporg-event-form' );
		await expect( form.getByLabel( 'Event title' ) ).toHaveValue( event.title.rendered );

		// Backing out of the form returns to the event, not to a list that
		// was never behind it.
		await dialog.getByRole( 'button', { name: 'Back to event' } ).click();
		await expect( dialog ).toHaveCount( 0 );
		await expect( page ).toHaveURL( event.link );
	} );

	test( 'keeps the tabs on the group Settings button', async ( { page } ) => {
		await login( page, 'organiser2', 'password' );

		await page.goto( '' );
		await page.locator( '[data-wporg-settings-open]' ).click();

		const dialog = page.getByRole( 'dialog' );
		await expect( dialog.getByRole( 'tab', { name: 'Venues' } ) ).toBeVisible();
		await expect( dialog.getByRole( 'tab', { name: 'Members' } ) ).toBeVisible();
	} );
} );
