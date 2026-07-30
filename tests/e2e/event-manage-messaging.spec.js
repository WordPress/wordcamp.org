const { test, expect } = require( '@playwright/test' );
const { login } = require( './utils/login' );
const { pinEventFarInFuture } = require( './utils/pin-event-far-future' );

/**
 * The `wporg/event-manage` block's "Message attendees" action (#1822),
 * stacked on top of the pre-existing "Message all members" action (#1821).
 *
 * Requires the `eventorganiser1` / `password` and `member1` / `password`
 * test users from the groups-gatherpress-compat-test skill's
 * environment-setup step.
 */
test.describe( 'event manage — message attendees', () => {
	/**
	 * Creates a fresh event as `eventorganiser1` via the wp-admin block
	 * editor (same flow as event-organiser.spec.js) and returns its
	 * front-end permalink, so this spec doesn't depend on any
	 * hand-seeded event data.
	 *
	 * @param {import('@playwright/test').Page} page
	 */
	async function createEventAsOrganiser( page ) {
		await login( page, 'eventorganiser1', 'password' );

		await page.goto( 'wp-admin/post-new.php?post_type=gatherpress_event' );
		await page.locator( 'iframe[name="editor-canvas"]' ).waitFor( { state: 'attached', timeout: 20000 } );

		const welcomeGuideHeading = page.getByRole( 'heading', { name: 'Welcome to the editor' } );
		try {
			await welcomeGuideHeading.waitFor( { state: 'visible', timeout: 3000 } );
			await page.keyboard.press( 'Escape' );
		} catch {
			// Guide didn't appear (already dismissed for this user) — nothing to do.
		}

		const title = `Messaging Test Event ${ Date.now() }`;
		const editorCanvas = page.locator( 'iframe[name="editor-canvas"]' ).contentFrame();
		await editorCanvas.getByRole( 'textbox', { name: 'Add title' } ).fill( title );
		await editorCanvas.getByRole( 'textbox', { name: 'Add title' } ).press( 'Tab' );

		await pinEventFarInFuture( page );

		const publishButton = page
			.getByLabel( 'Editor top bar' )
			.getByRole( 'button', { name: 'Publish', exact: true } );
		await expect( publishButton ).toBeEnabled( { timeout: 10000 } );
		await publishButton.click();

		const confirmPublishButton = page
			.getByLabel( 'Editor publish' )
			.getByRole( 'button', { name: 'Publish', exact: true } );
		await expect( confirmPublishButton ).toBeEnabled( { timeout: 10000 } );
		await confirmPublishButton.click();

		await expect( page.getByTestId( 'snackbar' ).getByText( 'Event published.' ) ).toBeVisible( {
			timeout: 15000,
		} );

		// The homepage's "Upcoming events" widget is capped and, on a
		// long-lived dev DB, fills up with same-day events from prior test
		// runs — a newly published event isn't guaranteed a slot in it.
		// The post id in the editor's URL is a direct, deterministic way to
		// reach the permalink instead.
		const editUrl = new URL( page.url() );
		const postId = editUrl.searchParams.get( 'post' );
		await page.goto( `?p=${ postId }&post_type=gatherpress_event` );
		await page.waitForLoadState( 'networkidle' );

		return page.url();
	}

	test( 'recipient checkboxes are independently selectable, require at least one, and send succeeds', async ( {
		page,
	} ) => {
		test.slow(); // Shares the block editor's cold-boot cost from createEventAsOrganiser().

		await createEventAsOrganiser( page );

		await page.getByRole( 'button', { name: 'Message attendees' } ).click();

		const modal = page.getByRole( 'dialog', { name: 'Message event attendees' } );
		await expect( modal ).toBeVisible();

		const attending = modal.getByRole( 'checkbox', { name: 'Attending', exact: true } );
		const waitingList = modal.getByRole( 'checkbox', { name: 'Waiting list' } );
		const notAttending = modal.getByRole( 'checkbox', { name: 'Not attending' } );
		const sendButton = modal.getByRole( 'button', { name: 'Send message' } );

		// "Attending" is checked by default; the others are not.
		await expect( attending ).toBeChecked();
		await expect( waitingList ).not.toBeChecked();
		await expect( notAttending ).not.toBeChecked();

		// Unchecking every recipient shows the validation notice and
		// disables sending, even with a non-empty message.
		await attending.uncheck();
		await modal.getByLabel( 'Message' ).fill( 'This should not be sendable.' );
		await expect( modal.getByText( 'Select at least one RSVP group.' ) ).toBeVisible();
		await expect( sendButton ).toBeDisabled();

		// Waiting list and Not attending can both be checked at once,
		// independently of Attending — this is the core behavior #1822
		// adds over the previous all-or-nothing "Message all members".
		await waitingList.check();
		await notAttending.check();
		await expect( waitingList ).toBeChecked();
		await expect( notAttending ).toBeChecked();
		await expect( attending ).not.toBeChecked();
		await expect( modal.getByText( 'Select at least one RSVP group.' ) ).toBeHidden();

		// Reset to "Attending" only, matching the PR's own test plan, and send.
		await waitingList.uncheck();
		await notAttending.uncheck();
		await attending.check();

		const uniqueMessage = `E2E attendees-only message ${ Date.now() }`;
		await modal.getByLabel( 'Message' ).fill( uniqueMessage );
		await expect( sendButton ).toBeEnabled();
		await sendButton.click();

		await expect( modal.getByText( 'Your message has been scheduled for delivery.' ) ).toBeVisible();
	} );

	test( 'Message all members has no recipient checkboxes', async ( { page } ) => {
		test.slow();

		await createEventAsOrganiser( page );

		await page.getByRole( 'button', { name: 'Message all members' } ).click();

		const modal = page.getByRole( 'dialog', { name: 'Message all members' } );
		await expect( modal ).toBeVisible();
		await expect(
			modal.getByText( 'This message will be emailed to every opted-in member of this group.' )
		).toBeVisible();
		await expect( modal.getByRole( 'checkbox' ) ).toHaveCount( 0 );
	} );

	test( 'an ordinary member sees neither messaging action', async ( { page, browser } ) => {
		test.slow();

		const organiserPage = await ( await browser.newContext() ).newPage();
		const eventUrl = await createEventAsOrganiser( organiserPage );
		await organiserPage.close();

		await login( page, 'member1', 'password' );
		await page.goto( eventUrl );

		await expect( page.getByRole( 'button', { name: 'Message all members' } ) ).toHaveCount( 0 );
		await expect( page.getByRole( 'button', { name: 'Message attendees' } ) ).toHaveCount( 0 );
	} );
} );
