/**
 * Logs a Playwright `page` into wp-login.php for the current baseURL.
 *
 * Uses standard core wp-login.php field IDs — no plugin-specific login UI
 * exists for the groups front end, so this is the same for every role.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} username
 * @param {string} password
 */
async function login( page, username, password ) {
	await page.goto( 'wp-login.php' );
	await page.locator( '#user_login' ).fill( username );
	await page.locator( '#user_pass' ).fill( password );
	await page.locator( '#wp-submit' ).click();
	await page.waitForLoadState( 'networkidle' );
}

module.exports = { login };
