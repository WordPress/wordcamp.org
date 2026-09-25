const { test, expect } = require( '@playwright/test' );
const { login } = require( './utils/login' );
const { dismissEditorOnboarding } = require( './utils/dismiss-editor-onboarding' );

/**
 * Event Organizers (author role) manage events through wp-admin's native
 * GatherPress editor — the custom `wporg/event-manage` front-end block is
 * registered but not placed in any template (a known, tracked issue; see
 * the "Known-issues appendix" in the groups-gatherpress-compat-test skill),
 * so this exercises the actual working path rather than the unreachable one.
 *
 * Requires the `eventorganiser1` / `password` test user from the skill's
 * environment-setup step.
 */
test.describe( 'event organizer (author role)', () => {
	test( 'can create and publish an event via wp-admin, and it appears on the front page', async ( { page } ) => {
		test.slow(); // The block editor can be slow to boot on a cold local dev stack.

		await login( page, 'eventorganiser1', 'password' );

		await page.goto( 'wp-admin/post-new.php?post_type=gatherpress_event' );
		await page.locator( 'iframe[name="editor-canvas"]' ).waitFor( { state: 'attached', timeout: 60000 } );

		await dismissEditorOnboarding( page );

		const title = `E2E Test Event ${ Date.now() }`;
		// The block editor's canvas (including the title field) renders
		// inside an iframe.
		const editorCanvas = page.locator( 'iframe[name="editor-canvas"]' ).contentFrame();
		await editorCanvas.getByRole( 'textbox', { name: 'Add title' } ).fill( title );
		await editorCanvas.getByRole( 'textbox', { name: 'Add title' } ).press( 'Tab' );

		// Both the toolbar toggle and (once open) the confirm button inside
		// the publish panel are named exactly "Publish" — scope each to its
		// own labelled region to avoid a strict-mode ambiguity.
		const publishButton = page.getByLabel( 'Editor top bar' ).getByRole( 'button', { name: 'Publish', exact: true } );
		await expect( publishButton ).toBeEnabled( { timeout: 10000 } );
		await publishButton.click();

		const confirmPublishButton = page.getByLabel( 'Editor publish' ).getByRole( 'button', { name: 'Publish', exact: true } );
		await expect( confirmPublishButton ).toBeEnabled( { timeout: 10000 } );
		await confirmPublishButton.click();

		await expect( page.getByTestId( 'snackbar' ).getByText( 'Event published.' ) ).toBeVisible( { timeout: 15000 } );

		await page.goto( '' );

		// Not the homepage's "Upcoming events" widget — it's capped, and on a
		// long-lived dev DB (or after this suite has run many times) it fills
		// up with older same-day events, so a brand-new one isn't guaranteed a
		// slot in it (the same issue event-manage-messaging.spec.js hit and
		// worked around). The my-events block has no such cap: it's the
		// #1810 behaviour itself — this organizer published the event and
		// never RSVP'd to it — and reliably shows it regardless of how many
		// other events exist.
		await expect(
			page.locator( '.wporg-my-events__title', { hasText: title } )
		).toBeVisible();
	} );
} );
