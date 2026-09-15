const { test, expect } = require( '@playwright/test' );

/**
 * The "Add to calendar" control on an event page.
 *
 * The block was in the template already, but self-closing: GatherPress's
 * `add-to-calendar` is a static wrapper whose entire output is its inner
 * blocks, so with none it rendered nothing and the control was simply absent
 * (#2028). The inner blocks now live in the template, and GatherPress swaps
 * the placeholder hrefs for the event's real calendar endpoints at render.
 *
 * Runs as an anonymous visitor: adding an event to your own calendar needs no
 * account. Reads an existing published event, so it leaves no fixtures behind.
 */
test.describe( 'add to calendar', () => {
	test( 'offers the four calendar links on an event page', async ( { page } ) => {
		const response = await page.request.get(
			'wp-json/wp/v2/gatherpress_events?per_page=1&status=publish&_fields=link'
		);
		expect( response.ok() ).toBe( true );
		const [ event ] = await response.json();
		expect( event, 'the group needs a published event' ).toBeTruthy();

		await page.goto( event.link );

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
				`${ event.link }${ endpoint }/`
			);
		}

		// The menu carries a fixed pixel width, and the card it opens in is
		// narrower than that: unanchored it ran off the page.
		const overflow = await page.evaluate(
			() => document.documentElement.scrollWidth - document.documentElement.clientWidth
		);
		expect( overflow ).toBe( 0 );

		const ics = await page.request.get( `${ event.link }ical/` );
		expect( ics.ok() ).toBe( true );
		expect( ics.headers()[ 'content-type' ] ).toContain( 'text/calendar' );
	} );
} );
