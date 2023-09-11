<?php

namespace WordPressdotorg\Events_2023;
use WP_Post, DateTimeZone, DateTime;

defined( 'WPINC' ) || die();


/**
 * Get a list of all upcoming events across all sites.
 */
function get_all_upcoming_events(): array {
	global $wpdb;

	$events = $wpdb->get_results( '
		SELECT
			id, `type`, title, url, meetup, location, latitude, longitude, date_utc,
			date_utc_offset AS tz_offset
		FROM `wporg_events`
		WHERE
			status = "scheduled" AND
			(
				( "wordcamp" = type AND date_utc BETWEEN NOW() AND ADDDATE( NOW(), 180 ) ) OR
				( "meetup" = type AND date_utc BETWEEN NOW() AND ADDDATE( NOW(), 60 ) )
			)
		ORDER BY date_utc ASC
		LIMIT 400'
	);

	foreach ( $events as $event ) {
		// `capital_P_dangit()` won't work here because the current filter isn't `the_title` and there isn't a safelisted prefix before `$text`.
		$event->title = str_replace( 'Wordpress', 'WordPress', $event->title );

		// `date_utc` is a misnomer, the value is actually in the local timezone of the event. So, convert to a true Unix timestamp (UTC).
		// Can't do this reliably in the query because MySQL converts it to the server timezone.
		$event->timestamp = strtotime( $event->date_utc ) - $event->tz_offset;

		unset( $event->date_utc );
	}

	return $events;
}

/**
 * Get events based on the given request URI.
 *
 * See `get_city_landing_sites()` for how request URIs map to events.
 */
function get_city_landing_page_events( string $request_uri ): array {
	$limit = 300;
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

	return $events;
}

/**
 * Get sites that match the given request URI.
 *
 * /rome/          -> All sites in Rome
 * /rome/training/ -> All training sites in Rome
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
