<?php
/**
 * REST API controller for the group-ownership transfer workflow.
 *
 * A thin wrapper around `WordCamp\Groups\Ownership_Transfer\*` (defined in
 * the always-loaded `mu-plugins/groups/group-ownership-transfer.php`, which
 * this plugin depends on for the workflow's state machine and capability
 * checks) — no business logic is duplicated here, only routes, arg schemas,
 * permission callbacks, and response shaping.
 *
 * Routes:
 *   GET  /wporg-groups/v1/ownership-transfer          — current state
 *   POST /wporg-groups/v1/ownership-transfer/initiate — nominate a candidate
 *   POST /wporg-groups/v1/ownership-transfer/accept    — candidate accepts
 *   POST /wporg-groups/v1/ownership-transfer/decline   — candidate declines
 *   POST /wporg-groups/v1/ownership-transfer/cancel    — initiator cancels
 *
 * Approving/rejecting a transfer is deliberately NOT exposed here — every
 * existing network-admin action in this codebase is a wp-admin
 * `admin_post_*` form, not cross-site REST (a network admin isn't "on" the
 * group's site when deciding). That happens on the Network Admin
 * "Ownership Transfers" screen instead; see `group-ownership-transfer.php`.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\Ownership_Transfer;

use WordCamp\Groups\Ownership_Transfer as Transfer;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'WPINC' ) || die();

/**
 * Ownership-transfer REST controller.
 */
