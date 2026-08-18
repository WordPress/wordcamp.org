<?php
/**
 * The RSVP count line, in one place.
 *
 * The line under the RSVP button ("14 going", "You and 3 others") has to be
 * produced twice: PHP renders it into the markup, and `view.js` re-renders it
 * in the browser when an RSVP changes the count without a reload. Script
 * modules can't depend on `wp-i18n` — modules and classic scripts aren't
 * interoperable — so the view module can't call `_n()` itself. It gets the
 * translated formats through `context.labels` instead and picks between them.
 *
 * That makes two branch implementations unavoidable. What is avoidable is two
 * copies of the *strings*: `get_count_parts()` below holds the only `_n()`
 * calls, `get_count_label()` finishes the server-rendered line from them, and
 * `get_count_formats()` harvests the same formats for the view module. Adding
 * a state means editing one function here and its mirror in `view.js`.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\RSVP_Labels;

defined( 'WPINC' ) || die();

/**
 * The keys `get_count_formats()` returns, which are also the keys
 * `getCountParts()` in `view.js` selects between. Named here so the test suite
 * can assert the two sides still agree on the vocabulary.
 */
const COUNT_FORMAT_KEYS = array(
	'countZero',
	'countSingular',
	'countPlural',
	'countYouFirst',
	'countYouAndOneOther',
	'countYouAndOthers',
);

/**
 * The count line's format string and the number that goes into it.
 *
 * Returns the format rather than the finished string so one set of branches
 * can serve both callers — `get_count_label()` fills it in with the real
 * count, `get_count_formats()` takes the format itself.
 *
 * The four states each add to what the RSVP button already says. Once you're
 * attending the button reads "Attending", so a bare "1 going" would just be
 * telling you about yourself — that state counts the *others* instead. Zero
 * gets the same treatment in reverse: nobody yet is an invitation, not a
 * headcount.
 *
 * @param int  $count        Number of attendees.
 * @param bool $is_attending Whether the current user is one of them.
 * @return array{0: string, 1: int|null} The format, and the number to
 *                                       substitute into it — or null when the
 *                                       format takes no argument.
 */
function get_count_parts( int $count, bool $is_attending ): array {
	$others = max( 0, $count - 1 );

	if ( $is_attending && $others > 0 ) {
		return array(
			/* translators: %s: number of attendees other than the current user. */
			_n( 'You and %s other', 'You and %s others', $others, 'wporg-groups-frontend' ),
			$others,
		);
	}

	if ( $is_attending ) {
		return array( __( 'First one in', 'wporg-groups-frontend' ), null );
	}

	if ( $count > 0 ) {
		return array(
			/* translators: %s: attendee count. */
			_n( '%s going', '%s going', $count, 'wporg-groups-frontend' ),
			$count,
		);
	}

	return array( __( 'Be the first to RSVP', 'wporg-groups-frontend' ), null );
}

/**
 * The finished count line for the server-rendered markup.
 *
 * Passes the real count to `_n()`, so languages with more than two plural
 * forms get the right one — something the view module can't do with a
 * two-entry format table.
 *
 * @param int  $count        Number of attendees.
 * @param bool $is_attending Whether the current user is one of them.
 * @return string
 */
function get_count_label( int $count, bool $is_attending ): string {
	list( $format, $value ) = get_count_parts( $count, $is_attending );

	if ( null === $value ) {
		return $format;
	}

	return sprintf( $format, number_format_i18n( $value ) );
}

/**
 * The count formats the view module picks between, keyed for `context.labels`.
 *
 * Each one is harvested from `get_count_parts()` at the smallest count that
 * reaches that branch, so the strings stay written once. The singular/plural
 * pairs are resolved here at n=1 and n=2, which is as far as a lookup table
 * goes: a language with a third plural form gets its n=2 wording for larger
 * counts until the next page load re-renders the line through
 * `get_count_label()`.
 *
 * Keyed positionally by `COUNT_FORMAT_KEYS` rather than spelling the keys
 * out a second time, so the two can't drift from each other.
 *
 * @return array<string, string> Keyed by `COUNT_FORMAT_KEYS`.
 */
function get_count_formats(): array {
	return array_combine(
		COUNT_FORMAT_KEYS,
		array(
			get_count_parts( 0, false )[0],
			get_count_parts( 1, false )[0],
			get_count_parts( 2, false )[0],
			get_count_parts( 1, true )[0],
			get_count_parts( 2, true )[0],
			get_count_parts( 3, true )[0],
		)
	);
}

/**
 * The keys `get_modal_title_formats()` returns, which are also the keys
 * `modalTitle` in `view.js` selects between.
 */
const MODAL_TITLE_FORMAT_KEYS = array( 'modalTitleSingular', 'modalTitlePlural' );

/**
 * The RSVP modal title's format string and the count to substitute.
 *
 * Same one-`_n()`-call-serves-both-callers shape as `get_count_parts()`:
 * `get_modal_title_label()` finishes the server-rendered title,
 * `get_modal_title_formats()` harvests the singular/plural formats for the
 * view module.
 *
 * @param int $count Number of attendees.
 * @return array{0: string, 1: int} The format, and the count to substitute.
 */
function get_modal_title_parts( int $count ): array {
	return array(
		/* translators: 1: attendee count, 2: event title */
		_n( '%1$s Attendee of %2$s', '%1$s Attendees of %2$s', $count, 'wporg-groups-frontend' ),
		$count,
	);
}

/**
 * The finished modal title for the server-rendered markup.
 *
 * @param int    $count       Number of attendees.
 * @param string $event_title Event title.
 * @return string
 */
function get_modal_title_label( int $count, string $event_title ): string {
	list( $format, $value ) = get_modal_title_parts( $count );

	return sprintf( $format, number_format_i18n( $value ), $event_title );
}

/**
 * The modal-title formats the view module picks between, keyed for
 * `context.labels`. Resolved at n=1 and n=2 — the same two-entry-table
 * tradeoff `get_count_formats()` makes, for the same reason.
 *
 * @return array<string, string> Keyed by `MODAL_TITLE_FORMAT_KEYS`.
 */
function get_modal_title_formats(): array {
	return array_combine(
		MODAL_TITLE_FORMAT_KEYS,
		array(
			get_modal_title_parts( 1 )[0],
			get_modal_title_parts( 2 )[0],
		)
	);
}
