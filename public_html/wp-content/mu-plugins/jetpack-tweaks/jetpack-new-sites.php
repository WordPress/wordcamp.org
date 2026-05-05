<?php

namespace WordCamp\Jetpack_Tweaks;
use Jetpack_Provision;
use Automattic\Jetpack\Current_Plan;

defined( 'WPINC' ) || die();

add_filter( 'site_option_jetpack-network-settings', __NAMESPACE__ . '\auto_connect_new_sites' );
add_filter( 'default_site_option_jetpack-network-settings', __NAMESPACE__ . '\auto_connect_new_sites' );

add_action( 'wp_initialize_site', __NAMESPACE__ . '\schedule_partner_provision', 100, 1 );
add_action( 'wordcamp_jetpack_partner_provision', __NAMESPACE__ . '\run_partner_provision' );

/**
 * Automatically connect new sites to WordPress.com.
 * All sites at present have SSL on their primary domain, so we can safely auto-connect.
 *
 * If this ever changes, see https://github.com/WordPress/wordcamp.org/pull/1515.
 *
 * @param array $value
 *
 * @return array
 */
function auto_connect_new_sites( $value ) {
	if ( ! $value ) {
		$value = array();
	}

	$value['auto-connect'] = 1;
	$value['sub-site-connection-override'] = 0;

	return $value;
}

/**
 * Queue a one-off cron event on the newly created site to provision the partner Jetpack plan.
 *
 * @param \WP_Site $site The newly created site.
 */
function schedule_partner_provision( $site ) {
	switch_to_blog( (int) $site->blog_id );
	wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'wordcamp_jetpack_partner_provision' );
	restore_current_blog();
}

/**
 * Cron handler: provision the partner Jetpack plan for the current site, retrying hourly on failure.
 */
function run_partner_provision() {
	if ( ! in_array( wp_get_environment_type(), array( 'production', 'staging' ), true ) ) {
		return;
	}

	if (
		! defined( 'WORDCAMP_JETPACK_START_PARTNER_ID' ) ||
		! defined( 'WORDCAMP_JETPACK_START_PARTNER_SECRET' ) ||
		! defined( 'WORDCAMP_JETPACK_START_PARTNER_PLAN' )
	) {
		return;
	}

	$site = get_site();
	if ( ! $site || $site->deleted || $site->archived || $site->spam ) {
		return;
	}

	if ( defined( 'JETPACK__PLUGIN_DIR' ) && ! class_exists( Jetpack_Provision::class ) ) {
		require_once JETPACK__PLUGIN_DIR . '_inc/class.jetpack-provision.php';
	}

	if ( ! class_exists( Jetpack_Provision::class ) ) {
		schedule_retry();
		return;
	}

	if ( ! str_contains( Current_Plan::get()['product_slug'], 'free' ) ) {
		return;
	}

	$wordcamp = get_user_by( 'login', 'wordcamp' );
	if ( ! $wordcamp ) {
		return;
	}

	$access_token = fetch_partner_access_token();
	if ( ! $access_token ) {
		schedule_retry();
		return;
	}

	wp_set_current_user( $wordcamp->ID );
	$result = Jetpack_Provision::partner_provision(
		$access_token,
		array( 'plan' => WORDCAMP_JETPACK_START_PARTNER_PLAN )
	);
	wp_set_current_user( 0 );

	if ( is_wp_error( $result ) ) {
		log_failure( 'provision', $result->get_error_code() . ': ' . $result->get_error_message() );
		schedule_retry();
	}
}

/**
 * Exchange the partner client credentials for a short-lived access token.
 *
 * @return string Access token, or empty string on failure.
 */
function fetch_partner_access_token() {
	$response = wp_remote_post(
		'https://public-api.wordpress.com/oauth2/token',
		array(
			'timeout' => 30,
			'body'    => array(
				'client_id'     => WORDCAMP_JETPACK_START_PARTNER_ID,
				'client_secret' => WORDCAMP_JETPACK_START_PARTNER_SECRET,
				'grant_type'    => 'client_credentials',
				'scope'         => 'jetpack-partner',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		log_failure( 'token request', $response->get_error_message() );
		return '';
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $body ) || empty( $body['access_token'] ) ) {
		log_failure( 'token decode', wp_remote_retrieve_body( $response ) );
		return '';
	}

	return (string) $body['access_token'];
}

/**
 * Re-queue the provisioning task one hour from now on the current site.
 */
function schedule_retry() {
	wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'wordcamp_jetpack_partner_provision' );
}

/**
 * Emit a partner-provisioning failure for the current site as a PHP warning.
 *
 * @param string $stage  Which step failed (e.g. "token request", "provision").
 * @param string $detail Human-readable error message from the failing call.
 */
function log_failure( $stage, $detail ) {
	trigger_error(
		esc_html( sprintf( '[jetpack-new-sites] %s failed for blog %d: %s', $stage, get_current_blog_id(), $detail ) ),
		E_USER_WARNING
	);
}
