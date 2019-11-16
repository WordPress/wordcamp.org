<?php
namespace WordCamp\QuickBooks;

defined( 'WPINC' ) || die();

const PLUGIN_DIR    = __DIR__;
const PLUGIN_PREFIX = 'wordcamp-qbo';
const OAUTH_CAP     = 'manage_network';

require_once PLUGIN_DIR . '/includes/client.php';
require_once PLUGIN_DIR . '/includes/admin.php';

add_filter( 'wordcamp_qbo_client_config', __NAMESPACE__ . '\set_client_config' );

/**
 * Helper to determine the current environment.
 *
 * @return string
 */
function get_environment() {
	return ( defined( 'WORDCAMP_ENVIRONMENT' ) ) ? WORDCAMP_ENVIRONMENT : 'development';
}

/**
 * The option key for storing/retrieving OAuth details.
 *
 * @return string
 */
function get_oauth_option_key() {
	return PLUGIN_PREFIX . '_oauth_' . get_environment();
}

/**
 * Save the details of an OAuth token to the database.
 *
 * @param string $access
 * @param string $refresh
 * @param string $realm_id
 *
 * @return bool
 */
function save_oauth_token( $access, $refresh, $realm_id ) {
	$token = array(
		'accessTokenKey'  => $access,
		'refreshTokenKey' => $refresh,
		'QBORealmID'      => $realm_id,
	);

	return update_site_option( get_oauth_option_key(), $token );
}

/**
 * Delete an OAuth token from the database.
 *
 * @return bool
 */
function delete_oauth_token() {
	return delete_site_option( get_oauth_option_key() );
}

/**
 * Get a stored OAuth token from the database.
 *
 * @return array
 */
function get_oauth_token() {
	return get_site_option( get_oauth_option_key(), array() );
}

/**
 * Filter: Add client configuration details depending on the environment.
 *
 * @param array $config
 *
 * @return array
 */
function set_client_config( array $config ) {
	$environment = get_environment();

	switch ( $environment ) {
		case 'production':
			if ( defined( 'WORDCAMP_PRODUCTION_QBO_CLIENT_ID' ) && defined( 'WORDCAMP_PRODUCTION_QBO_CLIENT_SECRET' ) ) {
				$config = array_merge(
					$config,
					array(
						'ClientID'     => WORDCAMP_PRODUCTION_QBO_CLIENT_ID,
						'ClientSecret' => WORDCAMP_PRODUCTION_QBO_CLIENT_SECRET,
						'baseUrl'      => 'Production',
					),
					get_oauth_token()
				);
			}
			break;

		case 'development':
		default:
			if ( defined( 'WORDCAMP_SANDBOX_QBO_CLIENT_ID' ) && defined( 'WORDCAMP_SANDBOX_QBO_CLIENT_SECRET' ) ) {
				$config = array_merge(
					$config,
					array(
						'ClientID'     => WORDCAMP_SANDBOX_QBO_CLIENT_ID,
						'ClientSecret' => WORDCAMP_SANDBOX_QBO_CLIENT_SECRET,
						'baseUrl'      => 'Development',
					),
					get_oauth_token()
				);
			}
			break;
	}

	return $config;
}
