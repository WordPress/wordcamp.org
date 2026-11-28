<?php
/**
 * Banning members from a group (#2395).
 *
 * An organizer can ban a member when removing them. A banned person can't
 * join the group again or RSVP to its events until an organizer lifts the
 * ban. Lifting it doesn't add them back; they can join again themselves.
 *
 * The ban is user meta scoped to the group site, written before the member is
 * removed, so it outlives their membership and doesn't touch other groups.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\Member_Bans;

defined( 'WPINC' ) || die();

use WordCamp\Groups\Frontend\Members\Members_Controller;
use WP_Error;
use WP_REST_Request;
use WP_User_Query;

const META_KEY = 'wporg_groups_ban';

// The RSVP routes that don't go through ours, keyed as `rest_endpoints` keys them.
const GUARDED_RSVP_ROUTES = array(
	'/gatherpress/v1/event/rsvp',
	'/gpre/v1/event/(?P<recurrence_id>[0-9]{8}(?:T[0-9]{6})?)/rsvp',
);

/**
 * Refuse banned people wherever they'd be added to a group.
 *
 * Registered on every site, not only those running GatherPress: the Meetup
 * import adds members from the network root.
 */
function bootstrap(): void {
	add_filter( 'can_add_user_to_blog', __NAMESPACE__ . '\refuse_banned_user', 10, 4 );
}

/**
 * Refuse RSVPs from banned people on GatherPress's and the recurring events
 * routes. Our own route checks in `REST\save_rsvp()`.
 */
function bootstrap_rsvp_guards(): void {
	add_filter( 'rest_endpoints', __NAMESPACE__ . '\guard_rsvp_routes' );
}

/**
 * The user meta key holding bans for one group.
 *
 * @param int $blog_id Group site ID. Defaults to the current site.
 */
function get_meta_key( int $blog_id = 0 ): string {
	global $wpdb;

	return $wpdb->get_blog_prefix( $blog_id ? $blog_id : get_current_blog_id() ) . META_KEY;
}

/**
 * A user's ban from a group, if they have one.
 *
 * @param int $user_id User ID.
 * @param int $blog_id Group site ID. Defaults to the current site.
 * @return array{by: int, at: int}|null Who banned them and when, or null.
 */
function get_ban( int $user_id, int $blog_id = 0 ): ?array {
	if ( $user_id <= 0 ) {
		return null;
	}

	$ban = get_user_meta( $user_id, get_meta_key( $blog_id ), true );

	return is_array( $ban ) ? $ban : null;
}

/**
 * Whether a user is banned from a group.
 *
 * @param int $user_id User ID.
 * @param int $blog_id Group site ID. Defaults to the current site.
 */
function is_banned( int $user_id, int $blog_id = 0 ): bool {
	return null !== get_ban( $user_id, $blog_id );
}

/**
 * Ban a user from a group.
 *
 * @param int $user_id   User to ban.
 * @param int $banned_by Organizer banning them.
 * @param int $blog_id   Group site ID. Defaults to the current site.
 */
function ban( int $user_id, int $banned_by, int $blog_id = 0 ): void {
	update_user_meta(
		$user_id,
		get_meta_key( $blog_id ),
		array(
			'by' => $banned_by,
			'at' => time(),
		)
	);
}

/**
 * Lift a user's ban from a group. They stay out until they join again.
 *
 * @param int $user_id User ID.
 * @param int $blog_id Group site ID. Defaults to the current site.
 * @return bool Whether there was a ban to lift.
 */
function unban( int $user_id, int $blog_id = 0 ): bool {
	return delete_user_meta( $user_id, get_meta_key( $blog_id ) );
}

/**
 * Everyone banned from a group, most recent first.
 *
 * @param int $blog_id Group site ID. Defaults to the current site.
 * @return array[] Each with `id`, `name`, `bannedBy` and `bannedAt` (ISO 8601).
 */
function get_banned_users( int $blog_id = 0 ): array {
	$query = new WP_User_Query(
		array(
			'blog_id'     => 0,
			'meta_key'    => get_meta_key( $blog_id ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'number'      => -1,
			'count_total' => false,
		)
	);

	$banned = array();

	foreach ( $query->get_results() as $user ) {
		$ban = get_ban( (int) $user->ID, $blog_id );

		if ( ! $ban ) {
			continue;
		}

		$by = get_userdata( (int) ( $ban['by'] ?? 0 ) );

		$banned[] = array(
			'id'       => (int) $user->ID,
			'name'     => Members_Controller::member_name( $user ),
			'bannedBy' => $by ? Members_Controller::member_name( $by ) : '',
			'bannedAt' => gmdate( 'c', (int) ( $ban['at'] ?? 0 ) ),
		);
	}

	usort( $banned, static fn( array $a, array $b ): int => strcmp( $b['bannedAt'], $a['bannedAt'] ) );

	return $banned;
}

/**
 * What a banned person is told.
 */
function get_message(): string {
	return __( "You can't join this group.", 'wordcamporg' );
}

/**
 * The error returned to a banned person.
 */
function get_error(): WP_Error {
	return new WP_Error( 'wporg_groups_banned', get_message(), array( 'status' => 403 ) );
}

/**
 * Keep a banned user from being added to the group they're banned from.
 *
 * Covers `/members/join`, the join when someone RSVPs, the Meetup import and
 * wp-admin, which all go through `add_user_to_blog()`.
 *
 * @param true|WP_Error $retval  Whether the user can be added so far.
 * @param int           $user_id User being added.
 * @param string        $role    Role they'd get.
 * @param int           $blog_id Site they'd be added to.
 * @return true|WP_Error
 */
function refuse_banned_user( $retval, $user_id, $role, $blog_id ) {
	if ( true !== $retval ) {
		return $retval;
	}

	return is_banned( (int) $user_id, (int) $blog_id ) ? get_error() : $retval;
}

/**
 * Refuse a banned user's RSVP to attend. Cancelling is left alone.
 *
 * @param int    $user_id User RSVPing.
 * @param string $status  Requested status.
 * @return true|WP_Error
 */
function check_rsvp( int $user_id, string $status ) {
	if ( 'attending' !== $status || ! is_banned( $user_id ) ) {
		return true;
	}

	return get_error();
}

/**
 * Wrap the permission callbacks of the other RSVP routes with the ban check.
 *
 * Wrapped rather than replaced, so each route's own checks still run. They
 * need their own check because they save the RSVP even when the join that
 * comes with it fails.
 *
 * @param array $endpoints Registered REST endpoints.
 * @return array
 */
function guard_rsvp_routes( array $endpoints ): array {
	foreach ( GUARDED_RSVP_ROUTES as $route ) {
		if ( empty( $endpoints[ $route ] ) ) {
			continue;
		}

		foreach ( $endpoints[ $route ] as $index => $handler ) {
			if ( ! is_array( $handler ) || ! isset( $handler['permission_callback'] ) ) {
				continue;
			}

			$original = $handler['permission_callback'];

			$endpoints[ $route ][ $index ]['permission_callback'] = static function ( $request ) use ( $original ) {
				$allowed = call_user_func( $original, $request );

				if ( true !== $allowed ) {
					return $allowed;
				}

				return check_rsvp( requested_user_id( $request ), sanitize_key( (string) $request->get_param( 'status' ) ) );
			};
		}
	}

	return $endpoints;
}

/**
 * The user an RSVP request is for.
 *
 * @param WP_REST_Request $request RSVP request.
 */
function requested_user_id( WP_REST_Request $request ): int {
	$user_id = (int) $request->get_param( 'user_id' );

	return $user_id ? $user_id : get_current_user_id();
}
