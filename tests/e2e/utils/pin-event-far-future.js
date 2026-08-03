/**
 * Pins a GatherPress event's start date far in the future via the block
 * editor's "Date & time start" popover.
 *
 * The front page's "Upcoming events" pattern (`groups-site/upcoming-events-cards`)
 * shows only the 3 *soonest* upcoming events network-wide. Left at the
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
 * @param {import('@playwright/test').Page} page
 */
async function pinEventFarInFuture( page ) {
	await page.getByRole( 'button', { name: 'Date & time start' } ).click();
	await page.getByRole( 'spinbutton', { name: 'Year' } ).fill( '2099' );
	await page.keyboard.press( 'Tab' );
	await page.keyboard.press( 'Escape' );
}

module.exports = { pinEventFarInFuture };
