<?php
/**
 * Resolves the events a member counts as "mine" for the my-events block.
 *
 * Extracted from the block's `render.php` so the definition of "my events"
 * is testable on its own, and so the template is left with rendering.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\My_Events;

defined( 'WPINC' ) || die();

/**
 * Upcoming event IDs for a member, soonest first.
 *
 * "Mine" means either of two things, and an organiser is usually both:
 *
 * - events the member has RSVP'd to as attending,
 * - events the member authored, which on a group site means the ones they
 *   organise.
 *
 * Authorship is unioned in rather than written into the RSVP data, so
 * "attending" keeps meaning that the person said they are coming. That
 * distinction matters because the RSVP data feeds attendee counts, and
 * an organiser who creates an event they will not personally attend is a
 * normal thing on a group with several organisers.
 *
 * @param int $user_id Member to resolve events for.
 *
 * @return int[] Upcoming event post IDs, ordered by start time ascending.
 */
function get_upcoming_event_ids( int $user_id ): array {
	if ( ! $user_id ) {
		return array();
	}

	$event_ids = array_merge(
		get_attending_event_ids( $user_id ),
		get_authored_event_ids( $user_id )
	);

	$event_ids = array_values( array_unique( array_map( 'intval', $event_ids ) ) );

	if ( empty( $event_ids ) ) {
		return array();
	}

	return filter_to_upcoming( $event_ids );
}

/**
 * Event IDs the member has RSVP'd to as attending.
 *
 * @param int $user_id Member to resolve RSVPs for.
 *
 * @return int[] Event post IDs, unordered.
 */
function get_attending_event_ids( int $user_id ): array {
	$rsvp_comments = get_comments(
		array(
			'user_id' => $user_id,
			'type'    => 'gatherpress_rsvp',
			'status'  => 'approve',
			'number'  => 100,
		)
	);

	if ( empty( $rsvp_comments ) ) {
		return array();
	}

	$comment_event_ids = array();
	foreach ( $rsvp_comments as $rsvp_comment ) {
		$comment_event_ids[ (int) $rsvp_comment->comment_ID ] = (int) $rsvp_comment->comment_post_ID;
	}

	$rsvp_terms = wp_get_object_terms(
		array_keys( $comment_event_ids ),
		'_gatherpress_rsvp_status',
		array( 'fields' => 'all_with_object_id' )
	);

	if ( is_wp_error( $rsvp_terms ) ) {
		return array();
	}

	// Filter to attending RSVPs without re-querying per event.
	$attending_ids = array();
	foreach ( $rsvp_terms as $rsvp_term ) {
		if ( 'attending' !== $rsvp_term->slug ) {
			continue;
		}

		$event_id = $comment_event_ids[ (int) $rsvp_term->object_id ] ?? 0;
		if ( $event_id ) {
			$attending_ids[ $event_id ] = $event_id;
		}
	}

	return array_values( $attending_ids );
}

/**
 * Event IDs the member authored.
 *
 * @param int $user_id Member to resolve authored events for.
 *
 * @return int[] Event post IDs, unordered.
 */
function get_authored_event_ids( int $user_id ): array {
	return array_map(
		'intval',
		get_posts(
			array(
				'post_type'      => 'gatherpress_event',
				'post_status'    => 'publish',
				'author'         => $user_id,
				'fields'         => 'ids',
				'posts_per_page' => 100,
			)
		)
	);
}

/**
 * Reduce event IDs to the ones that have not finished yet, soonest first.
 *
 * Reads GatherPress's datetime table directly, as the block already did:
 * the dates live there rather than in post meta that `WP_Query` could order
 * by, and one query beats instantiating an event per candidate.
 *
 * @param int[] $event_ids Candidate event post IDs.
 *
 * @return int[] Upcoming event post IDs, ordered by start time ascending.
 */
function filter_to_upcoming( array $event_ids ): array {
	global $wpdb;

	if ( empty( $event_ids ) ) {
		return array();
	}

	$table        = $wpdb->prefix . 'gatherpress_events';
	$placeholders = implode( ', ', array_fill( 0, count( $event_ids ), '%d' ) );
	$query_args   = array_merge( $event_ids, array( current_time( 'mysql', true ) ) );

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and placeholders are generated locally.
	$upcoming_ids = $wpdb->get_col(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- As above.
			"SELECT post_id FROM {$table} WHERE post_id IN ( {$placeholders} ) AND datetime_end_gmt >= %s ORDER BY datetime_start_gmt ASC",
			$query_args
		)
	);

	return array_map( 'intval', (array) $upcoming_ids );
}
