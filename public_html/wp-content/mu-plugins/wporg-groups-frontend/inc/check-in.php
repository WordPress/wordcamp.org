<?php
/**
 * Event check-in: recording who actually came.
 *
 * GatherPress only knows RSVP intent (attending, waiting list, not attending),
 * so an RSVP'd no-show and someone who was there look the same, and nobody
 * can record a walk-in who never RSVP'd. This lets whoever can edit an event
 * check attendees in at the door or afterwards, and add walk-ins by their
 * WordPress.org account (#2130).
 *
 * Storage:
 *
 *   - **Check-ins** live in one `wporg_groups_checked_in` comment meta on the
 *     GatherPress RSVP comment, holding the GMT time of the check-in. Like the
 *     registration answers in `rsvp-questions.php`, keeping it on the comment
 *     means it goes away with the RSVP it belongs to.
 *   - **Which dates use check-in** lives in one `wporg_groups_check_in_scopes`
 *     post meta on the event: the recurrence ids (or `''` for a plain event)
 *     with at least one check-in. Consumers deciding what "attended" means
 *     need to know whether the organizer checked anyone in at all, and this
 *     answers that without reading every attendee's meta.
 *
 * A walk-in gets an ordinary RSVP, made on their behalf, which is then
 * checked in. They are not added to the group: joining, and the group email
 * that comes with it, stays their own choice.
 *
 * Namespace: `wporg-groups/v1`
 *
 *   GET  /event/{id}/check-in
 *        The attendees and waiting list, with who is checked in.
 *
 *   POST /event/{id}/check-in/{comment_id}
 *        Check one RSVP in, or undo it.
 *
 *   POST /event/{id}/walk-in
 *        Add someone by username or email and check them in.
 *
 * Every route takes an optional `recurrence_id`, resolved through the same
 * `wporg_groups_frontend_before_rsvp` filter as the RSVP route, so a series'
 * dates each have their own list. All three return the updated list.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\Check_In;

defined( 'WPINC' ) || die();

use GatherPress\Core\Event\Event;
use GatherPress\Core\Rsvp\Rsvp;
use WP_Comment;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use const WordCamp\Groups\Frontend\REST\NAMESPACE_V1;

use function WordCamp\Groups\Frontend\Capabilities\current_user_can_manage_events;
use function WordCamp\Groups\Frontend\My_Events\get_comment_recurrence_ids;
use function WordCamp\Groups\Frontend\REST\current_user_can_edit_event;

const CHECKED_IN_META = 'wporg_groups_checked_in';
const SCOPES_META     = 'wporg_groups_check_in_scopes';

/**
 * How long before the start check-in opens, in minutes. Early enough for an
 * organizer at the door as people arrive, late enough that nobody can mark a
 * future event as attended.
 */
const OPENS_BEFORE_START = 60;

/**
 * Hook the REST routes.
 */
function bootstrap(): void {
	add_action( 'rest_api_init', __NAMESPACE__ . '\register_routes' );
}

/**
 * Register the check-in routes.
 */
function register_routes(): void {
	$id_arg = array(
		'type'              => 'integer',
		'required'          => true,
		'sanitize_callback' => 'absint',
	);

	$recurrence_arg = array(
		'type'              => 'string',
		'required'          => false,
		'sanitize_callback' => 'sanitize_text_field',
	);

	register_rest_route(
		NAMESPACE_V1,
		'/event/(?P<id>\d+)/check-in',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\get_check_in_list',
			'permission_callback' => __NAMESPACE__ . '\permissions_check',
			'args'                => array(
				'id'            => $id_arg,
				'recurrence_id' => $recurrence_arg,
			),
		)
	);

	register_rest_route(
		NAMESPACE_V1,
		'/event/(?P<id>\d+)/check-in/(?P<comment_id>\d+)',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => __NAMESPACE__ . '\update_check_in',
			'permission_callback' => __NAMESPACE__ . '\permissions_check',
			'args'                => array(
				'id'            => $id_arg,
				'comment_id'    => $id_arg,
				'checked_in'    => array(
					'type'     => 'boolean',
					'required' => true,
				),
				'recurrence_id' => $recurrence_arg,
			),
		)
	);

	register_rest_route(
		NAMESPACE_V1,
		'/event/(?P<id>\d+)/walk-in',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => __NAMESPACE__ . '\add_walk_in',
			'permission_callback' => __NAMESPACE__ . '\permissions_check',
			'args'                => array(
				'id'            => $id_arg,
				'login'         => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'recurrence_id' => $recurrence_arg,
			),
		)
	);
}

