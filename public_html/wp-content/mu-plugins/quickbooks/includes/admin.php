<?php
namespace WordCamp\QuickBooks\Admin;

use const WordCamp\QuickBooks\{ OAUTH_CAP, PLUGIN_DIR, PLUGIN_PREFIX };
use function WordCamp\QuickBooks\{ get_client };

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
	$client = get_client();

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

	$client = get_client();

	if ( $client->has_error() ) {
		require PLUGIN_DIR . '/views/admin-form-error.php';

		return;
	}

	$cmd = filter_input( INPUT_POST, 'cmd' );
	if ( ! $cmd ) {
		$cmd = filter_input( INPUT_GET, 'cmd' );
	}

	$nonce = filter_input( INPUT_POST, PLUGIN_PREFIX . '_oauth_' . $cmd );

	switch ( $cmd ) {
		case 'authorize':
			if ( wp_verify_nonce( $nonce, $cmd ) ) {
				$url = wp_sanitize_redirect( $client->get_authorize_url() );

				add_filter( 'allowed_redirect_hosts', __NAMESPACE__ . '\allow_intuit_domain_redirect', 10, 2 );

				wp_safe_redirect( $url );
			} else {
				$client->error->add(
					'invalid_nonce',
					'Your request could not be validated.'
				);

				require PLUGIN_DIR . '/views/admin-form-error.php';
			}
			exit();

		case 'exchange':
			// See \WordCamp\QuickBooks\Client::oauth_redirect_uri.

			$client->maybe_exchange_code_for_token();

			if ( $client->has_error() ) {
				require PLUGIN_DIR . '/views/admin-form-error.php';
				break;
			}

			$url = add_query_arg( 'page', 'quickbooks', network_admin_url( 'settings.php' ) );

			wp_safe_redirect( $url );
			exit();

		case 'revoke':
			if ( wp_verify_nonce( $nonce, $cmd ) ) {
				$client->revoke_token();

				if ( $client->has_error() ) {
					require PLUGIN_DIR . '/views/admin-form-error.php';
					break;
				}

				$url = add_query_arg( 'page', 'quickbooks', network_admin_url( 'settings.php' ) );

				wp_safe_redirect( $url );
			} else {
				$client->error->add(
					'invalid_nonce',
					'Your request could not be validated.'
				);

				require PLUGIN_DIR . '/views/admin-form-error.php';
			}
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
	global $pagenow, $plugin_page;

	// Most contributors won't need to connect to QBO, or be able to. It's very important to warn on production,
	// though.
	if (
		'local' === wp_get_environment_type()
		|| 'settings_page_quickbooks' === get_plugin_page_hook( $plugin_page, $pagenow )
	) {
		return;
	}

	$client = get_client();

	if ( ! $client->has_valid_token() ) {
		// We don't need any other errors to appear on every Network screen, just this special disconnection one.
		$client->error->errors     = array();
		$client->error->error_data = array();

		$client->error->add(
			'disconnected',
			sprintf(
				'WordCamp is disconnected from QuickBooks. Please ask a developer to <a href="%s">connect it.</a>',
				esc_url( add_query_arg( 'page', 'quickbooks', network_admin_url( 'settings.php' ) ) )
			)
		);

		require PLUGIN_DIR . '/views/admin-form-error.php';
	}
}
