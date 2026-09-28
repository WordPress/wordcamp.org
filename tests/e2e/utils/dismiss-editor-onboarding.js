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
 * That alone still wasn't enough: a trace showed the *fill* right after
 * this function returns failing with the dialog open again, despite this
 * function already having dismissed it. The editor's app state appears to
 * reinitialize a second time shortly after the first load (a duplicate
 * wave of editor asset requests shows up ~1-2s after the first), bringing
 * a fresh, not-yet-dismissed instance of the same dialog back with it. One
 * dismissal isn't durable, so this loops -- checking again after each
 * dismissal -- until the dialog genuinely stops reappearing.
 *
 * @param {import('@playwright/test').Page} page
 */
async function dismissEditorOnboarding( page ) {
	const welcomeGuideCloseButton = page
		.getByRole( 'dialog', { name: 'Welcome to the editor' } )
		.getByRole( 'button', { name: 'Close' } );

	// First check is generous (cold JS boot); rechecks after a dismissal are
	// shorter, since by then we're only confirming a reappearance didn't
	// happen, not waiting through an initial render.
	for ( let attempt = 0; attempt < 3; attempt++ ) {
		try {
			await welcomeGuideCloseButton.waitFor( {
				state: 'visible',
				timeout: attempt === 0 ? 15000 : 5000,
			} );
		} catch {
			// Didn't (re)appear — nothing left to dismiss.
			break;
		}
		// Bounded, rather than inheriting the test's full (90s under
		// test.slow()) default action timeout -- by this point we already
		// know the dialog exists, so a normal click shouldn't need long. If
		// something's actually wrong (e.g. the dialog stuck detaching and
		// reattaching in a render loop), this fails fast and loud instead of
		// burning the whole test budget on one action.
		await welcomeGuideCloseButton.click( { timeout: 10000 } );
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
