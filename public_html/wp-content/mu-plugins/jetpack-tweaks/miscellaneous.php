<?php

namespace WordCamp\Jetpack_Tweaks;
use WP_Service_Worker_Caching_Routes, WP_Service_Worker_Scripts;

defined( 'WPINC' ) || die();

// Allow Photon to fetch images that are served via HTTPS.
add_filter( 'jetpack_photon_reject_https',    '__return_false' );

/**
 * Filter the post types Jetpack has access to, and can synchronize with WordPress.com.
 *
 * @see Jetpack's WPCOM_JSON_API_ENDPOINT::_get_whitelisted_post_types();
 *
 * @param array $allowed_types Array of whitelisted post types.
 *
 * @return array Modified array of whitelisted post types.
 */
function add_post_types_to_rest_api( $allowed_types ) {
	$allowed_types += array( 'wcb_speaker', 'wcb_session', 'wcb_sponsor' );

	return $allowed_types;
}

add_filter( 'rest_api_allowed_post_types', __NAMESPACE__ . '\add_post_types_to_rest_api' );

/**
 * Prepend a unique string to contact form subjects.
 *
 * Otherwise some e-mail clients and management systems -- *cough* SupportPress *cough* -- will incorrectly group
 * separate messages into the same thread.
 *
 * It'd be better to have the key appended rather than prepended, but SupportPress won't always recognize the
 * subject as unique if we do that :|
 *
 * @param string $subject
 *
 * @return string
 */
function grunion_unique_subject( $subject ) {
	return sprintf( '[%s] %s', wp_generate_password( 8, false ), $subject );
}
add_filter( 'contact_form_subject', __NAMESPACE__ . '\grunion_unique_subject' );

/**
 * Lower the timeout for requests to the Brute Protect API to avoid unintentional DDoS.
 *
 * The default timeout is 30 seconds, but when the API goes down, the long timeouts will occupy php-fpm threads,
 * which will stack up until there are no more available, and the site will crash.
 *
 * @link https://wordpress.slack.com/archives/G02QCEMRY/p1553203877064600
 *
 * @param int $timeout
 *
 * @return int
 */
function lower_brute_protect_api_timeout( $timeout ) {
	return 8; // seconds.
}
add_filter( 'jetpack_protect_connect_timeout', __NAMESPACE__ . '\lower_brute_protect_api_timeout' );

/**
 * Register caching routes for Jetpack with the frontend service worker.
 *
 * Jetpack uses wp.com domains for loading assets, which need to be cached regexes that match from the start of
 * the URL. This prevents unintentional caching of 3rd-party scripts by broad regexes.
 *
 * @param WP_Service_Worker_Scripts $scripts
 */
function register_caching_routes( WP_Service_Worker_Scripts $scripts ) {
	/*
	 * Set up jetpack cache strategy to pull from the cache first, with no network request if the resource is
	 * found, and save up to 50 cached entries for 1 day.
	 */
	$asset_cache_strategy_args = array(
		'strategy'  => WP_Service_Worker_Caching_Routes::STRATEGY_CACHE_FIRST,
		'cacheName' => 'wc-jetpack',
		'plugins'   => array(
			'expiration' => array(
				'maxEntries'    => 50,
				'maxAgeSeconds' => DAY_IN_SECONDS,
			),
		),
	);

	/*
	 * Cache Jetpack core assets.
	 * It's possible that some Jetpack assets are loaded from wp.com servers. Anything off a `s0.`, `s1.`, or
	 * `s2.wp.com` domain should be locally cached.
	 */
	$scripts->caching_routes()->register(
		'https?://s[0-2]{1}.wp.com/.*\.(png|gif|jpg|jpeg)(\?.*)?$',
		$asset_cache_strategy_args
	);

	/*
	 * Cache assets from "Site Accelerator".
	 * Jetpack can use the wp.com CDN for CSS and JS. This uses the `c0.wp.com` domain.
	 */
	$scripts->caching_routes()->register(
		'https?://c0.wp.com/.*\.(css|js)(\?.*)?$',
		$asset_cache_strategy_args
	);

	/*
	 * Cache files from Photon.
	 * Images loaded by Photon use wp.com servers, and are loaded from `i0.`, `i1.`, or `i2.wp.com`.
	 */
	$scripts->caching_routes()->register(
		'https?://i[0-2]{1}.wp.com/.*/files/.*\.(png|gif|jpg|jpeg)(\?.*)?$',
		$asset_cache_strategy_args
	);
}
add_action( 'wp_front_service_worker', __NAMESPACE__ . '\register_caching_routes' );

/**
 * Disable Jetpack's email notifications for following a WordCamp if not already set.
 *
 * Jetpack defaults to send an email about each subscriber to each WordCamp to the owner
 * of the Jetpack connection.  No need to receive these emails.
 */
function disable_jetpack_blog_follow_emails() {
	$social_notifications_subscribe = get_option( 'social_notifications_subscribe' );
	if ( false === $social_notifications_subscribe ) {
		update_option( 'social_notifications_subscribe', 'off' );
	}
}
add_filter( 'admin_init', __NAMESPACE__ . '\disable_jetpack_blog_follow_emails' );

/**
 * Disable Jetpack's automatic spam deletion if the WordCamp is in future or has ended less than month ago.
 *
 * Jetpack deletes normally spam submissions after 15 days. Sometimes there are false positivies and
 * organisers do miss important messages because those get deleted before team manually checks spam folder.
 * Keep the spam submissions until month has passed from the start of WordCamp, just in case of some.
 */
function disable_jetpack_spam_delete() {
	$wordcamp = get_wordcamp_post();
	$wc_start = $wordcamp->meta['Start Date (YYYY-mm-dd)'][0];

	/**
	 * Bail if WordCamp start date has not been set.
	 * Allow spam deletion in order to keep database clean.
	 */
	if ( empty( $wc_start ) ) {
		return;
	}

	/**
	 * Bail if month has passed after WordCamp started.
	 * Allow spam deletion.
	 */
	if ( absint( $wc_start ) < strtotime( '-30 days' ) ) {
		return;
	}

	/**
	 * Remove spam deletion actions.
	 * One for the actual submit and second for Akismet metadata attached to each submission.
	 */
  remove_action( 'grunion_scheduled_delete', 'grunion_delete_old_spam' );
  remove_action( 'wp_scheduled_delete', array( \Grunion_Contact_Form_Plugin::init(), 'daily_akismet_meta_cleanup' ) );
}
add_action( 'init', __NAMESPACE__ . '\disable_jetpack_spam_delete' );
