<?php
/**
 * WordCamp.org configuration file.
 */

define( 'IS_WORDCAMP_NETWORK', true );

/**
 * The environment in which the WordCamp codebase is currently running.
 *
 * There are a few different values that this might contain that have implications in the code:
 *
 * - `production`:  Used on the production server. Should not be used anywhere else.
 * - `development`: The catchall value for non-production environments. Currently used on wporg sandboxes.
 * - `local`:       The value used for local development environments, where the domain is wordcamp.test and some
 *                  functionality that relies on remote connections may not be available.
 */
define( 'WORDCAMP_ENVIRONMENT', 'local' );

/*
 * Database
 */
define( 'DB_NAME',     'wordcamp_dev' );
define( 'DB_USER',     'root'         );
define( 'DB_PASSWORD', 'mysql'        );
define( 'DB_HOST',     'wordcamp.db'  );

// Force utf8mb4 since HyperDB won't admit it supports it.
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', 'utf8mb4_general_ci' );

define( 'CUSTOM_USER_TABLE',      'wc_users'    );
define( 'CUSTOM_USER_META_TABLE', 'wc_usermeta' );

$table_prefix = 'wc_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride

/*
 * Multisite
 */
if ( 'buddycamp.test' === $_SERVER['HTTP_HOST'] || '.buddycamp.test' === substr( $_SERVER['HTTP_HOST'], strlen( $_SERVER['HTTP_HOST'] ) - 14, 14 ) ) {
	$wcorg_domain_current_site = 'buddycamp.test';
} else {
	$wcorg_domain_current_site = 'wordcamp.test';
}

define( 'WP_ALLOW_MULTISITE',   true ); // Temporary workaround for https://github.com/Automattic/wp-super-cache/issues/97.
define( 'MULTISITE',            true );
define( 'SUBDOMAIN_INSTALL',    true );
define( 'DOMAIN_CURRENT_SITE',  $wcorg_domain_current_site );
define( 'PATH_CURRENT_SITE',    '/' );
define( 'SITE_ID_CURRENT_SITE',  1 );
define( 'BLOG_ID_CURRENT_SITE',  5 ); // central.wordcamp.test.
define( 'NOBLOGREDIRECT',       'https://central.wordcamp.test' );
define( 'SUNRISE',               true );
define( 'CLI_HOSTNAME_OVERRIDE', 'wordcamp.test' );


/*
 * Logging/Debugging
 */
define( 'WP_DEBUG',         true );
define( 'WP_DEBUG_LOG',     true );
define( 'WP_DEBUG_DISPLAY', true );
define( 'SAVEQUERIES',      true );
define( 'DIEONDBERROR',     false );
define( 'SCRIPT_DEBUG',     false ); // Temporarily disabled because of https://github.com/WordPress/gutenberg/issues/7897 / https://github.com/WordPress/gutenberg/issues/11360.
define( 'JETPACK_DEV_DEBUG', true );


/*
 * Salts
 *
 * It doesn't matter for local environments, but use `wp config shuffle-salts` to change this in production
 * environments, because generating the keys locally is safer than using the API (and exposing the keys to
 * your OS/browser if you copy/paste, etc).
 */
define( 'AUTH_KEY',         'put your unique phrase here' );
define( 'SECURE_AUTH_KEY',  'put your unique phrase here' );
define( 'LOGGED_IN_KEY',    'put your unique phrase here' );
define( 'NONCE_KEY',        'put your unique phrase here' );
define( 'AUTH_SALT',        'put your unique phrase here' );
define( 'SECURE_AUTH_SALT', 'put your unique phrase here' );
define( 'LOGGED_IN_SALT',   'put your unique phrase here' );
define( 'NONCE_SALT',       'put your unique phrase here' );


/*
 * Misc
 */
define( 'WPLANG',          '' );
define( 'WP_CONTENT_DIR', __DIR__ . '/wp-content' );
define( 'WP_CONTENT_URL', 'https://' . preg_replace( '/[^-_.0-9a-z:]/i', '', $_SERVER['HTTP_HOST'] ) . '/wp-content' );
define( 'WP_TEMP_DIR',    '/tmp' );

define( 'FORCE_SSL_ADMIN',          true );
define( 'DISALLOW_UNFILTERED_HTML', true );

define( 'WORDCAMP_SCRIPT_DIR',     __DIR__ . '/bin' );
define( 'WP_CLI_SCRIPT_DIR',       WORDCAMP_SCRIPT_DIR . '/wp-cli/' );
define( 'SCRIPT_BACKUP_DIRECTORY', WP_TEMP_DIR );
define( 'WORDCAMP_UTILITIES_DIR',  WP_CONTENT_DIR . '/mu-plugins/utilities' );

define( 'EMAIL_DEVELOPER_NOTIFICATIONS', 'developers@example.test' );
define( 'EMAIL_CENTRAL_SUPPORT',         'support@wordcamp.test' );