/**
 * Capability check shared by every check-in route: the same gate as the
 * other per-event organizer tools, so an Event Organizer can check in their
 * own events and an Organizer can check in anyone's.
 *
 * @param WP_REST_Request $request REST request.
 */
function permissions_check( WP_REST_Request $request ): bool {
	return current_user_can_manage_events()
		&& current_user_can_edit_event( (int) $request->get_param( 'id' ) );
}

/**
 * Whether check-in is open for an event: from shortly before it starts, and
 * with no end, since organizers often catch up on the list afterwards.
 *
 * Reads the active occurrence's dates when a recurring date is in context.
 *
 * @param Event $event The event.
 */
function is_open( Event $event ): bool {
	return $event->has_event_started( -OPENS_BEFORE_START );
}

/**
 * Whether an RSVP is checked in.
 *
 * @param int $comment_id RSVP comment ID.
 */
function is_checked_in( int $comment_id ): bool {
	return '' !== (string) get_comment_meta( $comment_id, CHECKED_IN_META, true );
}

/**
 * Check an RSVP in, or undo it, and keep the event's record of which dates
 * use check-in in step.
 *
 * @param WP_Comment $comment    RSVP comment.
 * @param bool       $checked_in Whether they came.
 */
function set_checked_in( WP_Comment $comment, bool $checked_in ): void {
	$comment_id = (int) $comment->comment_ID;

	if ( $checked_in ) {
		// Keep the original time when someone is checked in twice.
		if ( ! is_checked_in( $comment_id ) ) {
			update_comment_meta( $comment_id, CHECKED_IN_META, current_time( 'mysql', true ) );
		}
	} else {
		delete_comment_meta( $comment_id, CHECKED_IN_META );
	}

	$scope = get_comment_recurrence_ids( array( $comment_id ) )[ $comment_id ] ?? '';

	sync_scope( (int) $comment->comment_post_ID, $scope );
}

/**
 * Recompute whether one date of an event has any check-ins.
 *
 * Recomputed from the comments rather than counted up and down, so a missed
 * or repeated request can't leave the flag wrong.
 *
 * @param int    $event_id Event post ID.
 * @param string $scope    Recurrence ID, or `''` for a plain event.
 */
function sync_scope( int $event_id, string $scope ): void {
	$checked_in_ids = get_comments(
		array(
			'post_id'  => $event_id,
			'type'     => Rsvp::COMMENT_TYPE,
			'status'   => 'approve',
			'meta_key' => CHECKED_IN_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One event's RSVPs.
			'fields'   => 'ids',
		)
	);

	$recurrence_ids = get_comment_recurrence_ids( array_map( 'intval', $checked_in_ids ) );
	$has_check_ins  = false;

	foreach ( $checked_in_ids as $comment_id ) {
		if ( ( $recurrence_ids[ (int) $comment_id ] ?? '' ) === $scope ) {
			$has_check_ins = true;
			break;
		}
	}

	$scopes = get_check_in_scopes( $event_id );

	if ( $has_check_ins ) {
		$scopes[] = $scope;
	} else {
		$scopes = array_diff( $scopes, array( $scope ) );
	}

	$scopes = array_values( array_unique( $scopes ) );

	if ( $scopes ) {
		update_post_meta( $event_id, SCOPES_META, $scopes );
	} else {
		delete_post_meta( $event_id, SCOPES_META );
	}
}

