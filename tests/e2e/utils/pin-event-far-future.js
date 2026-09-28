/**
 * Pins a GatherPress event's start date far in the future via the block
 * editor's "Date & time start" popover.
 *
 * The front page's "Upcoming events" query loop shows only the 3
 * *soonest* upcoming events network-wide. Left at the
 * editor's default (today/tomorrow), every event an e2e spec creates
 * competes for those 3 slots with events other specs create in the same
 * CI run — a genuine flaky-test cause, not just a theoretical one (an
 * unrelated spec's own newly-published event got crowded out of that
 * list by other tests' events in a real CI run). Pinning far enough
 * out keeps a spec's own events out of that ranking entirely, without
 * having to coordinate timing across every spec that creates one.
 *
 * Must be called after the event's title is set and before publishing.
 *
 * The "Date & time start" control lives inside GatherPress's "Event
 * settings" document panel, which GatherPress force-opens on first load
 * via a `domReady` callback — but that callback checks
 * `isEditorPanelOpened()` before the panel-open preference (persisted
 * server-side per WordPress user, not freshly defaulted each session) has
 * necessarily finished loading, so it can race and leave the panel
 * collapsed. Once that happens for a given test account, the panel stays
 * collapsed on every later editor load too, since GatherPress never
 * re-opens it after that first check. This isn't timing flakiness in the
 * test itself, so this opens the panel explicitly instead of assuming it's
 * already expanded.
 *
 * @param {import('@playwright/test').Page} page
 */
async function pinEventFarInFuture( page ) {
	const dateTimeStartButton = page.getByRole( 'button', { name: 'Date & time start' } );

	if ( ! ( await dateTimeStartButton.isVisible() ) ) {
		await page.getByRole( 'button', { name: 'Event settings', exact: true } ).click();
	}

	await dateTimeStartButton.click();
	await page.getByRole( 'spinbutton', { name: 'Year' } ).fill( '2099' );
	await page.keyboard.press( 'Tab' );
	await page.keyboard.press( 'Escape' );
}

module.exports = { pinEventFarInFuture };
