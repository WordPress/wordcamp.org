const { test, expect } = require( '@playwright/test' );
const { login } = require( './utils/login' );

/**
 * Recurrence controls in the organiser-facing group settings block.
 *
 * Requires the `organiser1` / `password` test user from the local Groups
 * environment setup.
 */
test.describe( 'recurring events frontend', () => {
	test( 'an organiser can configure a weekly recurrence', async ( { page } ) => {
		await login( page, 'organiser1', 'password' );
		await page.goto( '' );

		await page.locator( '[data-wporg-settings-open]' ).click();
		const dialog = page.getByRole( 'dialog' );
		await dialog.getByRole( 'button', { name: '+ Create event', exact: true } ).click();

		const recurrence = dialog.getByRole( 'group', { name: 'Repeating event' } );
		await expect( recurrence ).toBeVisible();
		await recurrence.getByLabel( 'Repeats' ).selectOption( 'weekly' );

		await expect( recurrence.getByLabel( 'Repeat every' ) ).toHaveValue( '1' );
		await expect( recurrence.locator( 'input[type="checkbox"]:checked' ) ).toHaveCount( 1 );

		await recurrence.getByLabel( 'Ends' ).selectOption( 'count' );
		await recurrence.getByLabel( 'Occurrences' ).fill( '3' );
		await expect( recurrence.getByLabel( 'Occurrences' ) ).toHaveValue( '3' );
	} );
} );
