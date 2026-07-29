<?php
/**
 * Default field values for the front-end event form.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\Defaults;

defined( 'WPINC' ) || die();

use GatherPress\Core\Event\Event;
use GatherPress\Core\Venue\Setup as Venue_Setup;

/**
 * Build the default field values for the create-event form.
 *
 * Defaults are scoped to the current site (group), not per user — every
 * organizer that opens the form gets the same prefilled venue and time-of-day
 * based on the most recent event on this group.
 *
 * - **Date** is always set to today + 7 days (a sensible "next week" default).
 * - **Start time** and **end time** are copied from the most recent event's
 *   times-of-day, falling back to 18:00 / 20:00 if there is no prior event.
 * - **Venue ID** is the venue assigned to the most recent event, or `0` if
 *   none.
 *
 * @return array{
 *     title:string,
 *     description:string,
 *     date:string,
 *     time_start:string,
 *     time_end:string,
 *     venue_id:int,
 *     is_online:bool,
 *     online_event_link:string
 * }
 */
function get_default_event_data(): array {
	$defaults = array(
		'title'             => '',
		'description'       => '',
		'date'              => wp_date( 'Y-m-d', strtotime( '+7 days' ) ),
		'time_start'        => '18:00',
		'time_end'          => '20:00',
		'venue_id'          => 0,
		'is_online'         => false,
		'online_event_link' => '',
	);

	$most_recent = get_most_recent_event_id();
	if ( ! $most_recent ) {
		return $defaults;
	}

	$event = new Event( $most_recent );

	// Pull the time-of-day from the previous event's stored datetime. The
	// `gatherpress_datetime_start` post meta is in `Y-m-d H:i:s` local time.
	$start = (string) get_post_meta( $most_recent, 'gatherpress_datetime_start', true );
	$end   = (string) get_post_meta( $most_recent, 'gatherpress_datetime_end', true );

	if ( $start && preg_match( '/(\d{2}:\d{2})/', $start, $m ) ) {
		$defaults['time_start'] = $m[1];
	}
	if ( $end && preg_match( '/(\d{2}:\d{2})/', $end, $m ) ) {
		$defaults['time_end'] = $m[1];
	}

	$defaults['venue_id'] = get_event_venue_post_id( $most_recent );

	return $defaults;
}

/**
 * Find the most recently published gatherpress_event on the current site.
 *
 * "Most recent" is by `post_date` (which is the post's creation/publish time,
 * not the event's start datetime — that's intentional, we want the *most
 * recently set up* event so the organizer's last choices are remembered).
 */
function get_most_recent_event_id(): int {
	$posts = get_posts(
		array(
			'post_type'        => Event::POST_TYPE,
			'post_status'      => 'publish',
			'posts_per_page'   => 1,
			'orderby'          => 'date',
			'order'            => 'DESC',
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => false,
		)
	);

	return $posts ? (int) $posts[0] : 0;
}

/**
 * Block names treated as "description prose" blocks.
 *
 * These are the core blocks the front-end edit modal's inline Gutenberg
 * editor knows how to render. Any block in `post_content` whose name is
 * **not** in this list is considered metadata (GatherPress event-date,
 * venue, RSVP, etc.) and is hidden from the editor on load and preserved
 * on save.
 *
 * Kept in sync with `WordCamp\Groups\Frontend\REST\build_post_content()`.
 */
const DESCRIPTION_BLOCK_NAMES = array(
	'core/paragraph',
	'core/heading',
	'core/list',
	'core/list-item',
	'core/image',
	'core/quote',
	'core/separator',
	'core/code',
	'core/preformatted',
	'core/group',
	'core/columns',
	'core/column',
);

/**
 * Pull a plain-text description out of an existing event's `post_content`.
 *
 * Concatenates the inner text of every `core/paragraph` block, ignoring all
 * the GatherPress metadata blocks (event-date, venue, RSVP, etc.) so when
 * the form reopens an existing event the description box only contains the
 * prose the user originally typed.
 */
function extract_description_text( int $event_id ): string {
	$content = (string) get_post_field( 'post_content', $event_id );
	if ( '' === $content ) {
		return '';
	}

	$parts = array();
	foreach ( parse_blocks( $content ) as $block ) {
		if ( 'core/paragraph' !== $block['blockName'] ) {
			continue;
		}
		$parts[] = trim( wp_strip_all_tags( $block['innerHTML'] ) );
	}

	return trim( implode( "\n\n", array_filter( $parts ) ) );
}

/**
 * Pull the description blocks (only) out of an existing event's post_content.
 *
 * Used by the REST `event-form-data` endpoint when loading an event for
 * editing — the inline block editor only knows how to render the
 * `DESCRIPTION_BLOCK_NAMES` set, so handing it the GatherPress metadata
 * blocks would trigger the editor's "Keep as HTML" recovery UI.
 *
 * Returns serialised block markup ready to feed straight into the editor.
 */
function extract_description_blocks( int $event_id ): string {
	$content = (string) get_post_field( 'post_content', $event_id );
	if ( '' === $content ) {
		return '';
	}

	$blocks = parse_blocks( $content );
	$kept   = array_filter(
		$blocks,
		static function ( $block ) {
			return in_array( $block['blockName'], DESCRIPTION_BLOCK_NAMES, true );
		}
	);

	return serialize_blocks( array_values( $kept ) );
}

/**
 * Resolve the gatherpress_venue post ID assigned to a given event.
 *
 * Delegates to GatherPress's own `Venue\Setup::get_venue_post_from_event_post_id()`
 * rather than reversing the `_gatherpress_venue` taxonomy term slug ourselves.
 */
function get_event_venue_post_id( int $event_id ): int {
	$venue_post = Venue_Setup::get_instance()->get_venue_post_from_event_post_id( $event_id );

	return $venue_post ? (int) $venue_post->ID : 0;
}
