<?php
namespace WordCamp\QuickBooks\Admin;

use WordCamp\QuickBooks\Client;
use const WordCamp\QuickBooks\{ OAUTH_CAP, PLUGIN_DIR, PLUGIN_PREFIX };

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
	$errors   = array();
	$messages = array();

	$client = new Client();

	if ( $client->has_error() ) {
		$errors = array_merge( $errors, $client->error->get_error_messages() );
	}

	$cmd    = 'authorize';
	$button = 'Authorize';

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

	$cmd = filter_input( INPUT_POST, 'cmd' );

	switch ( $cmd ) {
		case 'authorize':

			break;
	}
}
