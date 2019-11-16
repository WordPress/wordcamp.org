<?php
namespace WordCamp\QuickBooks\Admin;

use WordCamp\QuickBooks\Client;
use const WordCamp\QuickBooks\{ OAUTH_CAP, PLUGIN_DIR, PLUGIN_PREFIX };
use function WordCamp\Quickbooks\{ save_oauth_token, delete_oauth_token };

defined( 'WPINC' ) || die();

add_action( 'network_admin_menu', __NAMESPACE__ . '\add_page' );
add_action( 'admin_post_' . PLUGIN_PREFIX . '-oauth', __NAMESPACE__ . '\handle_form_post' );

/**
 * Add a settings page.
 *
 * @return void
 */
function add_page() {
	add_submenu_page(
		'settings.php',
		'QuickBooks',
		'QuickBooks',
		OAUTH_CAP,
		'quickbooks',
		__NAMESPACE__ . '\render_page'
	);
}

/**
 * Render the settings page.
 *
 * @return void
 */
function render_page() {
	$errors = array();
	$client = new Client();

	maybe_request_token( $client );

	if ( $client->has_valid_token() ) {
		$cmd    = 'revoke';
		$button = 'Disconnect';
	} else {
		$cmd    = 'authorize';
		$button = 'Connect';
	}

	if ( $client->has_error() ) {
		$errors = array_merge( $errors, $client->error->get_error_messages() );
	}

	require PLUGIN_DIR . '/views/admin.php';
}

/**
 * Handle submissions from the settings form.
 *
 * @return void
 */
function handle_form_post() {
	if ( ! current_user_can( OAUTH_CAP ) ) {
		wp_die( 'You do not have permission to perform this action.' );
	}

	$client = new Client();
	$cmd    = filter_input( INPUT_POST, 'cmd' );

	switch ( $cmd ) {
		case 'authorize':
			$url = wp_sanitize_redirect( $client->get_authorize_url() );

			add_filter( 'allowed_redirect_hosts', __NAMESPACE__ . '\allow_intuit_domain_redirect', 10, 2 );

			wp_safe_redirect( $url );
			exit();

		case 'revoke':
			$client->revoke_token();
			delete_oauth_token();

			$url = add_query_arg( 'page', 'quickbooks', network_admin_url( 'settings.php' ) );

			wp_safe_redirect( $url );
			exit();
	}
}

/**
 * Complete the OAuth connection process if the right data is available.
 *
 * Once a user has authorized a connection on the Intuit site, they are redirected back to our page, along with
 * an authorization code and realm ID. If those two pieces of data are in the $_GET, and we don't already have a valid
 * token, we need to send a request to get the token.
 *
 * @param Client $client
 *
 * @return void
 */
function maybe_request_token( Client &$client ) {
	$authorization_code = filter_input( INPUT_GET, 'code' );
	$realm_id           = filter_input( INPUT_GET, 'realmId' );

	if ( ! $client->has_valid_token() && $authorization_code && $realm_id ) {
		$token = $client->exchange_code_for_token( $authorization_code, $realm_id );

		if ( is_array( $token ) ) {
			save_oauth_token( ...$token );
		}
	}
}

/**
 * Add an Intuit domain to the safe redirect list so we can go complete the OAuth process.
 *
 * This filter should only be added right before doing the redirect, so that the Intuit domain isn't always
 * considered "safe".
 *
 * @param array  $allowed_domains
 * @param string $domain
 *
 * @return array
 */
function allow_intuit_domain_redirect( $allowed_domains, $domain ) {
	if ( preg_match( '#\.intuit\.com$#', $domain ) ) {
		$allowed_domains[] = $domain;
	}

	return array_unique( $allowed_domains );
}
