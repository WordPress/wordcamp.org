<?php

namespace WordCamp\Jetpack_Tweaks;
use WP_Site;

defined( 'WPINC' ) or die();


add_filter( 'pre_update_site_option_jetpack-network-settings', __NAMESPACE__ . '\auto_connect_new_sites', 10, 2 );
add_action( 'wp_initialize_site',                              __NAMESPACE__ . '\schedule_connect_new_site', 11 );
add_action( 'wcorg_connect_new_site',                          __NAMESPACE__ . '\wcorg_connect_new_site', 10, 2 );

/**
 * Never automatically connect new sites to WordPress.com.
 *
 * Sites don't have SSL certificates when they're first created, so any attempt to connect to WordPress.com would
 * fail. Instead, connecting is attempted after the SSL has been installed. See wcorg_connect_new_site_email().
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
 * Schedule an email asking to connect Jetpack to WordPress.com
 *
 * @param WP_Site $new_site
 */
function schedule_connect_new_site( $new_site ) {
	wp_schedule_single_event(
		/*
		 * Jetpack can't be connected until the domain's SSL certificate is installed.
		 *
		 * The daemon that polls our `domains-dehydrated` endpoint for new domains runs every 10 seconds.
		 * When it detects a new domain, it calls Dehydrated, which needs a little bit of time to order,
		 * verify, and install the new certificate, and then gracefully reload nginx config.
		 *
		 * The UX benefits of connecting quickly drops off sharply after ~3 seconds, so we might as
		 * well wait a bit longer, in order to improve reliability.
		 */
		time() + MINUTE_IN_SECONDS,
		'wcorg_connect_new_site_email',
		array( $new_site->blog_id, get_current_user_id() )
	);
}

/**
 * Send a mail asking for connecting Jetpack to WordPress.com
 *
 * Runs during wp-cron.php.
 *
 * @param int $blog_id The blog_id to connect.
 * @param int $user_id The user ID who created the new site.
 */
function wcorg_connect_new_site_email( $blog_id, $user_id ) {
	$original_blog_id = get_current_blog_id();

	switch_to_blog( $blog_id );

	// Bail if Jetpack is already active
	if ( \Jetpack::is_active() ) {
		restore_current_blog();
		return;
	}
	restore_current_blog();

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
	$jetpack_net_admin = \Jetpack_Network::init();
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
