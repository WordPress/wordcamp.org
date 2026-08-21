const { test, expect } = require( '@playwright/test' );
const { login } = require( './utils/login' );
const { dismissEditorOnboarding } = require( './utils/dismiss-editor-onboarding' );

/**
 * Reply → Cancel on a group news post.
 *
 * `single.html` renders comments with core's ordinary post comment form,
 * whose Cancel reply link core nests inside `#reply-title` — the heading the
 * theme's stylesheet hides. Until the theme's compact comment-form defaults
 * covered `post` as well as `gatherpress_event`, clicking Reply moved the
 * form into nested-reply mode and hid the only control that could undo it:
 * every later comment silently became a child of the one being replied to.
 *
 * Only a browser can cover this half — `comment-reply.js` is what moves the
 * form and rewrites `#comment_parent`. The server-rendered half (no
 * `#reply-title`, a visible cancel link, the placeholder and the
 * screen-reader label) is pinned in
 * `mu-plugins/groups/tests/test-groups-site-comment-form.php`.
 *
 * Requires the `eventorganiser6` / `password` test user from the skill's
 * environment-setup step. It authors both the post and the comment, which
 * is what keeps the comment out of moderation: core auto-approves a post
 * author's comments on their own post.
 */
test.describe( 'news post comments', () => {
	test( 'Reply then Cancel puts the form back to a top-level comment', async ( { page } ) => {
		test.slow(); // The block editor can be slow to boot on a cold local dev stack.

		await login( page, 'eventorganiser6', 'password' );

		// --- Publish a news post to comment on. ---------------------------
		await page.goto( 'wp-admin/post-new.php?post_type=post' );
		await page.locator( 'iframe[name="editor-canvas"]' ).waitFor( { state: 'attached', timeout: 60000 } );

		await dismissEditorOnboarding( page );

		const title = `E2E Comment Reply ${ Date.now() }`;
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

		await expect( page.getByTestId( 'snackbar' ).getByText( 'Post published.' ) ).toBeVisible( {
			timeout: 15000,
		} );

		// `?p=` rather than the permalink: the editor URL is the one thing
		// that reliably identifies the post just published, and the News
		// section on the front page is capped.
		const postId = new URL( page.url() ).searchParams.get( 'post' );
		expect( postId ).toBeTruthy();

		// --- Leave a comment to reply to. ---------------------------------
		await page.goto( `?p=${ postId }` );

		const composer = page.locator( '#commentform' );
		await expect( composer.locator( 'textarea#comment' ) ).toHaveAttribute( 'placeholder', 'Add a comment…' );

		await composer.locator( 'textarea#comment' ).fill( 'The comment being replied to.' );
		await composer.locator( 'input[type="submit"]' ).click();

		const comment = page.locator( '.wp-block-comment-template > li' ).first();
		await expect( comment ).toContainText( 'The comment being replied to.' );

		// The author can edit their own comment, so both actions share the
		// row under it.
		await expect( comment.getByRole( 'link', { name: 'Edit' } ) ).toBeVisible();

		const parentField = page.locator( '#comment_parent' );
		await expect( parentField ).toHaveValue( '0' );

		// --- Reply. -------------------------------------------------------
		await comment.getByRole( 'link', { name: 'Reply' } ).click();

		const cancelLink = page.locator( '#cancel-comment-reply-link' );
		await expect( cancelLink ).toBeVisible();
		await expect( parentField ).not.toHaveValue( '0' );

		// The form really did move under the comment being replied to.
		const movedComposer = page.locator( '.wp-block-comment-template > #respond' );
		await expect( movedComposer ).toBeVisible();

		// --- Cancel. ------------------------------------------------------
		await cancelLink.click();

		await expect( cancelLink ).toBeHidden();
		await expect( parentField ).toHaveValue( '0' );
		await expect( movedComposer ).toHaveCount( 0 );
		await expect( page.locator( '.wp-block-comments > #respond' ) ).toBeVisible();
	} );
} );
