<?php
/**
 * Occurrence-aware REST API.
 *
 * @package WordPressdotorg\GatherPress_Recurring_Events
 */

namespace WordPressdotorg\GatherPress_Recurring_Events;

use GatherPress\Core\Event\Event;
use GatherPress\Core\Rsvp\Cache;
use GatherPress\Core\Rsvp\Response\Status;
use GatherPress\Core\Rsvp\Rsvp;
use GatherPress\Core\Utility;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'WPINC' ) || die();

final class Rest_API {

	/** Registers occurrence-aware REST routes. */
	public static function register(): void {
		register_rest_route(
			'gpre/v1',
			'/occurrences/(?P<post_id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'occurrences' ),
				'permission_callback' => array( self::class, 'can_read_occurrences' ),
				'args'                => array(
					'post_id' => array(
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'gpre/v1',
			'/event/(?P<recurrence_id>[0-9]{8}(?:T[0-9]{6})?)/nonce',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => static function (): WP_REST_Response {
					// Our own pages need no CORS headers to read the nonce, and
					// the nonce is user-bound, so no foreign origin gets to see
					// one minted from the caller's cookie.
					remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );

					Utility::ensure_user_authentication();

					$response = new WP_REST_Response( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ) );

					// The answer depends on the origin, so nothing in front of
					// us may serve one origin's response to another.
					$response->header( 'Vary', 'Origin' );

					return $response;
				},
				'permission_callback' => array( self::class, 'is_same_origin_request' ),
			)
		);

