/**
 * Dismisses the block editor's onboarding UI that can appear on a fresh
 * `post-new.php` visit, before any other interaction with the editor.
 *
 * Two separate, independent modals can show up here, both driven by
 * client-side/per-user preferences rather than anything this test controls,
 * so a given run may see either, both, or neither:
 *
 *   - The "Welcome to the editor" guide — dismissed with Escape.
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
 * @param {import('@playwright/test').Page} page
 */
async function dismissEditorOnboarding( page ) {
	const welcomeGuideHeading = page.getByRole( 'heading', { name: 'Welcome to the editor' } );
	try {
		await welcomeGuideHeading.waitFor( { state: 'visible', timeout: 3000 } );
		await page.keyboard.press( 'Escape' );
	} catch {
		// Guide didn't appear (already dismissed for this user) — nothing to do.
	}

	const patternDialogHeading = page.getByRole( 'heading', { name: 'Choose a pattern' } );
	try {
		await patternDialogHeading.waitFor( { state: 'visible', timeout: 3000 } );
		await page.getByRole( 'option' ).first().click();
	} catch {
		// Dialog didn't appear (already dismissed for this user/session, or a
		// post type with no starter patterns) — nothing to do.
	}
}

module.exports = { dismissEditorOnboarding };
