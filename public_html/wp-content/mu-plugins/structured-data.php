<?php

namespace WordCamp\Structured_Data;
use WP_Post;
use WordCamp_Loader;

add_action( 'wp_head', __NAMESPACE__ . '\add_event_data' );


/**
 * Add structured Event data to the homepage.
 *
 * @link https://schema.org/Event
 * @link https://developers.google.com/search/docs/appearance/structured-data/event
 */
function add_event_data(): void {
	if ( ! is_front_page() ) {
		return;
	}

	$wordcamp = get_wordcamp_post();

	if ( ! $wordcamp ) {
		return;
	}

	$payload = build_event_payload( $wordcamp );

	if ( ! $payload ) {
		return;
	}

	printf(
		'<script type="application/ld+json"> %s </script>',
		wp_json_encode( $payload )
	);
}

/**
 * Build a structured data payload for the WordCamp.
 *
 * @return array|false
 */
function build_event_payload( WP_Post $wordcamp ) {
	global $camptix;

	$cache_key      = 'structured-data-event';
	$cached_payload = get_transient( $cache_key );

	if ( $cached_payload ) {
		return $cached_payload;
	}

	$first_session_start_time_utc = get_first_session_utc_start_time( $wordcamp->ID );
	$offset_seconds               = get_wordcamp_offset( $wordcamp );

	// Note: We can't use `date( 'c' )` because it uses the server timezone, not the event/session timezone.
	if ( $first_session_start_time_utc ) {
		$start_timestamp_local = $first_session_start_time_utc + $offset_seconds;
		$start_date_iso_8601   = get_local_iso8601_with_offset( $start_timestamp_local, $offset_seconds );

	} else {
		$start_timestamp_local = (int) $wordcamp->meta['Start Date (YYYY-mm-dd)'][0] ?? 0;

		// The time should only be included if it's known.
		// @link https://developers.google.com/search/docs/appearance/structured-data/event#best-practices.
		$start_date_iso_8601 = gmdate( 'Y-m-d', $start_timestamp_local );
	}

	if ( ! $start_timestamp_local || $start_timestamp_local < time() ) {
		return false;
	}

	$end_timestamp_local = (int) $wordcamp->meta['End Date (YYYY-mm-dd)'][0] ?? 0;

	// The time should be left off unless it's known. We could try to get the last session via something like
	// `get_first_session_utc_start_time()`, but the date is good enough.
	// @link https://developers.google.com/search/docs/appearance/structured-data/event#examples-of-how-google-interprets-dates.
	$end_date_iso_8601 = gmdate( 'Y-m-d', $end_timestamp_local ? $end_timestamp_local : $start_timestamp_local );

	$status = get_event_status( $wordcamp );

	if ( ! $status ) {
		return false;
	}

	$active_tickets = $camptix->get_active_tickets();

	if ( ! $active_tickets ) {
		return false;
	}

	$attendance_mode = get_event_attendance_mode( $active_tickets, $wordcamp );
	$offers          = get_event_offers( $active_tickets );
	$header_image    = get_header_image();

	// They want it to be concise, so use the excerpt instead of the full content.
	// @link https://developers.google.com/search/docs/appearance/structured-data/event#structured-data-type-definitions.
	switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
	$description = get_the_excerpt( $wordcamp );
	restore_current_blog();

	$payload = array(
		'@context'            => 'https://schema.org',
		'@type'               => 'Event',
		'name'                => get_wordcamp_name(),
		'startDate'           => $start_date_iso_8601,
		'endDate'             => $end_date_iso_8601,
		'eventAttendanceMode' => $attendance_mode,
		'eventStatus'         => $status,
		'location'            => get_event_location( $wordcamp ),
		'image'               => $header_image ? $header_image : '',
		'description'         => $description,
		'offers'              => $offers,
	);

	set_transient( $cache_key, $payload, HOUR_IN_SECONDS );

	return $payload;
}

/**
 * Get a local date/time string in ISO 8601 format with a timezone offset.
 *
 * For example, `2024-12-15T09:00:00+01:00`.
 *
 * `date( 'c' ) can't be used because that would use the server timezone, rather than the event.
 *
 * @link https://developers.google.com/search/docs/appearance/structured-data/event#best-practices
 * @link https://schema.org/DateTime
 * @link https://en.wikipedia.org/wiki/ISO_8601#Time_offsets_from_UTC
 *
 * @return string
 */
