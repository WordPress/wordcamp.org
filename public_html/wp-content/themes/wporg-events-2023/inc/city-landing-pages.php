<?php

namespace WordPressdotorg\Events_2023;
use WP, WP_Post, DateTimeZone, DateTime;
use WordPressdotorg\MU_Plugins\Google_Map;

defined( 'WPINC' ) || die();

const FILTER_SLUG = 'city-landing-pages';

add_filter( 'parse_request', __NAMESPACE__ . '\add_city_landing_page_query_vars' );
add_action( 'init', __NAMESPACE__ . '\schedule_cron_jobs' );
add_filter( 'google-map-event-filters-register-cron', __NAMESPACE__ .'\disable_event_filters_cron', 10, 2 );
add_action( 'google_map_event_filters_' . FILTER_SLUG, __NAMESPACE__ .'\get_events' );
add_action( 'google_map_event_filters_cache_key_parts', __NAMESPACE__ .'\add_landing_page_to_cache_key' );
add_action( 'events_landing_prime_query_cache', __NAMESPACE__ . '\prime_query_cache' );


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
function is_city_landing_page(): bool {
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
		"^/$city/[0-9]{4}/[a-z-]+/$"
	) );

	return ! empty( $sites );
}

/**
 * Schedule cron jobs.
 */
function schedule_cron_jobs(): void {
	if ( ! wp_next_scheduled( 'events_landing_prime_query_cache' ) ) {
		wp_schedule_event( time(), 'hourly', 'events_landing_prime_query_cache' );
	}
}

/**
 * Prime the caches of events.
 *
 * Without this, users would have to wait for new results to be generated every time the cache expires. That could
 * make the front end very slow. This will refresh the cache before it expires, so that the front end can always
 * load cached results.
 *
 * These pages can't use the cache-priming cron provided by the Event Filters plugin, because it doesn't know how
 * to handle them.
 */
function prime_query_cache(): void {
	$city_landing_uris = get_known_city_landing_request_uris();

	// It won't know the "current" page in a cron context, so we'll pass it directly to `get_cache_key()` in the loop below.
	remove_action( 'google_map_event_filters_cache_key_parts', __NAMESPACE__ .'\add_landing_page_to_cache_key' );

	foreach ( $city_landing_uris as $request_uri ) {
		// Must match the return value of the cache key that `Google_Map_Event_Filters\get_events()` generates during block rendering,
		// including the `landing_page` item added by `add_landing_page_to_cache_key()`.
		$parts = array(
			'filter_slug'     => FILTER_SLUG,
			'start_timestamp' => 0,
			'end_timestamp'   => 0,
			'landing_page'    => $request_uri,
		);

		$cache_key = Google_Map\get_cache_key( $parts );
		$events    = get_city_landing_page_events( $request_uri, true );

		set_transient( $cache_key, $events, DAY_IN_SECONDS );
	}
}

/**
 * Get a list of all known city landing page request URIs.
 *
 * For example, `/rome/`, `/rome/training/`, `/rome/2023/`.
 */
function get_known_city_landing_request_uris(): array {
	$city_landing_pages = array();

	$city_sites = get_sites( array(
		'network_id'   => EVENTS_NETWORK_ID,
		'path__not_in' => array( '/' ),
		'number'       => false,
		'public'       => 1,
		'archived'     => 0,
		'deleted'      => 0,
	) );

	foreach ( $city_sites as $site ) {
		$parts = explode( '/', trim( $site->path, '/' ) );

		if ( 3 !== count( $parts ) ) {
			continue;
		}

		$city  = $parts[0];
		$year  = absint( $parts[1] );
		$title = preg_replace( '#^(.*)(-\d+)$#', '$1', $parts[2] ); // Strip any `-2`, `-3`, `-n` suffixes.

		$city_landing_pages[ sprintf( '/%s/', $city ) ]            = true;
		$city_landing_pages[ sprintf( '/%s/%s/', $city, $year ) ]  = true;
		$city_landing_pages[ sprintf( '/%s/%s/', $city, $title ) ] = true;
	}

	return array_keys( $city_landing_pages );
}


/**
 * Get events based on the given request URI.
 *
 * See `get_city_landing_sites()` for how request URIs map to events.
 */
