const { test, expect } = require( '@playwright/test' );
const { login } = require( './utils/login' );

/**
 * Automatic "event published" notification (#1829): publishing a
 * GatherPress event for the first time schedules GatherPress's existing
 * "all members" email exactly once; editing an already-published event
 * afterwards does not schedule (and therefore does not send) a duplicate.
 *
 * Requires the `eventorganiser1` / `password` test user from the
 * groups-gatherpress-compat-test skill's environment-setup step, and a
 * reachable MailCatcher instance at http://localhost:1080 (the local dev
 * stack's mail sink — see .docker/readme.md).
 */
test.describe( 'event publish notification', () => {
	const MAILCATCHER_URL = 'http://localhost:1080';

	/**
	 * Creates and publishes a fresh event as `eventorganiser1` via the
	 * wp-admin block editor, and returns its post id.
	 *
	 * @param {import('@playwright/test').Page} page
	 * @param {string}                          title
	 */
	async function createAndPublishEvent( page, title ) {
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

		const editorCanvas = page.locator( 'iframe[name="editor-canvas"]' ).contentFrame();
		await editorCanvas.getByRole( 'textbox', { name: 'Add title' } ).fill( title );
		await editorCanvas.getByRole( 'textbox', { name: 'Add title' } ).press( 'Tab' );

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

		return new URL( page.url() ).searchParams.get( 'post' );
	}

	/**
	 * Edits the excerpt of an already-published event through the editor's
	 * Post sidebar (a plain textarea, more reliable to drive than the block
	 * canvas) and saves — a real edit that doesn't touch title or status.
	 *
	 * @param {import('@playwright/test').Page} page
	 * @param {string}                          postId
	 */
	async function editPublishedEvent( page, postId ) {
		await page.goto( `wp-admin/post.php?post=${ postId }&action=edit` );
		await page.locator( 'iframe[name="editor-canvas"]' ).waitFor( { state: 'attached', timeout: 20000 } );

		await page.getByRole( 'button', { name: 'Add an excerpt' } ).click();
		await page.getByRole( 'textbox', { name: 'Excerpt' } ).fill( 'Edited without changing publish state.' );
		await page.keyboard.press( 'Tab' );

		const saveButton = page
			.getByLabel( 'Editor top bar' )
			.getByRole( 'button', { name: 'Save', exact: true } );
		await expect( saveButton ).toBeEnabled( { timeout: 10000 } );
		await saveButton.click();

		await expect( page.getByTestId( 'snackbar' ).getByText( /(Event|Post) updated\./ ) ).toBeVisible( {
			timeout: 15000,
		} );
	}

	/**
	 * Forces WP-Cron to run now, so the scheduled `gatherpress_send_emails`
	 * job doesn't depend on WordPress's own opportunistic auto-spawn timing
	 * — the HTTP equivalent of `wp cron event run --due-now`.
	 *
	 * @param {import('@playwright/test').Page} page
	 */
	async function runDueCron( page ) {
		await page.request.get( 'wp-cron.php?doing_wp_cron', { failOnStatusCode: false } );
	}

	/**
	 * Emails in MailCatcher whose subject contains the given event title.
	 *
	 * @param {import('@playwright/test').Page} page
	 * @param {string}                          title
	 */
	async function matchingEmails( page, title ) {
		const response = await page.request.get( `${ MAILCATCHER_URL }/messages` );
		const messages = await response.json();
		return messages.filter( ( message ) => message.subject.includes( title ) );
	}

	/**
	 * The "all members" job delivers to each recipient in the same request,
	 * but MailCatcher's message list can still be read mid-append — polling
	 * for "count > 0" alone can catch a batch still arriving. Waits until
	 * the count holds steady across a few checks before treating it as the
	 * settled total.
	 *
	 * @param {import('@playwright/test').Page} page
	 * @param {string}                          title
	 */
	async function stableMatchingCount( page, title ) {
		let previous = -1;
		let stableChecks = 0;

		while ( stableChecks < 3 ) {
			const current = ( await matchingEmails( page, title ) ).length;
			if ( current === previous ) {
				stableChecks++;
			} else {
				stableChecks = 0;
			}
			previous = current;
			await page.waitForTimeout( 500 );
		}

		return previous;
	}

	test( 'publishing an event notifies members once, and editing it afterwards does not duplicate', async ( {
		page,
	} ) => {
		test.slow(); // Shares the block editor's cold-boot cost, plus cron + MailCatcher round trips.

		const title = `Auto-notify E2E ${ Date.now() }`;
		const postId = await createAndPublishEvent( page, title );

		await runDueCron( page );
		const countAfterPublish = await stableMatchingCount( page, title );
		expect( countAfterPublish ).toBeGreaterThan( 0 );

		await editPublishedEvent( page, postId );
		await runDueCron( page );
		const countAfterEdit = await stableMatchingCount( page, title );

		expect( countAfterEdit ).toBe( countAfterPublish );
	} );
} );
