<?php
/**
 * REST API controller for group members.
 *
 * Extends WP_REST_Users_Controller to provide a site-scoped member list
 * with role labels, join/leave endpoints, and profile URLs.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\Members;

defined( 'WPINC' ) || die();

/**
 * Members REST controller.
 *
 * Routes:
 *   GET    /wporg-groups/v1/members       — list group members
 *   GET    /wporg-groups/v1/members/{id}  — single member
 *   POST   /wporg-groups/v1/members/join  — join group
 *   DELETE /wporg-groups/v1/members/leave — leave group
 */
class Members_Controller extends \WP_REST_Users_Controller {

	/**
	 * Maximum public collection page size.
	 *
	 * @var int
	 */
	const MAX_PER_PAGE = 250;

	/**
	 * Role label mapping.
	 *
	 * @var array<string, string>
	 */
	const ROLE_LABELS = array(
		'administrator' => 'Organiser',
		'editor'        => 'Organiser',
		'author'        => 'Event Organiser',
		'contributor'   => 'Member',
		'subscriber'    => 'Member',
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = 'wporg-groups/v1';
		$this->rest_base = 'members';
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		// Collection: GET /members.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'per_page' => array(
							'default'           => 100,
							'sanitize_callback' => 'absint',
							'validate_callback' => array( $this, 'validate_per_page' ),
						),
						'page'     => array(
							'default'           => 1,
							'sanitize_callback' => 'absint',
							'validate_callback' => array( $this, 'validate_page' ),
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		// Single: GET /members/{id}.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'id' => array(
							'validate_callback' => function ( $param ) {
								return is_numeric( $param );
							},
						),
					),
				),
			)
		);

		// Join: POST /members/join.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/join',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'join_group' ),
					'permission_callback' => 'is_user_logged_in',
				),
			)
		);

		// Leave: DELETE /members/leave.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/leave',
			array(
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'leave_group' ),
					'permission_callback' => array( $this, 'leave_permissions_check' ),
				),
			)
		);
	}

	/**
	 * Get group members.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ) {
		$per_page = min( self::MAX_PER_PAGE, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );

		$users = get_users(
			array(
				'blog_id' => get_current_blog_id(),
				'number'  => $per_page,
				'paged'   => $page,
				'orderby' => 'display_name',
				'order'   => 'ASC',
			)
		);

		// Sort: organisers first, then event organisers, then members.
		usort( $users, array( $this, 'sort_by_role' ) );

		$data = array();
		foreach ( $users as $user ) {
			$data[] = $this->prepare_member( $user );
		}

		$all_count = count_users();
		$total     = $all_count['total_users'] ?? count( $users );

		$response = rest_ensure_response( $data );
		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', (int) ceil( $total / $per_page ) );

		return $response;
	}

	/**
	 * Get a single member.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ) {
		$user_id = (int) $request->get_param( 'id' );
		$user    = get_userdata( $user_id );

		if ( ! $user || ! is_user_member_of_blog( $user_id ) ) {
			return new \WP_Error(
				'rest_user_not_found',
				__( 'User not found.', 'wporg-groups-frontend' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $this->prepare_member( $user ) );
	}

	/**
	 * Join the current group.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function join_group( $request ) {
		$user_id = get_current_user_id();
		$blog_id = get_current_blog_id();

		if ( is_user_member_of_blog( $user_id, $blog_id ) ) {
			return new \WP_Error(
				'already_member',
				__( 'You are already a member of this group.', 'wporg-groups-frontend' ),
				array( 'status' => 400 )
			);
		}

		$result = add_user_to_blog( $blog_id, $user_id, 'subscriber' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$total = count_users()['total_users'] ?? 0;

		return rest_ensure_response(
			array(
				'success'     => true,
				'memberCount' => $total,
			)
		);
	}

	/**
	 * Check permissions for leaving.
	 *
	 * @return true|\WP_Error
	 */
	public function leave_permissions_check() {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in.', 'wporg-groups-frontend' ),
				array( 'status' => 401 )
			);
		}

		if ( ! is_user_member_of_blog() ) {
			return new \WP_Error(
				'not_a_member',
				__( 'You are not a member of this group.', 'wporg-groups-frontend' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Leave the current group.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function leave_group( $request ) {
		$user_id = get_current_user_id();
		$blog_id = get_current_blog_id();
		$user    = get_userdata( $user_id );

		// Prevent organisers from leaving without demotion.
		if ( $user && array_intersect( $user->roles, array( 'administrator', 'editor' ) ) ) {
			return new \WP_Error(
				'cannot_leave',
				__( 'Organisers cannot leave the group. Ask another organiser to change your role first.', 'wporg-groups-frontend' ),
				array( 'status' => 403 )
			);
		}

		remove_user_from_blog( $user_id, $blog_id );

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Prepare a member for REST response.
	 *
	 * @param \WP_User $user User object.
	 * @return array
	 */
	private function prepare_member( \WP_User $user ): array {
		$roles      = $user->roles;
		$role       = reset( $roles ) ?: 'subscriber';
		$role_label = self::ROLE_LABELS[ $role ] ?? 'Member';

		return array(
			'id'        => $user->ID,
			'name'      => $user->display_name,
			'avatar'    => get_avatar_url( $user->ID, array( 'size' => 128 ) ),
			'profile'   => sprintf( 'https://profiles.wordpress.org/%s/', $user->user_nicename ),
			'bio'       => wp_trim_words( get_the_author_meta( 'description', $user->ID ), 20, "\u{2026}" ),
			'role'      => $role,
			'roleLabel' => $role_label,
		);
	}

	/**
	 * Sort users by role weight (organisers first).
	 *
	 * @param \WP_User $a First user.
	 * @param \WP_User $b Second user.
	 * @return int
	 */
	private function sort_by_role( \WP_User $a, \WP_User $b ): int {
		$weights = array(
			'administrator' => 0,
			'editor'        => 1,
			'author'        => 2,
			'contributor'   => 3,
			'subscriber'    => 4,
		);

		$role_a = reset( $a->roles ) ?: 'subscriber';
		$role_b = reset( $b->roles ) ?: 'subscriber';

		$weight_a = $weights[ $role_a ] ?? 5;
		$weight_b = $weights[ $role_b ] ?? 5;

		if ( $weight_a !== $weight_b ) {
			return $weight_a - $weight_b;
		}

		return strcasecmp( $a->display_name, $b->display_name );
	}

	/**
	 * Get the public schema for a member.
	 *
	 * @return array
	 */
	public function get_public_item_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'group-member',
			'type'       => 'object',
			'properties' => array(
				'id'         => array(
					'type' => 'integer',
				),
				'name'       => array(
					'type' => 'string',
				),
				'avatar'     => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'profile'    => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'bio'        => array(
					'type' => 'string',
				),
				'role'       => array(
					'type' => 'string',
				),
				'roleLabel'  => array(
					'type' => 'string',
				),
			),
		);
	}

	/**
	 * Validate the public collection page size.
	 *
	 * @param mixed $param Request parameter.
	 * @return bool
	 */
	public function validate_per_page( $param ): bool {
		$value = (int) $param;

		return $value >= 1 && $value <= self::MAX_PER_PAGE;
	}

	/**
	 * Validate the public collection page number.
	 *
	 * @param mixed $param Request parameter.
	 * @return bool
	 */
	public function validate_page( $param ): bool {
		return (int) $param >= 1;
	}
}
