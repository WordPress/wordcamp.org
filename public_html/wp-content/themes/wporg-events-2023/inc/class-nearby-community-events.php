<?php

namespace WordPressdotorg\Events_2023;

use WP_Community_Events;

defined( 'WPINC' ) || die();

require_once ABSPATH . 'wp-admin/includes/class-wp-community-events.php';

/**
 * Fetches events near the visitor, the same way the Events widget in the Core dashboard does.
 *
 * Core's class already anonymizes the visitor's IP and caches the results per network, but it's tailored to the
 * widget's 3-item list, so this lifts that cap.
 */
class Nearby_Community_Events extends WP_Community_Events {
	/**
	 * The maximum number of events to show.
	 */
	const NUMBER_OF_EVENTS = 10;

	/**
	 * Request more events than the widget does.
	 *
	 * @param string $search   City search string.
	 * @param string $timezone Timezone string.
	 *
	 * @return array
	 */
	protected function get_request_args( $search = '', $timezone = '' ) {
		$args = parent::get_request_args( $search, $timezone );

		$args['body']['number'] = self::NUMBER_OF_EVENTS;

		return $args;
	}

	/**
	 * Keep a separate cache from the widget's.
	 *
	 * The widget caches the raw response under the same key, but asks for fewer events, so sharing it would cut
	 * this list short whenever the widget ran first.
	 *
	 * @param array $location
	 *
	 * @return string|false
	 */
	protected function get_events_transient_key( $location ) {
		$key = parent::get_events_transient_key( $location );

		return $key ? 'nearby-' . $key : false;
	}

	/**
	 * Drop events that have ended, without trimming the list down to the widget's size.
	 *
	 * @param array $events
	 *
	 * @return array
	 */
	protected function trim_events( array $events ) {
		$future_events = array();

		foreach ( $events as $event ) {
			if ( time() < (int) $event['end_unix_timestamp'] ) {
				$event['title'] = html_entity_decode( $event['title'], ENT_QUOTES, 'UTF-8' );

				$future_events[] = $event;
			}
		}

		return array_slice( $future_events, 0, self::NUMBER_OF_EVENTS );
	}
}
