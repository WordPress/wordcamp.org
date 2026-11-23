<?php
/**
 * The people speaking at an event.
 *
 * Speakers used to be set only from the group settings app, which saved them
 * through the core REST route after the event itself, so the event modal had
 * no way to set them and most events went without (#2424). They're now one of
 * the event form's fields, picked from the group's members like the hosts and
 * saved with the rest of the event.
 *
 * The meta itself, `_event_speakers`, is registered by the GatherPress tweaks
 * plugin and read by the `wporg/event-speakers` block.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\Event_Speakers;

use WP_User;

use function WordCamp\Groups\Frontend\Event_Hosts\sanitize_ids;

defined( 'WPINC' ) || die();

/**
 * Post meta holding the speaker user IDs, in the order the organizer gave them.
 */
const META_KEY = '_event_speakers';

/**
 * The most speakers one event can name.
 *
 * Generous for a meetup, which rarely has more than a handful; it bounds what
 * the endpoint will store rather than shaping any real event.
 */
const MAX_SPEAKERS = 20;

/**
 * The people speaking at an event, in the organizer's order.
 *
 * Unlike hosts there's no fallback: an event with nobody named has no
 * speakers. A speaker whose account is gone is skipped.
 *
 * @param int $event_id Event post ID.
 * @return WP_User[]
 */
function get_event_speakers( int $event_id ): array {
	$ids      = get_post_meta( $event_id, META_KEY, true );
	$speakers = array();

	foreach ( is_array( $ids ) ? $ids : array() as $id ) {
		$user = get_userdata( (int) $id );

		if ( $user ) {
			$speakers[] = $user;
		}
	}

	return $speakers;
}

/**
 * The event form's value for an event's speakers.
 *
 * @param int $event_id Event post ID.
 * @return array[] See `Event_Hosts\to_form_value()`.
 */
function get_form_value( int $event_id ): array {
	return array_map( 'WordCamp\\Groups\\Frontend\\Event_Hosts\\to_form_value', get_event_speakers( $event_id ) );
}

/**
 * Replace an event's speakers.
 *
 * Keeps group members only, through the same rules as the hosts. An empty
 * list, after sanitizing, clears them.
 *
 * @param int   $event_id Event post ID.
 * @param mixed $ids      User IDs.
 */
function set_event_speakers( int $event_id, $ids ): void {
	$ids = sanitize_ids( $ids, MAX_SPEAKERS );

	if ( $ids ) {
		update_post_meta( $event_id, META_KEY, $ids );
	} else {
		delete_post_meta( $event_id, META_KEY );
	}
}