/**
 * The dates of an event with at least one check-in.
 *
 * @param int $event_id Event post ID.
 * @return string[] Recurrence IDs, with `''` standing for a plain event.
 */
function get_check_in_scopes( int $event_id ): array {
	$scopes = get_post_meta( $event_id, SCOPES_META, true );

	return is_array( $scopes ) ? array_map( 'strval', $scopes ) : array();
}

/**
 * Whether the organizer used check-in for a date of an event. When they did,
 * "attended" means checked in; when they didn't, an RSVP is the best record
 * there is.
 *
 * @param int    $event_id      Event post ID.
 * @param string $recurrence_id Recurrence ID, or `''` for a plain event.
 */
function event_has_check_ins( int $event_id, string $recurrence_id = '' ): bool {
	return in_array( $recurrence_id, get_check_in_scopes( $event_id ), true );
}

/**
 * Resolve the event a check-in request is for, with its occurrence in context.
 *
 * @param WP_REST_Request $request REST request.
 * @return Event|WP_Error
 */
function resolve_event( WP_REST_Request $request ) {
	$event_id = (int) $request->get_param( 'id' );
	$post     = get_post( $event_id );

	if ( ! $post || Event::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
		return new WP_Error( 'wporg_groups_invalid_event', 'Invalid event ID', array( 'status' => 404 ) );
	}

	// Same hook as the RSVP route: a recurring series resolves which date the
	// list is for, and a walk-in's RSVP is mapped to that date.
	$context_error = apply_filters( 'wporg_groups_frontend_before_rsvp', null, $event_id, $request );
	if ( is_wp_error( $context_error ) ) {
		return $context_error;
	}

	$event = new Event( $event_id );

	if ( ! $event->rsvp || ! $event->rsvp->is_enabled() ) {
		return new WP_Error( 'wporg_groups_rsvp_unavailable', 'RSVP is not available for this event.', array( 'status' => 400 ) );
	}

	return $event;
}

/**
 * Refuse a change to check-ins before check-in opens.
 *
 * @param Event $event The event.
 * @return true|WP_Error
 */
function assert_open( Event $event ) {
	if ( is_open( $event ) ) {
		return true;
	}

	return new WP_Error(
		'wporg_groups_check_in_not_open',
		__( 'Check-in opens an hour before the event starts.', 'wordcamporg' ),
		array( 'status' => 400 )
	);
}

/**
 * The people who can be checked in, and who has been.
 *
 * Attending and waiting list only: someone who said they weren't coming is
 * added back through the walk-in field if they turn up anyway.
 *
 * @param Event $event The event, with its occurrence in context.
 * @return array{attendees: array, checkedInCount: int, isOpen: bool}
 */
function build_list( Event $event ): array {
	$responses = $event->rsvp->responses();
	$records   = array_merge(
		$responses['attending']['records'] ?? array(),
		$responses['waiting_list']['records'] ?? array()
	);

	$comment_ids = array_filter( array_map( 'intval', wp_list_pluck( $records, 'commentId' ) ) );
	if ( $comment_ids ) {
		update_meta_cache( 'comment', $comment_ids );
	}

	$user_ids = array_filter( array_map( 'intval', wp_list_pluck( $records, 'userId' ) ) );
	if ( $user_ids ) {
		cache_users( $user_ids );
	}

	$attendees = array();
	$checked   = 0;

	foreach ( $records as $record ) {
		$comment_id = (int) ( $record['commentId'] ?? 0 );
		if ( ! $comment_id ) {
			continue;
		}

		$user       = get_userdata( (int) ( $record['userId'] ?? 0 ) );
		$checked_in = is_checked_in( $comment_id );

		if ( $checked_in ) {
			++$checked;
		}

		$attendees[] = array(
			'commentId' => $comment_id,
			'name'      => (string) ( $record['name'] ?? '' ),
			'login'     => $user ? $user->user_login : '',
			'photo'     => (string) ( $record['photo'] ?? '' ),
			'status'    => (string) ( $record['status'] ?? '' ),
			'checkedIn' => $checked_in,
		);
	}

	usort(
		$attendees,
		static fn( array $a, array $b ): int => strcasecmp( $a['name'], $b['name'] )
	);

	return array(
		'attendees'      => $attendees,
		'checkedInCount' => $checked,
		'isOpen'         => is_open( $event ),
	);
}

