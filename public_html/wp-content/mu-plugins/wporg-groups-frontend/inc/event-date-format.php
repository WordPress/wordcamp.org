<?php
/**
 * How this group writes its event dates and times.
 *
 * GatherPress puts the start and end formats on the Event Date block as raw PHP
 * format-code text inputs, so they are set per block and therefore per event,
 * and typing `l, F j` is not a reasonable thing to ask an organizer for
 * (#2033, upstream GatherPress/gatherpress#2285).
 *
 * Both WordPress and GatherPress already hold a per-site date and time format,
 * but each needs `manage_options` to reach, which an organizer does not have,
 * and the groups-site templates pass explicit formats that would override them
 * anyway. This module is the group-level answer: one choice per group, made
 * from named examples rather than format codes, applied to the event page, the
 * event cards and the event emails alike.
 *
 * Stored in this network's own options rather than written into
 * `gatherpress_settings`, so a value an administrator set on GatherPress's own
 * settings screen is never clobbered, and so an empty value can keep meaning
 * "whatever the template already says" rather than having to encode the
 * template's own defaults in a setting.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\Event_Date_Format;

defined( 'WPINC' ) || die();

const DATE_OPTION = 'wporg_groups_event_date_format';
const TIME_OPTION = 'wporg_groups_event_time_format';

/**
 * Class names a template puts on a `gatherpress/event-date` block to say which
 * of this group's formats it wants.
 *
 * A class name rather than a bespoke block attribute because a class name is a
 * core-supported attribute: Gutenberg keeps it when someone opens the template
 * in the Site Editor and saves, where an attribute the block does not declare
 * in its `block.json` would be dropped and the marker lost without a trace.
 */
const DATE_CLASS     = 'has-group-date-format';
const TIME_CLASS     = 'has-group-time-format';
const DATETIME_CLASS = 'has-group-datetime-format';

/** The separator between the date and the time in a combined format. */
const DATETIME_SEPARATOR = ' \\· ';

/** Hooks the group's formats into every surface that renders an event date. */
function bootstrap(): void {
	add_filter( 'render_block_data', __NAMESPACE__ . '\apply_to_event_date_block' );
	add_filter( 'gatherpress_date_format', __NAMESPACE__ . '\filter_date_format' );
	add_filter( 'gatherpress_time_format', __NAMESPACE__ . '\filter_time_format' );
}

/**
 * The date formats a group may choose from.
 *
 * Deliberately a short list. The point of the setting is to replace typing
 * format codes, and a group that wants something outside it is better served by
 * asking for another entry here than by being handed a text box.
 *
 * @return string[] PHP date format strings.
 */
function get_date_formats(): array {
	return array( 'l, F j', 'l, F j, Y', 'F j, Y', 'j F Y', 'D, j M Y', 'Y-m-d' );
}

/**
 * The time formats a group may choose from.
 *
 * @return string[] PHP time format strings.
 */
function get_time_formats(): array {
	return array( 'g:i A', 'g:i a', 'H:i' );
}

/**
 * A timestamp to render the example of each format from.
 *
 * Fixed rather than "now" so the examples do not change under the reader while
 * the settings modal is open, and chosen to be unambiguous in every format on
 * the list: a day late enough in the month, and an afternoon hour, so `j` and
 * `d` differ and a 12-hour clock is not the same as a 24-hour one.
 */
function get_example_timestamp(): int {
	return (int) strtotime( '2026-09-29 18:00:00' );
}

/**
 * Build the choices for the settings form: the value, and what it looks like.
 *
 * The example is rendered through `wp_date()`, so it arrives already localized
 * and the reader picks a format by recognizing the date rather than by reading
 * `l, F j`.
 *
 * @param string[] $formats PHP format strings.
 * @return array<int, array{format: string, example: string}> Choices.
 */
function build_choices( array $formats ): array {
	$timestamp = get_example_timestamp();

	return array_map(
		static fn( string $format ): array => array(
			'format'  => $format,
			'example' => wp_date( $format, $timestamp ),
		),
		$formats
	);
}

/**
 * Normalize a submitted format to one this group may choose.
 *
 * Anything else becomes the empty string, which every caller reads as "leave
 * the template's own format alone". A format is a `wp_date()` argument, so an
 * allowlist is also what keeps arbitrary input from reaching it.
 *
 * @param mixed    $format  Submitted format.
 * @param string[] $allowed The list it has to be on.
 * @return string An allowed format, or ''.
 */
function sanitize_format( $format, array $allowed ): string {
	$format = trim( (string) $format );

	return in_array( $format, $allowed, true ) ? $format : '';
}

/** The date format this group chose, or '' when it has not chosen one. */
function get_date_format(): string {
	return sanitize_format( get_option( DATE_OPTION, '' ), get_date_formats() );
}

