<?php
/**
 * The people hosting an event.
 *
 * The event page credited whoever created the event as its host, and there
 * was no way to change that or name anyone else (#2152). Hosts are now a list
 * the organizer picks from the group's members. Hosting is a credit on the
 * event page and nothing more: it grants no capability, and the event's
 * author (who the permission checks read) is left alone.
 *
 * An event with no hosts stored falls back to its author, so every event
 * created before this, or saved without touching the field, reads the same
 * as it always did.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\Event_Hosts;

use WP_User;

defined( 'WPINC' ) || die();

/**
 * Post meta holding the host user IDs, in the order the organizer gave them.
 */
const META_KEY = '_event_hosts';

/**
 * The most hosts one event can name.
 *
 * Enough for any co-hosted meetup; a list longer than this is a roster, and
 * the event hero has room for a line, not a roster.
 */
const MAX_HOSTS = 10;

/**
 * Normalize a submitted list of host user IDs.
 *
 * Keeps members of the current group only, drops duplicates (the first one
 * wins) and keeps the first `MAX_HOSTS`. Enforced here rather than trusted
 * from the form, since the REST endpoint is the real write path.
 *
 * @param mixed $ids Submitted user IDs.
 * @return int[] Clean user IDs, in the order given.
 */
function sanitize_ids( $ids ): array {
	if ( ! is_array( $ids ) ) {
		return array();
	}

	$clean = array();

	foreach ( $ids as $id ) {
		if ( ! is_numeric( $id ) ) {
			continue;
		}

		$id = (int) $id;

		if ( $id <= 0 || in_array( $id, $clean, true ) || ! is_user_member_of_blog( $id ) ) {
			continue;
		}

		$clean[] = $id;

		if ( count( $clean ) >= MAX_HOSTS ) {
			break;
		}
	}

	return $clean;
}

/**
 * The people hosting an event, in the organizer's order.
 *
 * A host whose account is gone is skipped. With nobody left, or nobody
 * stored, the event's author hosts it.
 *
 * Membership is not re-checked here: someone who hosted an event and later
 * left the group still hosted it.
 *
 * @param int $event_id Event post ID.
 * @return WP_User[]
 */
function get_event_hosts( int $event_id ): array {
	$ids   = get_post_meta( $event_id, META_KEY, true );
	$hosts = array();

	foreach ( is_array( $ids ) ? $ids : array() as $id ) {
		$user = get_userdata( (int) $id );

		if ( $user ) {
			$hosts[] = $user;
		}
	}

	if ( $hosts ) {
		return $hosts;
	}

	$author = get_userdata( (int) get_post_field( 'post_author', $event_id ) );

	return $author ? array( $author ) : array();
}

/**
 * Describe a host for the event form's host field.
 *
 * @param WP_User $user The host.
 * @return array{id:int, name:string, slug:string}
 */
function to_form_value( WP_User $user ): array {
	return array(
		'id'   => (int) $user->ID,
		'name' => html_entity_decode( $user->display_name ),
		'slug' => $user->user_nicename,
	);
}

/**
 * The event form's value for an event's hosts.
 *
 * @param int $event_id Event post ID.
 * @return array[] See `to_form_value()`.
 */
function get_form_value( int $event_id ): array {
	return array_map( __NAMESPACE__ . '\to_form_value', get_event_hosts( $event_id ) );
}

/**
 * The event form's value for a new event: the person creating it.
 *
 * @return array[] See `to_form_value()`.
 */
function get_default_form_value(): array {
	$user = wp_get_current_user();

	return $user->exists() ? array( to_form_value( $user ) ) : array();
}

/**
 * Replace an event's hosts.
 *
 * An empty list, after sanitizing, clears the stored hosts so the event
 * falls back to its author rather than being hosted by nobody.
 *
 * @param int   $event_id Event post ID.
 * @param mixed $ids      User IDs; normalized through `sanitize_ids()`.
 */
function set_event_hosts( int $event_id, $ids ): void {
	$ids = sanitize_ids( $ids );

	if ( $ids ) {
		update_post_meta( $event_id, META_KEY, $ids );
	} else {
		delete_post_meta( $event_id, META_KEY );
	}
}
