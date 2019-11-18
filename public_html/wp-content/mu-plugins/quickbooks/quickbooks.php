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
 * Filter: Add client configuration details depending on the environment.
 *
 * @param array $config
 *
 * @return array
 */
function set_client_config( array $config ) {
	$environment = ( defined( 'WORDCAMP_ENVIRONMENT' ) ) ? WORDCAMP_ENVIRONMENT : 'development';

	switch ( $environment ) {
		case 'production':
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

		case 'development':
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
