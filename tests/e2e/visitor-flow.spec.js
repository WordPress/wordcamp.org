const { test, expect } = require( '@playwright/test' );
const { demoBeat, demoUse, installDemoCursor } = require( './utils/demo' );

test.use( demoUse );

test.describe( 'visitor journey', () => {
	test( 'can explore an event and is asked to log in before joining', async ( { page } ) => {
		await installDemoCursor( page );

		const response = await page.goto( '', { waitUntil: 'domcontentloaded' } );
		expect( response.status() ).toBe( 200 );
		await expect( page.getByRole( 'heading', { name: 'WordPress Sunshine Coast' } ) ).toBeVisible();
		await demoBeat( page, 650 );

		await page.getByRole( 'link', { name: 'Browse events' } ).click();
		await expect( page ).toHaveURL( /#upcoming$/ );
		await demoBeat( page, 750 );

		const firstUpcomingEvent = page.locator( '#upcoming .wp-block-post-title a' ).first();
		await expect( firstUpcomingEvent ).toBeVisible();
		await firstUpcomingEvent.click();

		await expect( page.getByRole( 'button', { name: 'View attendees' } ) ).toBeVisible();
		await demoBeat( page, 650 );

		await page.getByRole( 'button', { name: 'View attendees' } ).click();
		const attendees = page.getByRole( 'dialog', { name: 'Event attendees' } );
		await expect( attendees ).toBeVisible();
		await demoBeat( page, 850 );

		await attendees.getByRole( 'button', { name: 'Close' } ).click();
		await expect( attendees ).toBeHidden();
		await demoBeat( page );

		await page.getByRole( 'button', { name: 'Join & RSVP' } ).click();
		await expect( page ).toHaveURL( /wp-login\.php\?redirect_to=/ );
		await expect( page.getByLabel( 'Username or Email Address' ) ).toBeVisible();
		await demoBeat( page, 900 );
	} );
} );
