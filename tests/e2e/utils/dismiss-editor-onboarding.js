/**
 * Dismisses the block editor's onboarding UI that can appear on a fresh
 * `post-new.php` visit, before any other interaction with the editor.
 *
 * Two separate, independent modals can show up here, both driven by
 * client-side/per-user preferences rather than anything this test controls,
 * so a given run may see either, both, or neither:
 *
 *   - The "Welcome to the editor" guide — dismissed via its own Close button.
 *   - The "Choose a pattern" starter-pattern picker, which `gatherpress_event`
 *     registers starter patterns for. Unlike the welcome guide, this one's
 *     own Close button and Escape do **not** dismiss it (confirmed manually
 *     in a browser) — the only way through is picking a pattern, so this
 *     selects whichever one is offered first.
 *
 * Every spec that creates an event through wp-admin needs this, immediately
 * after the editor canvas iframe attaches and before touching anything else
 * — a previous version of these specs only handled the welcome guide, so
 * the pattern picker (when it appeared) silently sat on top of the sidebar
 * and every subsequent interaction (filling in the date, clicking Publish)
 * hung until the test timeout.
 *
 * The welcome guide used to be dismissed with a global `page.keyboard.press(
 * 'Escape' )` after waiting for its heading to become visible. Traces from
 * #1883 showed the dialog still open and blocking the page well after that
 * wait resolved -- Escape is a blind keypress that depends on focus already
 * being in the right place and the dialog's own event handlers already
 * being wired up, and evidently that's not reliable here (a heading can be
 * visible in the DOM slightly before React finishes attaching its
 * handlers). Clicking the dialog's own Close button instead is a real
 * actionability-checked interaction -- Playwright waits for it to exist,
 * be visible, be stable, and actually receive the click, which rides out
 * exactly this kind of hydration race instead of hoping a keypress lands.
 *
 * @param {import('@playwright/test').Page} page
 */
async function dismissEditorOnboarding( page ) {
	const welcomeGuideCloseButton = page
		.getByRole( 'dialog', { name: 'Welcome to the editor' } )
		.getByRole( 'button', { name: 'Close' } );
	let welcomeGuideAppeared = true;
	try {
		// Only "never appeared" is expected/swallowed here. Once we know it's
		// there, the click itself runs outside the try -- if that fails for
		// some other reason, the test should fail loudly at the real cause
		// instead of this silently misclassifying it as "didn't appear" and
		// leaving the same dialog open to block everything after it.
		await welcomeGuideCloseButton.waitFor( { state: 'visible', timeout: 15000 } );
	} catch {
		// Guide didn't appear (already dismissed for this user) — nothing to do.
		welcomeGuideAppeared = false;
	}
	if ( welcomeGuideAppeared ) {
		await welcomeGuideCloseButton.click();
	}

	// Independent of the welcome guide above -- a given run may see either,
	// both, or neither, so this always runs regardless of the outcome above.
	const patternDialogHeading = page.getByRole( 'heading', { name: 'Choose a pattern' } );
	try {
		await patternDialogHeading.waitFor( { state: 'visible', timeout: 3000 } );
		await page.getByRole( 'option' ).first().click();
	} catch {
		// Dialog didn't appear (already dismissed for this user/session, or a
		// post type with no starter patterns) — nothing to do. Not implicated
		// in #1883's investigation, so this keeps its original short timeout
		// rather than paying the welcome guide's longer one on every run
		// where it's absent (the common case).
	}
}

module.exports = { dismissEditorOnboarding };