class Ownership_Transfer_Controller extends WP_REST_Controller {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = 'wporg-groups/v1';
		$this->rest_base = 'ownership-transfer';
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'view_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/initiate',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'initiate' ),
					'permission_callback' => array( $this, 'initiate_permissions_check' ),
					'args'                => array(
						'candidateId' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'fromUserId'  => array(
							'type'              => 'integer',
							'required'          => false,
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		foreach ( array( 'accept', 'decline', 'cancel' ) as $action ) {
			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/' . $action,
				array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this, $action ),
						'permission_callback' => array( $this, 'view_permissions_check' ),
					),
				)
			);
		}
	}

	/**
	 * Permission check shared by every route: must be logged in and either a
	 * member of this group or a super admin. Identity/role-specific rules (is
	 * this the candidate? the owner?) are enforced by the `Transfer\*` state
	 * functions themselves, the same split `Members_Controller::update_member_role()`
	 * uses between its permission callback and its handler.
	 *
	 * Super admins are exempt from the membership requirement so the
	 * abandoned-group path (`Transfer\current_user_can_initiate()`'s
	 * `is_super_admin()` branch) actually works for a network admin who
	 * isn't personally a member of the group in question — otherwise this
	 * check would 403 them before that override is ever consulted, on
	 * every route including the initial `GET` the front-end panel needs
	 * just to render the initiate form.
	 *
	 * @return true|WP_Error
	 */
	public function view_permissions_check() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in.', 'wporg-groups-frontend' ),
				array( 'status' => 401 )
			);
		}

		if ( ! is_super_admin() && ! is_user_member_of_blog() ) {
			return new WP_Error(
				'not_a_member',
				__( 'You are not a member of this group.', 'wporg-groups-frontend' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Permission check for initiating a transfer.
	 *
	 * @return true|WP_Error
	 */
	public function initiate_permissions_check() {
		$logged_in_check = $this->view_permissions_check();
		if ( is_wp_error( $logged_in_check ) ) {
			return $logged_in_check;
		}

		if ( ! Transfer\current_user_can_initiate( get_current_blog_id() ) ) {
			return new WP_Error(
				'rest_cannot_initiate_transfer',
				__( 'Sorry, you are not allowed to transfer ownership of this group.', 'wporg-groups-frontend' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * GET /ownership-transfer — current state.
	 *
	 * @return WP_REST_Response
	 */
	public function get_item( $request ) {
		return rest_ensure_response( $this->prepare_state() );
	}

	/**
	 * POST /ownership-transfer/initiate
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function initiate( $request ) {
		$site_id      = get_current_blog_id();
		$candidate_id = (int) $request->get_param( 'candidateId' );
		$from_user_id = (int) $request->get_param( 'fromUserId' );
		$current_user = wp_get_current_user();
		$is_owner     = in_array( 'administrator', $current_user->roles, true );

		if ( $is_owner ) {
			// Owners can only ever initiate a transfer of their own ownership;
			// a mismatched value here would let an owner name someone else's
			// admin role as the "from" user.
			if ( $from_user_id && $from_user_id !== $current_user->ID ) {
				return new WP_Error(
					'from_user_mismatch',
					__( 'You can only transfer ownership away from your own account.', 'wporg-groups-frontend' ),
					array( 'status' => 400 )
				);
			}
			$from_user_id = $current_user->ID;
		} elseif ( ! $from_user_id ) {
			return new WP_Error(
				'from_user_required',
				__( 'Please specify which current owner is being replaced.', 'wporg-groups-frontend' ),
				array( 'status' => 400 )
			);
		}

		$result = Transfer\initiate_transfer( $site_id, $from_user_id, $candidate_id, $current_user->ID );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $this->prepare_state() );
	}

	/**
	 * POST /ownership-transfer/accept
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function accept( $request ) {
		$result = Transfer\accept_transfer( get_current_blog_id(), get_current_user_id() );

		return is_wp_error( $result ) ? $result : rest_ensure_response( $this->prepare_state() );
	}

	/**
	 * POST /ownership-transfer/decline
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function decline( $request ) {
		$result = Transfer\decline_transfer( get_current_blog_id(), get_current_user_id() );

		return is_wp_error( $result ) ? $result : rest_ensure_response( $this->prepare_state() );
	}

	/**
	 * POST /ownership-transfer/cancel
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel( $request ) {
		$result = Transfer\cancel_transfer( get_current_blog_id(), get_current_user_id() );

		return is_wp_error( $result ) ? $result : rest_ensure_response( $this->prepare_state() );
	}

	/**
	 * Build the full state payload returned by every route in this controller.
	 *
	 * @return array
	 */
	private function prepare_state(): array {
		$site_id      = get_current_blog_id();
		$current_user = wp_get_current_user();
		$pending      = Transfer\get_pending_transfer( $site_id );

		// Whether this viewer is in the initiate-eligible audience (owner or
		// super admin) — independent of whether a transfer is *currently*
		// pending, since the client also uses this to decide whether to show
		// the "Cancel transfer" action on an in-flight one. The client gates
		// the initiate *form* itself separately, on `! pending`.
		$can_initiate = Transfer\current_user_can_initiate( $site_id );
		$can_accept   = $pending
			&& Transfer\STATUS_PENDING_ACCEPTANCE === $pending['status']
			&& (int) $pending['to_user_id'] === $current_user->ID;

		return array(
			'pending'            => $pending ? $this->prepare_pending( $pending ) : null,
			'history'            => array_map( array( $this, 'prepare_history_entry' ), Transfer\get_transfer_history( $site_id ) ),
			'currentOwners'      => array_map( array( $this, 'prepare_user' ), Transfer\get_site_administrators( $site_id ) ),
			'eligibleCandidates' => array_map( array( $this, 'prepare_user' ), Transfer\get_eligible_candidates( $site_id ) ),
			'canInitiate'        => (bool) $can_initiate,
			'canAccept'          => (bool) $can_accept,
			// Tells the client whether to show a "current owner being
			// replaced" selector: an owner initiating their own transfer
			// never needs it (the server already defaults `fromUserId` to
			// them), only a super admin acting on someone else's behalf does.
			'viewerIsOwner'      => in_array( 'administrator', $current_user->roles, true ),
		);
	}

	/**
	 * @param array $pending Pending-transfer record.
	 * @return array
	 */
	private function prepare_pending( array $pending ): array {
		return array(
			'fromUserId'     => (int) $pending['from_user_id'],
			'fromUserName'   => $this->display_name( (int) $pending['from_user_id'] ),
			'toUserId'       => (int) $pending['to_user_id'],
			'toUserName'     => $this->display_name( (int) $pending['to_user_id'] ),
			'status'         => $pending['status'],
			'initiatedBy'    => (int) $pending['initiated_by'],
			'initiatedByName' => $this->display_name( (int) $pending['initiated_by'] ),
			'initiatedAt'    => (int) $pending['initiated_at'],
			'acceptedAt'     => isset( $pending['accepted_at'] ) ? $pending['accepted_at'] : null,
		);
	}

	/**
	 * @param array $entry History entry.
	 * @return array
	 */
	private function prepare_history_entry( array $entry ): array {
		return array(
			'fromUserId'   => (int) $entry['from_user_id'],
			'fromUserName' => $this->display_name( (int) $entry['from_user_id'] ),
			'toUserId'     => (int) $entry['to_user_id'],
			'toUserName'   => $this->display_name( (int) $entry['to_user_id'] ),
			'status'       => $entry['final_status'] ?? '',
			'decidedAt'    => isset( $entry['decided_at'] ) ? (int) $entry['decided_at'] : null,
			'reason'       => $entry['reason'] ?? '',
		);
	}

	/**
	 * @param \WP_User $user User object.
	 * @return array{id: int, name: string}
	 */
	private function prepare_user( \WP_User $user ): array {
		return array(
			'id'   => $user->ID,
			'name' => $user->display_name,
		);
	}

	/**
	 * @param int $user_id User ID.
	 * @return string
	 */
	private function display_name( int $user_id ): string {
		$user = get_userdata( $user_id );

		return $user ? $user->display_name : __( '(deleted user)', 'wporg-groups-frontend' );
	}
}
