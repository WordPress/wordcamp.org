<?php
namespace WordCamp\QuickBooks\Admin;

use WordCamp\QuickBooks\Client;
use const WordCamp\QuickBooks\{ OAUTH_CAP, PLUGIN_DIR, PLUGIN_PREFIX };

defined( 'WPINC' ) || die();

add_action( 'network_admin_menu', __NAMESPACE__ . '\add_page' );
add_action( 'admin_post_' . PLUGIN_PREFIX . '-oauth', __NAMESPACE__ . '\handle_form_post' );
add_action( 'network_admin_notices', __NAMESPACE__ . '\maybe_show_disconnection_warning' );

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
	$client = new Client();

	if ( $client->has_valid_token() ) {
		$cmd          = 'revoke';
		$button_label = 'Disconnect';
	} else {
		$cmd          = 'authorize';
		$button_label = 'Connect';
	}

	$button_attributes = array(
		'id' => PLUGIN_PREFIX . '-submit-' . esc_attr( $cmd ),
	);
	if ( ! $client->has_sdk() ) {
		$button_attributes['disabled'] = true;
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

	$cmd = filter_input( INPUT_POST, 'cmd' );
	if ( ! $cmd ) {
		$cmd = filter_input( INPUT_GET, 'cmd' );
	}

	switch ( $cmd ) {
		case 'authorize':
			$url = wp_sanitize_redirect( $client->get_authorize_url() );

			add_filter( 'allowed_redirect_hosts', __NAMESPACE__ . '\allow_intuit_domain_redirect', 10, 2 );

			wp_safe_redirect( $url );
			exit();

		case 'exchange':
			$client->maybe_exchange_code_for_token();

			if ( $client->has_error() ) {
				require PLUGIN_DIR . '/views/admin-form-error.php';
				break;
			}

			$url = add_query_arg( 'page', 'quickbooks', network_admin_url( 'settings.php' ) );

			wp_safe_redirect( $url );
			exit();

		case 'revoke':
			$client->revoke_token();

			if ( $client->has_error() ) {
				require PLUGIN_DIR . '/views/admin-form-error.php';
				break;
			}

			$url = add_query_arg( 'page', 'quickbooks', network_admin_url( 'settings.php' ) );

			wp_safe_redirect( $url );
			exit();
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

/**
 * Network admins should know when QBO is not connected.
 *
 * @return void
 */
function maybe_show_disconnection_warning() {
	$client = new Client();

	if ( ! $client->has_valid_token() ) {
		$client->error->add(
			'disconnected',
			sprintf(
				'WordCamp is disconnected from QuickBooks. <a href="%s">Learn more.</a>',
				esc_url( add_query_arg( 'page', 'quickbooks', network_admin_url( 'settings.php' ) ) )
			)
		);
	}

	// Prevent duplicate dependency warnings. Also it doesn't need to be shown on every Network Admin screen.
	if ( ! $client->has_sdk() ) {
		$client->error->remove( 'missing_dependency' );
	}

	require PLUGIN_DIR . '/views/admin-form-error.php';
}
