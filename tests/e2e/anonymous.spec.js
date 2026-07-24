const { test, expect } = require( '@playwright/test' );

/**
 * A logged-out visitor should be able to browse the group site normally,
 * and should never see any event/member management UI (that's gated to
 * Organiser/Event Organiser roles — see the group settings + event-manage
 * block permission checks in the groups-gatherpress-compat-test skill).
 */
test.describe( 'anonymous visitor', () => {
	test( 'front page renders', async ( { page } ) => {
		const response = await page.goto( '' );
		expect( response.status() ).toBe( 200 );
	} );

	test( 'no management UI is rendered on the front page', async ( { page } ) => {
		await page.goto( '/' );

		// The "Manage" button from the `wporg/event-manage` block should be
		// entirely absent for a logged-out visitor, not merely disabled.
		await expect( page.locator( '.wp-element-button', { hasText: /manage/i } ) ).toHaveCount( 0 );
	} );
} );
