const { test: setup, expect } = require( '@playwright/test' );
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

		// The editor's welcome guide and starter-pattern picker cover a new post, and closing them is a race.
		const nonce = await ( await page.request.get( 'wp-admin/admin-ajax.php?action=rest-nonce' ) ).text();
		const response = await page.request.post( 'wp-json/wp/v2/users/me', {
			headers: { 'X-WP-Nonce': nonce },
			data: {
				meta: {
					persisted_preferences: {
						core: { enableChoosePatternModal: false },
						'core/edit-post': { welcomeGuide: false },
						_modified: new Date().toISOString(),
					},
				},
			},
		} );
		expect( response.ok() ).toBe( true );
	} );
}
