<?php
/**
 * RSVP the organizer to an event they publish (#2255).
 *
 * An event used to start with nobody attending, not even the person running
 * it, so its count and attendee list were one short. The create form now has
 * an "I'm attending" box, ticked by default, and publishing with it ticked
 * RSVPs whoever published.
 *
 * Only the publisher, not the co-hosts: RSVPing someone else would put them
 * on the list, and send them a confirmation, for something they never did.
 * Only on first publish, so saving an edit can't put back an RSVP the
 * organizer has since cancelled.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\Organizer_RSVP;

use GatherPress\Core\Event\Event;
use GatherPress\Core\Rsvp\Rsvp;
use WordPressdotorg\GatherPress_Recurring_Events\Context;
use WordPressdotorg\GatherPress_Recurring_Events\Occurrences;
use WP_REST_Request;

defined( 'WPINC' ) || die();

/**
 * Request parameter the form sends.
 */
const PARAM = 'rsvp_organizer';

/**
 * The RSVP confirmation email's hook callback, skipped for this RSVP.
 */
const CONFIRMATION_CALLBACK = 'WordCamp\Groups\Frontend\RSVP_Confirmation\send_confirmation';

/** Registers the hooks. */
function bootstrap(): void {
	add_filter( 'wporg_groups_frontend_event_args_schema', __NAMESPACE__ . '\add_arg' );

	// After the recurring-events integration (priority 10) has projected a
	// series' dates, so there's a first date to RSVP to.
	add_action( 'wporg_groups_frontend_event_saved', __NAMESPACE__ . '\maybe_rsvp_organizer', 20, 3 );
}

/**
 * Adds the parameter to the event routes.
 *
 * Off unless sent, so a request that doesn't know about it RSVPs nobody.
 *
 * @param array $args REST argument schema.
 * @return array
 */
function add_arg( array $args ): array {
	$args[ PARAM ] = array(
		'type'              => 'boolean',
		'required'          => false,
		'default'           => false,
		'sanitize_callback' => 'rest_sanitize_boolean',
	);

	return $args;
}

/**
 * RSVPs the current user to an event they just published, if they asked to.
 *
 * @param int             $event_id          Saved event ID.
 * @param WP_REST_Request $request           The create or publish request.
 * @param bool            $schedule_editable Whether the event was unpublished before this save.
 */
function maybe_rsvp_organizer( int $event_id, WP_REST_Request $request, bool $schedule_editable ): void {
	$user_id = get_current_user_id();

	if ( ! $schedule_editable || ! $user_id || ! rest_sanitize_boolean( $request->get_param( PARAM ) ) ) {
		return;
	}

	if ( 'publish' !== get_post_status( $event_id ) ) {
		return;
	}

	$rsvp = new Rsvp( $event_id );

	if ( ! $rsvp->is_enabled() || ( new Event( $event_id ) )->has_event_past() ) {
		return;
	}

	// A series takes RSVPs one date at a time. The organizer is RSVP'd to
	// the first, as anyone opening the series page would be.
	$occurrence = null;

	if ( class_exists( Occurrences::class ) && apply_filters( 'wporg_groups_frontend_is_recurring_event', false, $event_id ) ) {
		$occurrence = Occurrences::select_for_series( $event_id );

		if ( ! $occurrence || 'cancelled' === $occurrence->status ) {
			return;
		}
	}

	$previous = $occurrence ? Context::get() : null;

	if ( $occurrence ) {
		Context::set( $occurrence );
	}

	// No "You're going" email: the organizer already gets one saying the
	// event is published, and two for a single click is noise.
	$confirms = remove_action( 'set_object_terms', CONFIRMATION_CALLBACK, 10 );

	$rsvp->save( $user_id, 'attending' );

	if ( $confirms ) {
		add_action( 'set_object_terms', CONFIRMATION_CALLBACK, 10, 6 );
	}

	if ( $occurrence ) {
		Context::set( $previous );
	}
}
