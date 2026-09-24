/**
 * Logs a Playwright `page` into wp-login.php for the current baseURL.
 *
 * Uses standard core wp-login.php field IDs — no plugin-specific login UI
 * exists for the groups front end, so this is the same for every role.
 *
 * On this multisite setup, a valid submission sometimes lands back on a
 * second, ordinary-looking wp-login.php form (same fields, no error
 * notice) instead of completing the login — observed specifically when
 * another test is concurrently logging in as the same user, so it's
 * likely a per-user active-session limit rather than anything wrong with
 * these credentials. Submitting the same credentials again on that second
 * form does complete authentication, so retry a few times before treating
 * it as a real failure — at high enough concurrency (several tests logging
 * in as the same user at once) a single retry isn't always enough.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string}                          username
 * @param {string}                          password
 */
async function login( page, username, password ) {
	await page.goto( 'wp-login.php' );

	const maxAttempts = 4;

	for ( let attempt = 0; attempt < maxAttempts; attempt++ ) {
		await page.locator( '#user_login' ).fill( username );
		await page.locator( '#user_pass' ).fill( password );
		await page.locator( '#wp-submit' ).click();
		await page.waitForLoadState( 'networkidle' );

		if ( ! new URL( page.url() ).pathname.endsWith( 'wp-login.php' ) ) {
			return;
		}
	}

	// Fail here, with the real reason, rather than downstream on a missing
	// group-site element, if authentication did not actually succeed.
	throw new Error( `login( '${ username }' ) failed — still on wp-login.php after ${ maxAttempts } attempts.` );
}

module.exports = { login };
