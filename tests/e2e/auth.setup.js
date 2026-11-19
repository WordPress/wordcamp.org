const { test: setup } = require( '@playwright/test' );
const { STORAGE_STATE } = require( './utils/accounts' );

/*
 * Logs in once per account and saves the session for the specs to share.
 *
 * WordPress keeps all of a user's sessions in one `session_tokens` user meta
 * row and rewrites the whole row on login, so logins as the same user that
 * overlap drop each other's session. The loser is sent back to wp-login.php
 * on its next request. A saved session is never rewritten that way.
 */
for ( const [ username, statePath ] of Object.entries( STORAGE_STATE ) ) {
	setup( `log in as ${ username }`, async ( { page } ) => {
		await page.goto( 'wp-login.php' );
		await page.locator( '#user_login' ).fill( username );
		await page.locator( '#user_pass' ).fill( 'password' );
		await page.locator( '#wp-submit' ).click();
		await page.waitForURL( ( url ) => ! url.pathname.endsWith( 'wp-login.php' ) );

		await page.context().storageState( { path: statePath } );
	} );
}