$trusted_deputies = array(
	3, // role-tester.
);


/*
 * Third party services
 */
define( 'SLACK_ERROR_REPORT_URL',      '(Optional) you can configure slack and add your webhook url. Errors will be posted to this slack url' );
define( 'WORDCAMP_LOGS_SLACK_CHANNEL', '(Optional) @your_slack_username' );
define( 'WORDCAMP_LOGS_JETPACK_SLACK_CHANNEL',   '(Optional) @your_slack_username' );
define( 'WORDCAMP_LOGS_GUTENBERG_SLACK_CHANNEL', '(Optional) @your_slack_username' );

define( 'TWITTER_CONSUMER_KEY_WORDCAMP_CENTRAL',    '' );
define( 'TWITTER_CONSUMER_SECRET_WORDCAMP_CENTRAL', '' );
define( 'TWITTER_BEARER_TOKEN_WORDCAMP_CENTRAL',    '' );

define( 'WORDCAMP_QBO_HMAC_KEY',        'localhmac' );

define( 'WORDCAMP_SANDBOX_QBO_CLIENT_ID',        "(Optional) your app's development client ID key goes here" );
define( 'WORDCAMP_SANDBOX_QBO_CLIENT_SECRET',    "(Optional) your app's development client secret key goes here" );
define( 'WORDCAMP_PRODUCTION_QBO_CLIENT_ID',     '' );
define( 'WORDCAMP_PRODUCTION_QBO_CLIENT_SECRET', '' );

define( 'REMOTE_CSS_GITHUB_ID',         '' );
define( 'REMOTE_CSS_GITHUB_SECRET',     '' );

define( 'WORDCAMP_LE_HELPER_API_KEY', '' );
define( 'GENDERIZE_IO_API_KEY',       '' );

define( 'WC_SANDBOX_PAYPAL_NVP_USERNAME',  '' );
define( 'WC_SANDBOX_PAYPAL_NVP_PASSWORD',  '' );
define( 'WC_SANDBOX_PAYPAL_NVP_SIGNATURE', '' );
define( 'WPCS_PAYPAL_NVP_USERNAME',        '' );
define( 'WPCS_PAYPAL_NVP_PASSWORD',        '' );
define( 'WPCS_PAYPAL_NVP_SIGNATURE',       '' );

define( 'WORDCAMP_CAMPTIX_STRIPE_TEST_PUBLIC', '' );
define( 'WORDCAMP_CAMPTIX_STRIPE_TEST_SECRET', '' );
define( 'WORDCAMP_CAMPTIX_STRIPE_LIVE_PUBLIC', '' );
define( 'WORDCAMP_CAMPTIX_STRIPE_LIVE_SECRET', '' );

define( 'WORDCAMP_PAYMENTS_ENCRYPTION_KEY',         '' );
define( 'WORDCAMP_PAYMENTS_HMAC_KEY',               '' );
define( 'WORDCAMP_PAYMENT_STRIPE_HMAC',             '' );
define( 'WORDCAMP_PAYMENT_STRIPE_PUBLISHABLE',      '' );
define( 'WORDCAMP_PAYMENT_STRIPE_SECRET',           '' );
define( 'WORDCAMP_PAYMENT_STRIPE_PUBLISHABLE_LIVE', '' );
define( 'WORDCAMP_PAYMENT_STRIPE_SECRET_LIVE',      '' );

define( 'WORDCAMP_DEV_GOOGLE_MAPS_API_KEY', '' );

define( 'MEETUP_API_BASE_URL', 'https://api.meetup.com/' );
define( 'MEETUP_MEMBER_ID',     72560962                 );
define( 'MEETUP_OAUTH_CONSUMER_KEY', '' );
define( 'MEETUP_OAUTH_CONSUMER_SECRET', '' );
define( 'MEETUP_OAUTH_CONSUMER_REDIRECT_URI', '' );
define( 'MEETUP_USER_EMAIL', '' );
define( 'MEETUP_USER_PASSWORD', '' );

define( 'WORDCAMP_FIXER_API_KEY', '' );
define( 'WORDCAMP_OXR_API_KEY',   '' );


/*
 * WP Super Cache
 */
define( 'WP_CACHE',       true );
define( 'WPCACHEHOME',    WP_CONTENT_DIR . '/plugins/wp-super-cache/' );
define( 'SUBMITDISABLED', false ); // Work around https://github.com/Automattic/wp-super-cache/issues/213.
define( 'WP_CACHE_PAGE_SECRET',    '' );
define( 'WP_CACHE_DEBUG_USERNAME', '' );


/*
 * Bootstrap WordPress
 */
if ( ! defined('ABSPATH') ) {
	define( 'ABSPATH', dirname( __FILE__ ) . '/mu' );
}

require_once ABSPATH . 'wp-settings.php';
