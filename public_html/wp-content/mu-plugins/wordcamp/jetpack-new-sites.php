<?php

namespace WordCamp\Jetpack_Tweaks;
use WP_Site, WP_Error;
use Jetpack, Jetpack_Network;

defined( 'WPINC' ) or die();

add_filter( 'pre_update_site_option_jetpack-network-settings', __NAMESPACE__ . '\auto_connect_new_sites', 10, 2 );
add_action( 'wp_initialize_site',                              __NAMESPACE__ . '\wp_initialize_site', 11 );
add_action( 'wcorg_connect_new_site',                          __NAMESPACE__ . '\cron_auto_connect_jetpack_site', 10, 2 );

/**
 * Don't automatically connect new sites to WordPress.com.
 * We'll handle this ourselves.
 *
 * @param array $new_value
 * @param array $old_value
 *
 * @return array
 */
function auto_connect_new_sites( $new_value, $old_value ) {
	$new_value['auto-connect'] = 0;

	return $new_value;
}

/**
 * When a new site is created, try to connect it to Jetpack.
 *
 * @param WP_Site $new_site The new site object.
 * @return void
 */
function wp_initialize_site( WP_Site $new_site ) {
	cron_auto_connect_jetpack_site( $new_site->id, 0 );
}

/**
 * Try to connect a new site to Jetpack.
 *
 * This runs as a cron-task, but also interactively.
 *
 * @param int $site_id The site ID to connect.
 * @param int $retries The number of retries so far.
 * @return void
 */
function cron_auto_connect_jetpack_site( $site_id, $retries = 0 ) {
	switch_to_blog( $site_id );

	$connected          = Jetpack::is_active();
	$site_is_accessible = $connected || ! is_wp_error( wp_remote_head( site_url(), array( 'timeout' => 1 ) ) );

	if ( ! $connected && $site_is_accessible ) {
		// We need to run as a network admin to do the subsiteregister.
		$current_user = get_current_user_id();
		wp_set_current_user( get_user_by( 'login', 'wordcamp' )->ID );

		// Pretend that the user can perform all Jetpack caps. This is needed during crons (non-proxied).
		add_filter( 'map_meta_cap', $map_meta_cap = static function( $caps, $cap ) {
			if ( str_starts_with( $cap, 'jetpack_' ) ) {
				$caps = array( 'exist' );
			}
			return $caps;
		}, 10, 2 );

		$jetpack_network           = Jetpack_Network::init();
		$jetpack_connection_result = new WP_Error( 'not_callable', 'Jetpack_Network::do_subsiteregister() not callable.' );
		// Wrap it in a callable check, as this is reaching deeper into Jetpack than reasonable.
		if ( is_callable( array( $jetpack_network, 'do_subsiteregister' ) ) ) {
			$jetpack_connection_result = $jetpack_network->do_subsiteregister( $site_id );
		}

		// Log this for debugging later.
		if ( is_wp_error( $jetpack_connection_result ) ) {
			trigger_error( 'Jetpack subsiteregister failed for ' . site_url(). ': ' . $jetpack_connection_result->get_error_message(), E_USER_WARNING );
		}

		// Restore the current user.
		remove_filter( 'map_meta_cap', $map_meta_cap );
		wp_set_current_user( $current_user );

		$connected = Jetpack::is_active();
	}

	// If connection failed, we'll retry a few times, then send an email to support.
	if ( ! $connected ) {
		$retries++;
		if ( $retries <= 10 ) {
			// Give SSL some time to get setup, with incremental back-offs (final attempt is made ~30mins after first attempt).
			wp_schedule_single_event( time() + ( $retries / 2 ) * MINUTE_IN_SECONDS, 'wcorg_connect_new_site', [ $site_id, $retries ] );
		} else {
			// Send the email to support.
			wcorg_connect_new_site_email( $site_id );
		}
	}

	restore_current_blog();
}

/**
 * Send a mail asking for connecting Jetpack to WordPress.com
 *
 * Runs during wp-cron.php.
 *
 * @param int $blog_id The blog_id to connect.
 */
function wcorg_connect_new_site_email( $blog_id ) {
	switch_to_blog( $blog_id );
	$jetpack_is_active = Jetpack::is_active();
	restore_current_blog();

	// Bail if Jetpack is already active
	if ( $jetpack_is_active ) {
		return;
	}

	$domain = get_site_url( $blog_id );

	$subject = 'Connect ' . $domain . ' with Jetpack';

	$email_content = get_wcorg_jetpack_email( $blog_id );
	wp_mail(
		'support@wordcamp.org',
		$subject,
		$email_content
	);
}

/**
 * Generate email content which contains the one click Jetpack - WordCamp connection link.
 *
 * @param $blog_id
 *
 * @return string
 */
function get_wcorg_jetpack_email( $blog_id ) {

	$domain = get_site_url( $blog_id );
	$jetpack_net_admin = Jetpack_Network::init();
	$jetpack_link = $jetpack_net_admin->get_url( array(
		'name' => 'subsiteregister',
		'site_id' => $blog_id,
	) );
	$email_content = <<<TEXT
Hi there,

WordCamp site $domain can now be connected to Jetpack. Please click on the link below to activate the Jetpack connection on this site.

$jetpack_link

Please note that this link can only be used by people having access to Jetpack admin on wordcamp.org. If you do not have access, please assign this ticket to any Global Community Support team member. 

Thanks.

TEXT;

	return $email_content;
}
