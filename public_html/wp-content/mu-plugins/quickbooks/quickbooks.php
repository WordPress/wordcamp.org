<?php
namespace WordCamp\QuickBooks;

defined( 'WPINC' ) || die();

const PLUGIN_DIR    = __DIR__;
const PLUGIN_PREFIX = 'wordcamp-qbo';
const OAUTH_CAP     = 'manage_network';

require_once PLUGIN_DIR . '/includes/client.php';
require_once PLUGIN_DIR . '/includes/admin.php';

add_filter( 'wordcamp_qbo_client_config', __NAMESPACE__ . '\set_client_config' );
add_action( 'init', __NAMESPACE__  . '\check_qbo_connection_cron' );
add_action( 'check_qbo_connection_action', __NAMESPACE__ . '\check_qbo_connection' );

/**
 * Setup hourly qbo connection task.
 */
function check_qbo_connection_cron() {
	if ( ! wp_next_scheduled( 'check_qbo_connection_action' ) ) {
		wp_schedule_event( time(), 'hourly', 'check_qbo_connection_action' );
	}
}

/**
 * Monitors the Quickbooks connection, and sends message to Slack if not connected.
 */
function check_qbo_connection() {
	$client  = get_client();

	if ( ! $client->has_valid_token() && function_exists( 'slack_dm' ) ) {
		slack_dm( 'QBO token invalid. Visit <https://wordcamp.org/wp-admin/network/settings.php> to check connection.', 'dotorg' );
	}
}

/**
 * Filter: Add client configuration details depending on the environment.
 *
 * @param array $config
 *
 * @return array
 */
function set_client_config( array $config ) {
	$environment = get_wordcamp_environment();

	switch ( $environment ) {
		// Sandboxes use the production database, so we should also use the production QBO account.
		// Otherwise production invoices would be sent to the sandbox company during sandbox testing, and it
		// wouldn't be possible to debug production errors on a sandbox.
		case 'production':
		case 'staging':
		case 'development':
			if ( defined( 'WORDCAMP_PRODUCTION_QBO_CLIENT_ID' ) && defined( 'WORDCAMP_PRODUCTION_QBO_CLIENT_SECRET' ) ) {
				$config = array_merge(
					$config,
					array(
						'ClientID'     => WORDCAMP_PRODUCTION_QBO_CLIENT_ID,
						'ClientSecret' => WORDCAMP_PRODUCTION_QBO_CLIENT_SECRET,
						'baseUrl'      => 'Production',
					)
				);
			}
			break;

		case 'local':
		default:
			if ( defined( 'WORDCAMP_SANDBOX_QBO_CLIENT_ID' ) && defined( 'WORDCAMP_SANDBOX_QBO_CLIENT_SECRET' ) ) {
				$config = array_merge(
					$config,
					array(
						'ClientID'     => WORDCAMP_SANDBOX_QBO_CLIENT_ID,
						'ClientSecret' => WORDCAMP_SANDBOX_QBO_CLIENT_SECRET,
						'baseUrl'      => 'Development',
					)
				);
			}
			break;
	}

	return $config;
}

/**
 * Instantiate the client. Helps avoid creating multiple instances in one session.
 *
 * @return Client
 */
function get_client() {
	static $client;

	if ( ! $client instanceof Client ) {
		$client = new Client();
	}

	return $client;
}