function get_local_iso8601_with_offset( int $local_timestamp, int $offset_seconds ): string {
	$offset_hours = (int) floor( $offset_seconds / HOUR_IN_SECONDS );
	$offset_hours = str_pad( $offset_hours, 2, '0', STR_PAD_LEFT );

	// An explicit `-` isn't needed because it will be included as $offset_hours.
	$offset_operator = $offset_seconds < 0 ? '' : '+';

	$offset_minutes = floor( ( $offset_seconds / MINUTE_IN_SECONDS ) % MINUTE_IN_SECONDS );
	$offset_minutes = str_pad( $offset_minutes, 2, '0', STR_PAD_LEFT );

	$datetime      = gmdate( 'Y-m-d\TH:i:s', $local_timestamp );
	$offset_string = $offset_operator . $offset_hours . ':' . $offset_minutes;

	return $datetime . $offset_string;
}

/**
 * Get the URL corresponding to the status of the event.
 *
 * @link https://schema.org/EventStatusType
 *
 * @return false|string
 */
function get_event_status( WP_Post $wordcamp ) {
	require_once WP_PLUGIN_DIR . '/wcpt/wcpt-event/class-event-loader.php';
	require_once WP_PLUGIN_DIR . '/wcpt/wcpt-wordcamp/wordcamp-loader.php';

	$active_statuses = WordCamp_Loader::get_active_wordcamp_statuses();

	if ( 'wcpt-cancelled' === $wordcamp->post_status ) {
		$status = 'https://schema.org/EventCancelled';
	} elseif ( in_array( $wordcamp->post_status, $active_statuses, true ) ) {
		$status = 'https://schema.org/EventScheduled';
	} else {
		// There isn't an `eventStatus` for completed events, they only want to show ones that are upcoming.
		// @link https://schema.org/EventStatusType
		// @link https://developers.google.com/search/docs/appearance/structured-data/event#structured-data-type-definitions.
		return false;
	}

	return $status;
}

/**
 * Get the attendance mode for the event.
 *
 * @link https://schema.org/EventAttendanceModeEnumeration
 *
 * @return string
 */
function get_event_attendance_mode( array $active_tickets, WP_Post $wordcamp ): string {
	if ( $wordcamp->meta['Virtual event only'][0] ?? false ) {
		$attendance_mode = 'https://schema.org/OnlineEventAttendanceMode';

	} else {
		$has_livestream_ticket = array_reduce(
			$active_tickets,
			function ( $carry, $ticket ) {
				if ( 'remote' === $ticket->tix_type ) {
					$carry = true;
				}

				return $carry;
			},
			false
		);

		if ( $has_livestream_ticket ) {
			$attendance_mode = 'https://schema.org/MixedEventAttendanceMode';

		} else {
			$attendance_mode = 'https://schema.org/OfflineEventAttendanceMode';
		}
	}

	return $attendance_mode;
}

/**
 * Get the location of the event.
 *
 * @link https://schema.org/Place
 * @link https://schema.org/VirtualLocation
 *
 * @return object
 */
function get_event_location( WP_Post $wordcamp ): object {
	if ( $wordcamp->meta['Virtual event only'][0] ?? false ) {
		$location = (object) array(
			'@type' => 'VirtualLocation',
			'url'   => $wordcamp->meta['URL'][0] ?? '',
		);

	} else {
		$street_number = $wordcamp->meta['_venue_street_number'][0] ?? '';
		$street_name   = $wordcamp->meta['_venue_street_name'][0] ?? '';

		$location = (object) array(
			'@type'   => 'Place',
			'name'    => $wordcamp->meta['Venue Name'][0] ?? '',
			'address' => (object) array(
				'@type'           => 'PostalAddress',
				'streetAddress'   => trim( $street_number . ' ' . $street_name ),
				'addressLocality' => $wordcamp->meta['_venue_city'][0] ?? '',
				'postalCode'      => $wordcamp->meta['_venue_zip'][0] ?? '',
				'addressRegion'   => $wordcamp->meta['_venue_state'][0] ?? '',
				'addressCountry'  => $wordcamp->meta['_venue_country_code'][0] ?? '',
			),
		);
	}

	return $location;
}

/**
 * Get ticket information for the event.
 *
 * Google wants the cheapest ticket price.
 *
 * @link https://developers.google.com/search/docs/appearance/structured-data/event#structured-data-type-definitions
 * @link https://schema.org/Offer
 *
 * @return false|object
 */
function get_event_offers( array $active_tickets ) {
	global $camptix;

	$tickets_page_id = $camptix->get_tickets_post_id();

	if ( ! $tickets_page_id ) {
		return false;
	}

	$camptix_options = $camptix->get_options();
	$cheapest_ticket = $camptix->get_cheapest_ticket( $active_tickets );

	$offers = (object) array(
		'@type'         => 'Offer',
		'url'           => get_permalink( $tickets_page_id ),
		'price'         => $cheapest_ticket->tix_price,
		'priceCurrency' => $camptix_options['currency'],
		'availability'  => 'https://schema.org/InStock',
	);

	return $offers;
}
