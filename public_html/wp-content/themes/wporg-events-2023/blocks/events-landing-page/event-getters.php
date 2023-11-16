<?php

namespace WordPressdotorg\Events_2023;
use WP_Post, DateTimeZone, DateTime;
use function WordPressdotorg\MU_Plugins\Google_Map_Event_Filters\get_latin1_results_with_prepared_query;

defined( 'WPINC' ) || die();


add_action( 'init', __NAMESPACE__ . '\schedule_cron_jobs' );
add_action( 'events_landing_prime_query_cache', __NAMESPACE__ . '\prime_query_cache' );

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
 */
function prime_query_cache(): void {
	$city_landing_uris = get_known_city_landing_request_uris();

	foreach ( $city_landing_uris as $request_uri ) {
		get_city_landing_page_events( $request_uri, true );
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
function get_city_landing_page_events( string $request_uri, bool $force_refresh = false ): array {
	$limit     = 300;
	$cache_key = 'event_landing_city_events_' . md5( $request_uri );

	if ( empty( $request_uri ) || '/' === $request_uri ) {
		return array();
	}

	if ( ! $force_refresh ) {
		$cached_events = get_transient( $cache_key );

		if ( $cached_events ) {
			return $cached_events;
		}
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

		$events[] = array(
			'id'        => $wordcamp->_site_id,
			'title'     => get_wordcamp_name( $wordcamp->_site_id ),
			'url'       => $wordcamp->{'URL'},
			'type'      => 'wordcamp',
			'meetup'    => '',
			'location'  => $wordcamp->{'Location'},
			'latitude'  => $coordinates['latitude'],
			'longitude' => $coordinates['longitude'],
			'timestamp' => $wordcamp->{'Start Date (YYYY-mm-dd)'},
			'tz_offset' => get_wordcamp_offset( $wordcamp ),
		);
	}

	restore_current_blog();

	// `prime_query_cache()` should update this hourly, but expire after a day just in case it doesn't find all the
	// valid request URIs.
	set_transient( $cache_key, $events, DAY_IN_SECONDS );

	return $events;
}

/**
 * Standardize request URIs so they can be reliably used as cache keys.
 *
 * For example, `/rome`, `/rome/` and `/rome/?foo=bar` should all be `/rome/`.
 */
function normalize_request_uri( $raw_uri, $query_string ) {
	$clean_uri = str_replace( '?' . $query_string, '', $raw_uri );
	$clean_uri = trailingslashit( $clean_uri );
	$clean_uri = '/' . ltrim( $clean_uri, '/' );

	return $clean_uri;
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
