const { test, expect } = require( '@playwright/test' );
const { login } = require( './utils/login' );

/**
 * The "Add to calendar" control on an event page.
 *
 * The block was in the template already, but self-closing: GatherPress's
 * `add-to-calendar` is a static wrapper whose entire output is its inner
 * blocks, so with none it rendered nothing and the control was simply absent
 * (#2028). The inner blocks now live in the template, and GatherPress swaps
 * the placeholder hrefs for the event's real calendar endpoints at render.
 *
 * Requires the `organiser7` / `password` editor-tier test user. One account
 * per test: this environment only supports a single active session per user
 * and specs run concurrently under `fullyParallel` (see utils/login.js).
 */
test.describe( 'add to calendar', () => {
	// Far enough out to stay clear of the front page's capped "Upcoming events"
	// ranking that other specs' events compete for (see utils/pin-event-far-future.js).
	const DATE = '2099-03-04'; // 2099-03-04 is a Wednesday.
	const START = '12:00';

	test( 'offers the four calendar links on an event page', async ( { page, request } ) => {
		test.slow(); // A cold boot of the inline block editor behind the modal.

		await login( page, 'organiser7', 'password' );

		/*
		 * Publish an event of our own rather than reading whatever the archive
		 * happens to hold. CI seeds no events at all, and the specs that create
		 * them run concurrently with this one.
		 */
		const title = `Add to calendar E2E ${ Date.now() }`;
		await page.goto( '' );
		await page.getByRole( 'button', { name: '+ Create event', exact: true } ).click();

		const modal = page.locator( '.wporg-groups-event-modal' );
		await modal.getByLabel( 'Event title' ).fill( title );
		await modal.getByLabel( 'Date', { exact: true } ).fill( DATE );
		await modal.getByLabel( 'Start time' ).fill( START );
		await modal.getByLabel( 'Duration' ).selectOption( { label: '1 hour' } );
		await modal.getByRole( 'button', { name: 'Create event' } ).click();
		await page.waitForURL( /\/event\//, { timeout: 30000 } );

		const eventUrl = page.url().split( '?' )[ 0 ].replace( /\/?$/, '/' );
		const trigger = page.getByRole( 'button', { name: 'Add to calendar' } );
		const menu = page.locator( '.groups-site-add-to-calendar .wp-block-gatherpress-dropdown__menu' );

		await expect( trigger ).toBeVisible();
		await expect( menu ).toBeHidden();

		await trigger.click();
		await expect( menu ).toBeVisible();

		// The hrefs are the assertion that matters: a placeholder left in
		// place (#gatherpress-google-calendar) renders a visible menu too.
		for ( const [ name, endpoint ] of [
			[ 'Google Calendar', 'google-calendar' ],
			[ 'iCal', 'ical' ],
			[ 'Outlook', 'outlook' ],
			[ 'Yahoo Calendar', 'yahoo-calendar' ],
		] ) {
			await expect( menu.getByRole( 'link', { name, exact: true } ) ).toHaveAttribute(
				'href',
				`${ eventUrl }${ endpoint }/`
			);
		}

		// The menu carries a fixed pixel width, and the card it opens in is
		// narrower than that: unanchored it ran off the page.
		const overflow = await page.evaluate(
			() => document.documentElement.scrollWidth - document.documentElement.clientWidth
		);
		expect( overflow ).toBe( 0 );

		/*
		 * `request` is its own context, so these run as a logged-out visitor:
		 * adding an event to your own calendar needs no account, and the
		 * block's own gate is whether the event is viewable.
		 */
		const anonymousPage = await request.get( eventUrl );
		expect( anonymousPage.ok() ).toBe( true );
		const html = await anonymousPage.text();
		expect( html ).toContain( `${ eventUrl }google-calendar/` );
		expect( html ).not.toContain( '#gatherpress-google-calendar' );

		const ics = await request.get( `${ eventUrl }ical/` );
		expect( ics.ok() ).toBe( true );
		expect( ics.headers()[ 'content-type' ] ).toContain( 'text/calendar' );
	} );
} );
