<?php
/**
 * The topics an event covers.
 *
 * GatherPress registers a topic taxonomy for events, but nothing on the group
 * sites let an organizer set it or a visitor see it. Meetup lets hosts tag an
 * event and lets attendees follow a tag to the rest of the group's events on
 * it, and testers missed that (#2040, #2132).
 *
 * Topics are free-form: organizers type their own, and the form suggests the
 * ones the group already uses so the vocabulary converges. Each group is its
 * own site, so a stray topic stays on the group that made it.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\Event_Topics;

use GatherPress\Core\Topic;
use WP_Term;

defined( 'WPINC' ) || die();

/**
 * The most topics one event can carry.
 *
 * A topic is a way into the archive, and an event tagged with everything
 * leads nowhere in particular.
 */
const MAX_TOPICS = 5;

/**
 * The longest topic name accepted, in characters.
 */
const MAX_LENGTH = 50;

/**
 * Normalize a submitted list of topic names.
 *
 * Trims, drops blanks, removes case-insensitive duplicates (the first spelling
 * wins) and keeps the first `MAX_TOPICS`. Enforced here rather than trusted
 * from the form, since the REST endpoint is the real write path.
 *
 * @param mixed $names Submitted topic names.
 * @return string[] Clean topic names, in the order given.
 */
function sanitize_names( $names ): array {
	if ( ! is_array( $names ) ) {
		return array();
	}

	$clean = array();

	foreach ( $names as $name ) {
		if ( ! is_scalar( $name ) ) {
			continue;
		}

		$name = trim( mb_substr( sanitize_text_field( (string) $name ), 0, MAX_LENGTH ) );
		$key  = mb_strtolower( $name );

		if ( '' === $name || isset( $clean[ $key ] ) ) {
			continue;
		}

		$clean[ $key ] = $name;

		if ( count( $clean ) >= MAX_TOPICS ) {
			break;
		}
	}

	return array_values( $clean );
}

/**
 * The topics an event carries, in name order.
 *
 * @param int $event_id Event post ID.
 * @return WP_Term[]
 */
function get_event_topic_terms( int $event_id ): array {
	$terms = get_the_terms( $event_id, Topic::TAXONOMY );

	if ( ! is_array( $terms ) ) {
		return array();
	}

	usort( $terms, static fn( WP_Term $first, WP_Term $second ): int => strcasecmp( $first->name, $second->name ) );

	return $terms;
}

/**
 * The names of the topics an event carries, for the event form.
 *
 * Decoded because term names are stored entity-encoded and these fill a
 * token field, not HTML.
 *
 * @param int $event_id Event post ID.
 * @return string[]
 */
function get_event_topics( int $event_id ): array {
	return array_map(
		static fn( WP_Term $term ): string => html_entity_decode( $term->name ),
		get_event_topic_terms( $event_id )
	);
}

/**
 * Replace an event's topics, creating any the group has not used before.
 *
 * An existing topic is matched by name, case-insensitively through the
 * database collation, so "wordpress" joins "WordPress" rather than creating
 * a near-duplicate. New topics are created at the top level: the taxonomy is
 * hierarchical in GatherPress, but a free-form field has no way to ask for a
 * parent.
 *
 * @param int   $event_id Event post ID.
 * @param mixed $names    Topic names; normalized through `sanitize_names()`.
 */
function set_event_topics( int $event_id, $names ): void {
	$term_ids = array();

	foreach ( sanitize_names( $names ) as $name ) {
		$term = get_term_by( 'name', $name, Topic::TAXONOMY );

		if ( $term instanceof WP_Term ) {
			$term_ids[] = (int) $term->term_id;
			continue;
		}

		$inserted = wp_insert_term( $name, Topic::TAXONOMY );

		if ( is_wp_error( $inserted ) ) {
			// A slug collision with a differently-named topic: reuse it
			// rather than dropping the organizer's choice.
			$existing = $inserted->get_error_data( 'term_exists' );

			if ( $existing ) {
				$term_ids[] = (int) $existing;
			}

			continue;
		}

		$term_ids[] = (int) $inserted['term_id'];
	}

	wp_set_object_terms( $event_id, array_values( array_unique( $term_ids ) ), Topic::TAXONOMY );
}

/**
 * The topics this group has used on a published event, for the form's
 * suggestions.
 *
 * `hide_empty` counts published events only, so a topic typed into a draft
 * that was then abandoned is not offered back to everyone else.
 *
 * @return string[] Topic names, in name order.
 */
function get_suggestions(): array {
	$terms = get_terms(
		array(
			'taxonomy'   => Topic::TAXONOMY,
			'hide_empty' => true,
			'orderby'    => 'name',
			'number'     => 200,
		)
	);

	if ( ! is_array( $terms ) ) {
		return array();
	}

	// `hide_empty` unsets the empty terms of a hierarchical taxonomy and keeps
	// the keys, which REST would then encode as an object instead of a list.
	return array_values(
		array_map(
			static fn( WP_Term $term ): string => html_entity_decode( $term->name ),
			$terms
		)
	);
}

/**
 * The events archive, narrowed to one topic.
 *
 * Links into the archive's own filter rather than GatherPress's topic
 * archive, so the visitor keeps the upcoming/past toggle and the other
 * filters.
 *
 * @param WP_Term $term Topic term.
 */
function get_topic_archive_url( WP_Term $term ): string {
	$archive = get_post_type_archive_link( 'gatherpress_event' ) ?: home_url( '/event/' );

	return add_query_arg( 'event_topic', $term->slug, $archive );
}