/**
 * GET /event/{id}/check-in
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function get_check_in_list( WP_REST_Request $request ) {
	$event = resolve_event( $request );
	if ( is_wp_error( $event ) ) {
		return $event;
	}

	return new WP_REST_Response( build_list( $event ) );
}

/**
 * POST /event/{id}/check-in/{comment_id}
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function update_check_in( WP_REST_Request $request ) {
	$event = resolve_event( $request );
	if ( is_wp_error( $event ) ) {
		return $event;
	}

	$open = assert_open( $event );
	if ( is_wp_error( $open ) ) {
		return $open;
	}

	$comment = get_comment( (int) $request->get_param( 'comment_id' ) );

	// The permission check was against the event in the URL, so the RSVP has
	// to belong to that event, or an Event Organizer could check in (and
	// thereby flag) RSVPs on events they can't edit.
	if (
		! $comment instanceof WP_Comment
		|| Rsvp::COMMENT_TYPE !== $comment->comment_type
		|| (int) $comment->comment_post_ID !== $event->event->ID
		|| '1' !== (string) $comment->comment_approved
	) {
		return new WP_Error( 'wporg_groups_invalid_rsvp', 'Invalid RSVP.', array( 'status' => 404 ) );
	}

	set_checked_in( $comment, (bool) $request->get_param( 'checked_in' ) );

	return new WP_REST_Response( build_list( $event ) );
}

/**
 * Look up a walk-in's account by exact username or email.
 *
 * Exact matches only, never a search, so the field can't be used to browse
 * WordPress.org accounts or fish for the email addresses behind them.
 *
 * @param string $login Username or email address.
 * @return \WP_User|false
 */
function find_walk_in_user( string $login ) {
	$login = trim( $login );

	if ( '' === $login ) {
		return false;
	}

	if ( is_email( $login ) ) {
		return get_user_by( 'email', $login );
	}

	return get_user_by( 'login', $login );
}

/**
 * POST /event/{id}/walk-in
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function add_walk_in( WP_REST_Request $request ) {
	$event = resolve_event( $request );
	if ( is_wp_error( $event ) ) {
		return $event;
	}

	$open = assert_open( $event );
	if ( is_wp_error( $open ) ) {
		return $open;
	}

	$user = find_walk_in_user( (string) $request->get_param( 'login' ) );

	if ( ! $user ) {
		return new WP_Error(
			'wporg_groups_walk_in_not_found',
			__( 'No WordPress.org account matches that username or email address.', 'wordcamporg' ),
			array( 'status' => 404 )
		);
	}

	$existing = $event->rsvp->get( $user->ID );
	$status   = (string) ( $existing['status'] ?? '' );

	// Someone already attending or on the waiting list keeps their RSVP as it
	// is; anyone else who turned up gets one. A full event may still put them
	// on the waiting list, which doesn't matter here: they are checked in,
	// and check-in is what records that they came.
	if ( ! in_array( $status, array( 'attending', 'waiting_list' ), true ) ) {
		$existing = $event->rsvp->save( $user->ID, 'attending' );
	}

	$comment = get_comment( (int) ( $existing['comment_id'] ?? 0 ) );

	if ( ! $comment instanceof WP_Comment ) {
		return new WP_Error(
			'wporg_groups_walk_in_failed',
			__( 'The walk-in could not be added.', 'wordcamporg' ),
			array( 'status' => 500 )
		);
	}

	set_checked_in( $comment, true );

	$response            = build_list( $event );
	$response['added']   = (int) $comment->comment_ID;
	$response['message'] = sprintf(
		/* translators: %s: attendee's display name. */
		__( '%s is checked in.', 'wordcamporg' ),
		$user->display_name
	);

	return new WP_REST_Response( $response );
}
