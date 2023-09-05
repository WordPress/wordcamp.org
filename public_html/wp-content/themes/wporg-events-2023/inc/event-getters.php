<?php

namespace WordPressdotorg\Events_2023;

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