function get_city_landing_page_events( string $request_uri ): array {
	$limit = 300;

	if ( empty( $request_uri ) || '/' === $request_uri ) {
		return array();
	}

	$sites = get_city_landing_sites( $request_uri, $limit );

	switch_to_blog( WORDCAMP_ROOT_BLOG_ID );

	$wordcamps = get_wordcamps( array(
		'post_status'  => 'wcpt-scheduled',
		'numberposts'  => $limit,
		'meta_key'     => '_site_id',
		'meta_value'   => wp_list_pluck( $sites, 'blog_id' ),
		'meta_compare' => 'IN',
	) );

	$events = array();

	foreach ( $wordcamps as $wordcamp ) {
		$coordinates = $wordcamp->_venue_coordinates ?? $wordcamp->_host_coordinates;

		if ( empty( $coordinates['latitude'] ) || empty( $coordinates['longitude'] ) ) {
			continue;
		}

		$events[] = (object) array(
			'id'        => $wordcamp->_site_id,
			'title'     => get_wordcamp_name( $wordcamp->_site_id ),
			'url'       => $wordcamp->{'URL'},
			'type'      => 'wordcamp',
			'meetup'    => '',
			'location'  => $wordcamp->{'Location'},
			'latitude'  => $coordinates['latitude'],
			'longitude' => $coordinates['longitude'],
			'date_utc'  => gmdate( 'Y-m-d H:i:s', $wordcamp->{'Start Date (YYYY-mm-dd)'} ),
			'tz_offset' => get_wordcamp_offset( $wordcamp ),
		);
	}

	restore_current_blog();

	return $events;
}

/**
 * Get sites that match the given request URI.
 *
 * /rome/          -> All sites in Rome
 * /rome/training/ -> All training sites in Rome (including those with slugs like `training-2`)
 * /rome/2023/     -> All sites in Rome in 2023
 */
function get_city_landing_sites( string $request_uri, int $limit ): array {
	global $wpdb;

	$request   = trim( $request_uri, '/' );
	$request   = explode( '/', $request );
	$num_parts = count( $request );

	if ( 1 !== $num_parts && 2 !== $num_parts ) {
		return array();
	}

	$city = $request[0];

	if ( is_numeric( $request[1] ?? '' ) && 4 === strlen( $request[1] ) ) {
		$year  = absint( $request[1] );
		$title = false;
	} else {
		$title = $request[1] ?? false;
		$year  = false;
	}

	if ( $year ) {
		$regex = "^/$city/$year/";
	} elseif ( $title ) {
		$regex = "^/$city/\d{4}/$title/";
	} else {
		$regex = "^/$city/";
	}

	// Using this instead of `get_sites()` so that the search doesn't match false positives.
	$sites = $wpdb->get_results( $wpdb->prepare( "
		SELECT blog_id
		FROM {$wpdb->blogs}
		WHERE
			site_id = %d AND
			path REGEXP %s AND
			public = 1 AND
			archived = 0 AND
			deleted = 0
		ORDER BY blog_id DESC
		LIMIT %d",
		EVENTS_NETWORK_ID,
		$regex,
		$limit
	) );

	return $sites;
}

/**
 * Get a WordCamp's UTC offset in seconds.
 *
 * Forked from `official-wordpress-events`.
 */
function get_wordcamp_offset( WP_Post $wordcamp ): int {
	if ( ! $wordcamp->{'Event Timezone'} || ! $wordcamp->{'Start Date (YYYY-mm-dd)'} ) {
		return 0;
	}

	$wordcamp_timezone = new DateTimeZone( $wordcamp->{'Event Timezone'} );

	$wordcamp_datetime = new DateTime(
		'@' . $wordcamp->{'Start Date (YYYY-mm-dd)'},
		$wordcamp_timezone
	);

	return $wordcamp_timezone->getOffset( $wordcamp_datetime );
}

/**
 * Disable the cron job that Google Map Event Filters registers.
 *
 * This plugin needs to run its own cron to prime the events cache, because the upstream one doesn't know how to handle city pages.
 */
function disable_event_filters_cron( bool $enabled, string $filter_slug ): bool {
	if ( FILTER_SLUG === $filter_slug ) {
		$enabled = false;
	}

	return $enabled;
}

/**
 * Get the events for the current city landing page.
 */
function get_events( array $events ): array {
	if ( is_city_landing_page() ) {
		$events = get_city_landing_page_events( get_current_landing_page() );
	}

	return $events;
}

/**
 * Get the path to the current landing page, with surrounding slashes.
 */
function get_current_landing_page(): string {
	global $wp;

	$page = '/' . $wp->request . '/';

	return $page;
}

/**
 * Adds the current landing page to the Event Filters cache key.
 *
 * Without this, all city pages would share the same cache key, and the wrong events would show up on most pages.
 */
function add_landing_page_to_cache_key( array $items ): array {
	if ( is_city_landing_page() && FILTER_SLUG === $items['filter_slug'] ) {
		$items['landing_page'] = get_current_landing_page();
	}

	return $items;
}
