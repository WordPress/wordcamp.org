<?php
/**
 * When an event takes RSVPs (#2397).
 *
 * Organizers can choose when RSVPs open and close, for example closing them
 * the day before so they can give the venue a final headcount. Both are
 * stored as times before the event starts, so each date of a recurring event
 * gets the same window without its own settings. Both are empty by default,
 * which leaves an event open for RSVPs until it ends, as before.
 *
 * Only new RSVPs are limited. Cancelling, leaving the waiting list, or
 * updating the answers on an existing RSVP stays possible at any time.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\RSVP_Window;

defined( 'WPINC' ) || die();

use DateTimeZone;
use Exception;
use GatherPress\Core\Event\Event;
use GatherPress\Core\Rsvp\Rsvp;
use WP_Error;
use WP_REST_Request;

use function WordCamp\Groups\Frontend\Event_Date_Format\get_date_format;
use function WordCamp\Groups\Frontend\Event_Date_Format\get_time_format;

const OPENS_META  = 'wporg_groups_rsvp_opens_before';
const CLOSES_META = 'wporg_groups_rsvp_closes_before';

// The event form's field for each setting.
const FIELDS = array(
	'rsvp_opens_before'  => OPENS_META,
	'rsvp_closes_before' => CLOSES_META,
);

// The longest either can be set before the event.
const MAX_BEFORE = YEAR_IN_SECONDS;

const OPEN    = 'open';
const NOT_YET = 'not_yet';
const CLOSED  = 'closed';

/**
 * The times an event's RSVPs open and close.
 *
 * Read from the event's start, so for a recurring event it is the window of
 * the date currently in context.
 *
 * @param int $event_id Event post ID.
 * @return array{opens: ?int, closes: ?int} Unix timestamps, null where unset.
 */
function get_window( int $event_id ): array {
	$window = array(
		'opens'  => null,
		'closes' => null,
	);

	$start_gmt = ( new Event( $event_id ) )->get_datetime()['datetime_start_gmt'] ?? '';
	$start     = $start_gmt ? strtotime( $start_gmt . ' UTC' ) : false;

	if ( ! $start ) {
		return $window;
	}

	$settings = get_settings( $event_id );

	if ( null !== $settings['rsvp_opens_before'] ) {
		$window['opens'] = $start - $settings['rsvp_opens_before'];
	}

	if ( null !== $settings['rsvp_closes_before'] ) {
		$window['closes'] = $start - $settings['rsvp_closes_before'];
	}

	return $window;
}

/**
 * Whether an event is taking RSVPs right now.
 *
 * @param int      $event_id Event post ID.
 * @param int|null $now      Unix timestamp to check against. Defaults to now.
 * @return string `OPEN`, `NOT_YET`, or `CLOSED`.
 */
function get_state( int $event_id, ?int $now = null ): string {
	$now    = $now ?? time();
	$window = get_window( $event_id );

	if ( null !== $window['opens'] && $now < $window['opens'] ) {
		return NOT_YET;
	}

	if ( null !== $window['closes'] && $now >= $window['closes'] ) {
		return CLOSED;
	}

	return OPEN;
}

/**
 * When RSVPs open, in the event's time zone and the group's date format.
 *
 * @param int $event_id Event post ID.
 * @return string For example "Nov 30, 2026 at 6:00 PM IST", or '' when unset.
 */
function get_opens_label( int $event_id ): string {
	$opens = get_window( $event_id )['opens'];

	if ( null === $opens ) {
		return '';
	}

	try {
		$timezone = new DateTimeZone( ( new Event( $event_id ) )->get_datetime()['timezone'] ?? '' );
	} catch ( Exception $e ) {
		$timezone = wp_timezone();
	}

	return sprintf(
		/* translators: 1: date, 2: time with time zone. */
		__( '%1$s at %2$s', 'wordcamporg' ),
		wp_date( get_date_format() ?: 'M j, Y', $opens, $timezone ),
		wp_date( ( get_time_format() ?: 'g:i A' ) . ' T', $opens, $timezone )
	);
}

/**
 * Why an RSVP can't be made right now, if it can't.
 *
 * @param int $event_id Event post ID.
 * @return string Empty while RSVPs are open.
 */