		register_rest_route(
			'gpre/v1',
			'/event/(?P<recurrence_id>[0-9]{8}(?:T[0-9]{6})?)/rsvp',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( self::class, 'update_rsvp' ),
				'permission_callback' => static fn( WP_REST_Request $request ) => is_user_logged_in() && self::can_read( $request ),
			)
		);

		register_rest_route(
			'gpre/v1',
			'/event/(?P<recurrence_id>[0-9]{8}(?:T[0-9]{6})?)/rsvp-responses',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'responses' ),
				'permission_callback' => array( self::class, 'can_read' ),
			)
		);

		register_rest_route(
			'gpre/v1',
			'/occurrence/(?P<post_id>\d+)/(?P<recurrence_id>[0-9]{8}(?:T[0-9]{6})?)/status',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( self::class, 'status' ),
				'permission_callback' => static fn( WP_REST_Request $request ) => current_user_can( 'edit_post', (int) $request['post_id'] ),
			)
		);

		register_rest_route(
			'gpre/v1',
			'/series/(?P<post_id>\d+)/(?P<recurrence_id>[0-9]{8}(?:T[0-9]{6})?)/end',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( self::class, 'end_series' ),
				'permission_callback' => static fn( WP_REST_Request $request ) => current_user_can( 'edit_post', (int) $request['post_id'] ),
			)
		);
	}

	/**
	 * Returns projected future occurrences.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response REST response.
	 */
	public static function occurrences( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request['post_id'];
		if ( 'publish' !== get_post_status( $post_id ) || ! Rule::is_recurring( $post_id ) ) {
			return new WP_REST_Response( array(), 404 );
		}

		$rows = array_map(
			static function ( object $row ) use ( $post_id ): array {
				return array(
					'recurrence_id' => $row->recurrence_id,
					'start'         => mysql_to_rfc3339( $row->datetime_start ),
					'end'           => mysql_to_rfc3339( $row->datetime_end ),
					'timezone'      => $row->timezone,
					'status'        => $row->status,
					'url'           => Context::occurrence_url( $post_id, $row->recurrence_id ),
				);
			},
			Occurrences::all( $post_id, 'upcoming' )
		);

		return new WP_REST_Response( $rows );
	}

	/**
	 * Updates the current user's RSVP for an occurrence.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response REST response.
	 */
	public static function update_rsvp( WP_REST_Request $request ): WP_REST_Response {
		$post_id    = (int) $request->get_param( 'post_id' );
		$occurrence = self::set_context( $post_id, (string) $request['recurrence_id'] );
		$status     = sanitize_key( (string) $request->get_param( 'status' ) );

		if ( 'publish' !== get_post_status( $post_id ) || ! $occurrence || 'cancelled' === $occurrence->status || ! in_array( $status, array( 'attending', 'not_attending' ), true ) ) {
			return new WP_REST_Response( array( 'success' => false ), 400 );
		}

		$event = new Event( $post_id );
		if ( $event->has_event_past() || ! $event->rsvp ) {
			return new WP_REST_Response( array( 'success' => false ), 400 );
		}

		$user_record = $event->rsvp->save( get_current_user_id(), $status );
		Cache::delete( $post_id );
		$responses = $event->rsvp->responses();

		return new WP_REST_Response(
			array(
				'event_id'    => $post_id,
				'success'     => in_array( $user_record['status'], Status::values(), true ),
				'status'      => $user_record['status'],
				'guests'      => $user_record['guests'],
				'anonymous'   => $user_record['anonymous'],
				'responses'   => $responses,
				'online_link' => $event->maybe_get_online_event_link(),
			)
		);
	}

	/**
	 * Returns occurrence-scoped RSVP responses.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response REST response.
	 */
	public static function responses( WP_REST_Request $request ): WP_REST_Response {
		$post_id    = (int) $request->get_param( 'post_id' );
		$occurrence = self::set_context( $post_id, (string) $request['recurrence_id'] );

		if ( 'publish' !== get_post_status( $post_id ) || ! $occurrence ) {
			return new WP_REST_Response( array( 'success' => false ), 404 );
		}

		Cache::delete( $post_id );
		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => ( new Rsvp( $post_id ) )->responses(),
			)
		);
	}

	/**
	 * Cancels or restores an occurrence.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response REST response.
	 */
	public static function status( WP_REST_Request $request ): WP_REST_Response {
		$post_id       = (int) $request['post_id'];
		$recurrence_id = (string) $request['recurrence_id'];
		$status        = sanitize_key( (string) $request->get_param( 'status' ) );
		$occurrence    = Occurrences::get( $post_id, $recurrence_id );

		if ( ! $occurrence || $occurrence->datetime_start_gmt <= current_time( 'mysql', true ) ) {
			return new WP_REST_Response( array( 'success' => false ), 400 );
		}

		return new WP_REST_Response( array( 'success' => Occurrences::set_status( $post_id, $recurrence_id, $status ) ) );
	}

	/**
	 * Ends a series at a selected occurrence.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response REST response.
	 */
	public static function end_series( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'success' => Occurrences::end_after( (int) $request['post_id'], (string) $request['recurrence_id'] ),
			)
		);
	}

	/**
	 * Whether the caller may read an event's RSVP data.
	 *
	 * Defers to GatherPress so these routes stay in step with the visibility
	 * its own RSVP routes apply.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool Whether the read is allowed.
	 */
	public static function can_read( WP_REST_Request $request ): bool {
		return Event::can_read_rsvps( (int) $request->get_param( 'post_id' ) );
	}

	/**
	 * Whether the caller may read a series' projected occurrences.
	 *
	 * The schedule follows the series post itself rather than its RSVP data,
	 * so it stays readable on a site that has RSVPs switched off.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool Whether the read is allowed.
	 */
	public static function can_read_occurrences( WP_REST_Request $request ): bool {
		$post = get_post( (int) $request->get_param( 'post_id' ) );

		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		if ( current_user_can( 'edit_post', $post->ID ) ) {
			return true;
		}

		return 'publish' === $post->post_status
			? ! post_password_required( $post )
			: current_user_can( 'read_post', $post->ID );
	}

	/**
	 * Whether the request comes from this site rather than another origin.
	 *
	 * Browsers send an `Origin` header with every cross-origin request, so a
	 * request without one, or with the exact origin of the site's home, site
	 * or admin URL, is our own. Core's CORS allow-list is not used: it drops
	 * the port and can be widened by filters.
	 *
	 * Mirrors `Utility::is_same_origin_request()` upstream. Drop this in
	 * favour of that once the GatherPress release carrying it is pinned.
	 *
	 * @return bool Whether the request has no origin or this site's own.
	 */
	public static function is_same_origin_request(): bool {
		$origin = get_http_origin();

		if ( '' === $origin ) {
			return true;
		}

		$origin       = self::url_origin( $origin );
		$site_origins = array_map(
			array( self::class, 'url_origin' ),
			array( home_url(), site_url(), admin_url() )
		);

		return '' !== $origin && in_array( $origin, $site_origins, true );
	}

	/**
	 * The normalized origin of a URL.
	 *
	 * Lowercases the scheme and host and keeps the port only when it is not
	 * the scheme's default, so equal origins compare equal as strings.
	 *
	 * @param string $url URL or origin.
	 * @return string Origin, or an empty string when the URL has no scheme or host.
	 */
	private static function url_origin( string $url ): string {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme        = strtolower( $parts['scheme'] );
		$default_ports = array(
			'http'  => 80,
			'https' => 443,
		);
		$port          = isset( $parts['port'] ) && ( $default_ports[ $scheme ] ?? null ) !== $parts['port']
			? ':' . $parts['port']
			: '';

		return $scheme . '://' . strtolower( $parts['host'] ) . $port;
	}

	/**
	 * Validates and activates REST occurrence context.
	 *
	 * @param int    $post_id       Series post ID.
	 * @param string $recurrence_id Recurrence identifier.
	 * @return object|null Occurrence row.
	 */
	private static function set_context( int $post_id, string $recurrence_id ): ?object {
		$occurrence = Occurrences::get( $post_id, $recurrence_id );
		if ( $occurrence ) {
			Context::set( $occurrence );
		}

		return $occurrence;
	}
}
