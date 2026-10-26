<?php
/**
 * Which flagship events take applications, and when.
 *
 * @package WordCamp\Open_Horizons
 */

declare( strict_types = 1 );

namespace WordCamp\Open_Horizons;

defined( 'WPINC' ) || die();

/**
 * The option holding the IDs of the flagship `wordcamp` posts reviewers picked.
 */
const EVENTS_OPTION = 'oh_flagship_events';

/**
 * Gets the flagship WordCamps reviewers picked.
 *
 * @return int[] `wordcamp` post IDs.
 */
function get_flagship_event_ids(): array {
	return array_values( array_filter( array_map( 'absint', (array) get_option( EVENTS_OPTION, array() ) ) ) );
}

/**
 * Gets when an event takes applications: from four months before it starts until two months before.
 *
 * Four months leaves time for visas; closing two months out leaves time to decide and to travel.
 *
 * @param int $wordcamp_id A `wordcamp` post ID.
 * @return array|null `start`, `opens` and `closes` timestamps, or null without a start date.
 */
function get_application_window( int $wordcamp_id ): ?array {
	$start = (int) get_post_meta( $wordcamp_id, 'Start Date (YYYY-mm-dd)', true );

	if ( ! $start ) {
		return null;
	}

	return array(
		'start'  => $start,
		'opens'  => strtotime( '-4 months', $start ),
		'closes' => strtotime( '-2 months', $start ),
	);
}

/**
 * Gets the flagship events taking applications now.
 *
 * @return array[] `name` and `closes`, keyed by `wordcamp` post ID.
 */
function get_open_events(): array {
	$events = array();
	$now    = time();

	foreach ( get_flagship_event_ids() as $wordcamp_id ) {
		$window = get_application_window( $wordcamp_id );

		if ( $window && $window['opens'] <= $now && $now < $window['closes'] ) {
			$events[ $wordcamp_id ] = array(
				'name'   => get_the_title( $wordcamp_id ),
				'closes' => $window['closes'],
			);
		}
	}

	return $events;
}
