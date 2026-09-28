const { test, expect } = require( '@playwright/test' );
const { login } = require( './utils/login' );

/**
 * Recurrence controls in the organizer-facing group settings block.
 *
 * Requires the `organiser1` / `password` test user from the local Groups
 * environment setup.
 */
test.describe( 'recurring events frontend', () => {
	test( 'an organizer can configure a weekly recurrence', async ( { page } ) => {
		await login( page, 'organiser1', 'password' );
		await page.goto( '' );

		await page.locator( '[data-wporg-settings-open]' ).click();
		const dialog = page.getByRole( 'dialog' );
		await dialog.getByRole( 'button', { name: '+ Create event', exact: true } ).click();

		await dialog.getByLabel( 'Repeats' ).selectOption( 'weekly' );

		await expect( dialog.getByLabel( 'Repeat every' ) ).toHaveValue( '1' );
		await expect( dialog.getByRole( 'group', { name: 'Repeat on' } ).locator( 'input[type="checkbox"]:checked' ) ).toHaveCount( 1 );

		await dialog.getByLabel( 'Ends' ).selectOption( 'count' );
		await dialog.getByLabel( 'Occurrences' ).fill( '3' );
		await expect( dialog.getByLabel( 'Occurrences' ) ).toHaveValue( '3' );
	} );
} );