function get_unavailable_message( int $event_id ): string {
	switch ( get_state( $event_id ) ) {
		case NOT_YET:
			return sprintf(
				/* translators: %s: date and time RSVPs open. */
				__( 'RSVPs open on %s.', 'wordcamporg' ),
				get_opens_label( $event_id )
			);

		case CLOSED:
			return __( 'RSVPs for this event are closed.', 'wordcamporg' );
	}

	return '';
}

/**
 * Refuse a new RSVP outside the event's window.
 *
 * @param int    $event_id Event post ID.
 * @param string $status   The RSVP status being requested.
 * @param int    $user_id  Who is RSVPing. Defaults to the current user.
 * @return true|WP_Error True when the RSVP may be saved.
 */
function check_rsvp( int $event_id, string $status, int $user_id = 0 ) {
	if ( 'attending' !== $status ) {
		return true;
	}

	$user_id  = $user_id ? $user_id : get_current_user_id();
	$existing = $user_id ? ( ( new Rsvp( $event_id ) )->get( $user_id )['status'] ?? '' ) : '';

	if ( in_array( $existing, array( 'attending', 'waiting_list' ), true ) ) {
		return true;
	}

	$state = get_state( $event_id );

	if ( OPEN === $state ) {
		return true;
	}

	return new WP_Error(
		NOT_YET === $state ? 'wporg_groups_rsvp_not_open' : 'wporg_groups_rsvp_closed',
		get_unavailable_message( $event_id ),
		array( 'status' => 400 )
	);
}

/**
 * When an event's RSVPs open and close, as the event form edits them.
 *
 * @param int $event_id Event post ID.
 * @return array{rsvp_opens_before: ?int, rsvp_closes_before: ?int} Seconds before the start, null where unset.
 */
function get_settings( int $event_id ): array {
	$settings = array();

	foreach ( FIELDS as $field => $meta_key ) {
		$before             = get_post_meta( $event_id, $meta_key, true );
		$settings[ $field ] = '' !== $before && is_numeric( $before ) ? absint( $before ) : null;
	}

	return $settings;
}

/**
 * The settings an event would have after a request saves it.
 *
 * An absent field keeps the event's current setting, and null clears it.
 *
 * @param int             $event_id Event post ID, or 0 for a new event.
 * @param WP_REST_Request $request  The create, update or draft request.
 * @return array{rsvp_opens_before: ?int, rsvp_closes_before: ?int}
 */
function get_requested_settings( int $event_id, WP_REST_Request $request ): array {
	$settings = $event_id ? get_settings( $event_id ) : array_fill_keys( array_keys( FIELDS ), null );

	foreach ( array_keys( FIELDS ) as $field ) {
		if ( $request->has_param( $field ) ) {
			$before             = $request->get_param( $field );
			$settings[ $field ] = null === $before || '' === $before ? null : absint( $before );
		}
	}

	return $settings;
}

/**
 * Refuse a window that closes before it opens.
 *
 * @param int             $event_id Event post ID, or 0 for a new event.
 * @param WP_REST_Request $request  The create or update request.
 * @return true|WP_Error
 */
function validate_settings( int $event_id, WP_REST_Request $request ) {
	$settings = get_requested_settings( $event_id, $request );
	$opens    = $settings['rsvp_opens_before'];
	$closes   = $settings['rsvp_closes_before'];

	if ( null !== $opens && null !== $closes && $opens <= $closes ) {
		return new WP_Error(
			'wporg_groups_invalid_rsvp_window',
			__( 'RSVPs have to open before they close.', 'wordcamporg' ),
			array( 'status' => 400 )
		);
	}

	return true;
}

/**
 * Save when RSVPs open and close, for each setting the request carried.
 *
 * @param int             $event_id Saved event post ID.
 * @param WP_REST_Request $request  The create, update or draft request.
 */
function save_settings( int $event_id, WP_REST_Request $request ): void {
	$settings = get_requested_settings( $event_id, $request );

	foreach ( FIELDS as $field => $meta_key ) {
		if ( ! $request->has_param( $field ) ) {
			continue;
		}

		if ( null === $settings[ $field ] ) {
			delete_post_meta( $event_id, $meta_key );
		} else {
			update_post_meta( $event_id, $meta_key, $settings[ $field ] );
		}
	}
}