/** The time format this group chose, or '' when it has not chosen one. */
function get_time_format(): string {
	return sanitize_format( get_option( TIME_OPTION, '' ), get_time_formats() );
}

/**
 * Store this group's date format, clearing it when the value is empty.
 *
 * @param string $format One of `get_date_formats()`, or '' to clear.
 */
function set_date_format( string $format ): void {
	$format = sanitize_format( $format, get_date_formats() );

	if ( '' === $format ) {
		delete_option( DATE_OPTION );
		return;
	}

	update_option( DATE_OPTION, $format );
}

/**
 * Store this group's time format, clearing it when the value is empty.
 *
 * @param string $format One of `get_time_formats()`, or '' to clear.
 */
function set_time_format( string $format ): void {
	$format = sanitize_format( $format, get_time_formats() );

	if ( '' === $format ) {
		delete_option( TIME_OPTION );
		return;
	}

	update_option( TIME_OPTION, $format );
}

/**
 * Apply the group's formats to a marked `gatherpress/event-date` block.
 *
 * The groups-site templates pass explicit start and end formats, and an
 * explicit format beats the `gatherpress_date_format` filter inside
 * `Event::get_display_datetime()`, so the two filters below cannot reach these
 * blocks. Rewriting the attribute before the block renders can, and leaves the
 * template's own format in place as the default for a group that has chosen
 * nothing.
 *
 * @param array $parsed_block A parsed block.
 * @return array The block, with its formats swapped where the group has said so.
 */
function apply_to_event_date_block( array $parsed_block ): array {
	if ( 'gatherpress/event-date' !== ( $parsed_block['blockName'] ?? '' ) ) {
		return $parsed_block;
	}

	$classes = explode( ' ', (string) ( $parsed_block['attrs']['className'] ?? '' ) );
	$date    = get_date_format();
	$time    = get_time_format();

	if ( in_array( DATETIME_CLASS, $classes, true ) ) {
		// One block showing both, as the event cards do. Composed from the two
		// choices rather than being a third setting, so a group answers the
		// question once.
		if ( '' !== $date || '' !== $time ) {
			$parsed_block['attrs']['startDateFormat'] = format_for_role( $parsed_block, 'start', $date, $time );
		}

		return $parsed_block;
	}

	if ( in_array( DATE_CLASS, $classes, true ) && '' !== $date ) {
		return with_formats( $parsed_block, $date );
	}

	if ( in_array( TIME_CLASS, $classes, true ) && '' !== $time ) {
		return with_formats( $parsed_block, $time );
	}

	return $parsed_block;
}

/**
 * Compose the combined format, falling back per half to what the block already
 * said so a group that chose only one of the two keeps the other.
 *
 * @param array  $parsed_block A parsed block.
 * @param string $which        Which of the block's formats to fall back to.
 * @param string $date         The group's date format, or ''.
 * @param string $time         The group's time format, or ''.
 */
function format_for_role( array $parsed_block, string $which, string $date, string $time ): string {
	$existing = (string) ( $parsed_block['attrs'][ $which . 'DateFormat' ] ?? '' );

	if ( '' === $date || '' === $time ) {
		// Only one half was chosen. Splitting the block's own combined format
		// back into a date half and a time half is not something a format
		// string can be asked for reliably, so leave it alone rather than
		// render something the template never described.
		return $existing;
	}

	return $date . DATETIME_SEPARATOR . $time;
}

/**
 * Replace whichever of the block's two formats it actually carries.
 *
 * Only the ones already present are set: adding an `endDateFormat` to a block
 * that had none would turn a start-only line into a range.
 *
 * @param array  $parsed_block A parsed block.
 * @param string $format       The format to apply.
 * @return array The block.
 */
function with_formats( array $parsed_block, string $format ): array {
	foreach ( array( 'startDateFormat', 'endDateFormat' ) as $attribute ) {
		if ( isset( $parsed_block['attrs'][ $attribute ] ) ) {
			$parsed_block['attrs'][ $attribute ] = $format;
		}
	}

	return $parsed_block;
}

/**
 * Apply the group's date format wherever GatherPress resolves its own.
 *
 * This is the half the block filter cannot reach: the event emails, which call
 * `get_display_datetime()` with no format at all.
 *
 * @param string $format GatherPress's own setting.
 */
function filter_date_format( $format ): string {
	$group = get_date_format();

	return '' !== $group ? $group : (string) $format;
}

/**
 * Apply the group's time format wherever GatherPress resolves its own.
 *
 * @param string $format GatherPress's own setting.
 */
function filter_time_format( $format ): string {
	$group = get_time_format();

	return '' !== $group ? $group : (string) $format;
}
