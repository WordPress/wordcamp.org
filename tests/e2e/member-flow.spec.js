const { test, expect } = require( '@playwright/test' );
const { demoBeat, demoUse, getRestNonce, installDemoCursor, loginForDemo } = require( './utils/demo' );

test.use( demoUse );

/**
 * Reset the demo member through the same public REST interfaces used by the
 * front end so the join and RSVP journey is repeatable.
 *
 * @param {import('@playwright/test').Page} page
 */
async function resetMemberState( page ) {
	const nonce = await getRestNonce( page );
	const options = {
		headers: { 'X-WP-Nonce': nonce },
		failOnStatusCode: false,
	};

	await page.request.post( 'wp-json/wporg-groups/v1/members/notification-preference', {
		...options,
		data: { opt_in: false },
	} );
	await page.request.post( 'wp-json/gatherpress/v1/event/rsvp', {
		...options,
		data: {
			post_id: 82,
			status: 'not_attending',
			guests: 0,
			anonymous: 0,
		},
	} );
	await page.request.delete( 'wp-json/wporg-groups/v1/members/leave', options );
}

test.describe( 'member journey', () => {
	test( 'can join, opt into updates, RSVP, and find the event on their calendar', async ( { page } ) => {
		await loginForDemo( page, 'grouptestmember', 'password' );
		await resetMemberState( page );
		await installDemoCursor( page );

		await page.goto( '', { waitUntil: 'domcontentloaded' } );
		const joinGroup = page.getByRole( 'button', { name: 'Join this group' } );
		await expect( joinGroup ).toBeVisible();
		await demoBeat( page, 650 );

		await joinGroup.click();
		await expect( page.getByText( 'Member', { exact: true } ) ).toBeVisible();
		await demoBeat( page, 650 );

		const updates = page.getByRole( 'checkbox', { name: /Email me updates/ } );
		await updates.check();
		await expect( page.getByRole( 'status' ) ).toHaveText( 'Email preference saved.' );
		await demoBeat( page, 650 );

		await page.getByRole( 'link', { name: 'Browse events' } ).click();
		await demoBeat( page, 650 );

		const eventLink = page.locator( '#upcoming .wp-block-post-title a' ).first();
		const eventTitle = await eventLink.innerText();
		await eventLink.click();
		await expect( page.getByRole( 'button', { name: 'RSVP' } ) ).toBeVisible();
		await demoBeat( page, 600 );

		await page.getByRole( 'button', { name: 'RSVP' } ).click();
		await expect( page.getByRole( 'button', { name: /Attending/ } ) ).toBeVisible();
		await expect( page.getByRole( 'status' ) ).toHaveText( 'You are now attending this event.' );
		await demoBeat( page, 800 );

		await page.getByRole( 'link', { name: 'WordPress Sunshine Coast', exact: true } ).first().click();
		const myEvents = page.getByRole( 'heading', { name: 'My upcoming events' } );
		await myEvents.scrollIntoViewIfNeeded();
		await expect( page.locator( '.wporg-my-events__title', { hasText: eventTitle } ) ).toBeVisible();
		await demoBeat( page, 900 );
	} );
} );
