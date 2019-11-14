<?php
namespace WordCamp\QuickBooks;

defined( 'WPINC' ) || die();

const PLUGIN_DIR = __DIR__;
const OAUTH_CAP  = 'manage_network';

add_action( 'plugins_loaded', __NAMESPACE__ . '\load_files' );

/**
 * Conditionally load files.
 *
 * @return void
 */
function load_files() {
	require_once PLUGIN_DIR . '/includes/client.php';

	if ( is_network_admin() ) {
		require_once PLUGIN_DIR . '/includes/admin.php';
	}
}
