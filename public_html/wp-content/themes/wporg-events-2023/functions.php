<?php

namespace WordPressdotorg\Events_2023;
use WP;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/blocks/events-landing-page/events-landing-page.php';

add_filter( 'parse_request', __NAMESPACE__ . '\add_city_landing_page_query_vars' );
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_assets' );

/**
 * Override the current query vars so that the city landing page loads instead.
 */
function add_city_landing_page_query_vars( WP $wp ): void {
	if ( ! wp_using_themes() || ! is_main_query() || ! is_city_landing_page() ) {
		return;
	}

	// This assumes there's a placeholder page in the database with this slug.
	$wp->set_query_var( 'page', '' );
	$wp->set_query_var( 'pagename', 'city-landing-page' );
}

/**
 * Determine if the current request is for a city landing page.
 *
 * See `get_city_landing_sites()` for examples of request URIs.
 */
function is_city_landing_page() {
	global $wp, $wpdb;

	// The landing page formats will always match the rewrite rule for pages, which sets `page` and `pagename`.
	if ( empty( $wp->query_vars['page'] ) && empty( $wp->query_vars['pagename'] ) ) {
		return false;
	}

	$city = explode( '/', $wp->request );
	$city = $city[0] ?? false;

	// Using this instead of `get_sites()` so that the search doesn't match false positives.
	$sites = $wpdb->get_results( $wpdb->prepare( "
		SELECT blog_id
		FROM {$wpdb->blogs}
		WHERE
			site_id = %d AND
			path REGEXP %s
		LIMIT 1",
		EVENTS_NETWORK_ID,
		"^/$city/\d{4}/[a-z-]+/$"
	) );

	return ! empty( $sites );
}

/**
 * Enqueue scripts and styles.
 */
function enqueue_assets() {
	wp_enqueue_style(
		'wporg-events-2023-style',
		get_stylesheet_uri(),
		array( 'wporg-parent-2021-style', 'wporg-global-fonts' ),
		filemtime( __DIR__ . '/style.css' )
	);
}
