<?php

namespace WordPressdotorg\MU_Plugins\REST_API;

/**
 * Users_Controller
 */
class Users_Controller extends \WP_REST_Users_Controller {
	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();

		$this->namespace = 'wporg/v1';
	}

	/**
	 * Registers the routes for users.
	 *
	 * At this time, this endpoint is exclusively read-only. Other routes from the parent class have been omitted.
	 *
	 * @see register_rest_route()
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<slug>[\w-]+)',
			array(
				'args'   => array(
					'slug' => array(
						'description' => __( 'A unique alphanumeric identifier for the user.', 'wporg' ),
						'type'        => 'string',
					),
				),
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'context' => $this->get_context_param( array( 'default' => 'view' ) ),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		// Filter the post endpoints to alter the public URL for non-authenticated users.
		$post_types = get_post_types(
			array(
				'public'       => true,
				'show_in_rest' => true,
			)
		);

		foreach ( $post_types as $post_type ) {
			add_filter( "rest_prepare_{$post_type}", array( $this, 'alter_post_author_link' ) );
		}
	}

	/**
	 * Change the author link for posts to this endpoint.
	 * 
	 * @param \WP_REST_Response $response Response object for the request.
	 * 
	 * @return \WP_REST_Response Response object for the request.
	 */
	public function alter_post_author_link( $response ) {
		$data = $response->get_data();
		if ( empty( $data['author'] ) ) {
			return $response;
		}

		$user = get_user_by( 'id', $data['author'] );
		if ( ! $user ) {
			return $response;
		}

		// If the core users endpoint will work for this user, no need to change it.
		if ( is_multisite() && is_user_member_of_blog( $user->ID ) ) {
			return $response;
		}

		// Remove the existing author link
		$response->remove_link( 'author' );

		// Add a link to our endpoint instead
		$response->add_link(
			'author',
			rest_url( $this->namespace . '/' . $this->rest_base . '/' . urlencode( $user->user_nicename ) ),
			array(
				'embeddable' => true
			)
		);

		return $response;
	}

	/**
	 * Permissions check for getting all users.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 *
	 * @return true|\WP_Error True if the request has read access, otherwise WP_Error object.
	 */
	public function get_items_permissions_check( $request ) {
		$check = parent::get_items_permissions_check( $request );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		if ( empty( $request['slug'] ) ) {
			return new \WP_Error(
				'rest_invalid request',
				__( 'You must use the slug parameter for requests to this endpoint.', 'wporg' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Get the user, if the slug is valid.
	 *
	 * @param string $slug Supplied slug.
	 *
	 * @return \WP_User|\WP_Error True if slug is valid, WP_Error otherwise.
	 */
	protected function get_user_by_slug( $slug ) {
		$error = new \WP_Error(
			'rest_user_invalid_slug',
			__( 'Invalid user slug.', 'wporg' ),
			array( 'status' => 404 )
		);

		if ( mb_strlen( $slug ) > 50 || ! sanitize_title( $slug ) ) {
			return $error;
		}

		$user = get_user_by( 'slug', $slug );
		if ( empty( $user ) || ! $user->exists() ) {
			return $error;
		}

		return $user;
	}

	/**
	 * Checks if a given request has access to read a user.
	 *
	 * Modified from the parent method to use slug instead of id and remove capability checks that necessitate
	 * blog membership.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 *
	 * @return true|\WP_Error True if the request has read access for the item, otherwise WP_Error object.
	 */
	public function get_item_permissions_check( $request ) {
		$user = $this->get_user_by_slug( $request['slug'] );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		return true;
	}

	/**
	 * Retrieves a single user.
	 *
	 * Modified from the parent method to use slug instead of id.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 *
	 * @return \WP_REST_Response|\WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_item( $request ) {
		$user = $this->get_user_by_slug( $request['slug'] );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$user     = $this->prepare_item_for_response( $user, $request );
		$response = rest_ensure_response( $user );

		return $response;
	}

	/**
	 * Prepares links for the user request.
	 *
	 * @param \WP_User $user User object.
	 *
	 * @return array Links for the given user.
	 */
	protected function prepare_links( $user ) {
		$links = parent::prepare_links( $user );

		// Prepending / is to avoid replacing the 1 in wporg/v1 if the user ID is 1 :D
		$links['self']['href'] = str_replace( '/' . $user->ID, '/' . $user->user_nicename, $links['self']['href'] );

		$links['collection']['href'] = add_query_arg(
			'slug',
			$user->user_nicename,
			$links['collection']['href']
		);

		return $links;
	}

	/**
	 * Retrieves the query params for collections.
	 *
	 * @return array Collection parameters.
	 */
	public function get_collection_params() {
		$allowed_params = array(
			'context'  => '',
			'page'     => '',
			'per_page' => '',
			'order'    => '',
			'orderby'  => '',
			'slug'     => '',
		);

		$query_params = array_intersect_key( parent::get_collection_params(), $allowed_params );

		if ( isset( $query_params['orderby']['enum'] ) ) {
			$allowed_orderby = array( 'id', 'name', 'slug', 'include_slugs' );
			$query_params['orderby']['enum'] = array_intersect( $query_params['orderby']['enum'], $allowed_orderby );
		}

		return $query_params;
	}

	/**
	 * Retrieves the magical context param.
	 *
	 * Prevents usage of contexts (such as edit) that potentially reveal users' sensitive account information.
	 *
	 * @param array $args Optional. Additional arguments for context parameter. Default empty array.
	 *
	 * @return array Context parameter details.
	 */
	public function get_context_param( $args = array() ) {
		$context = parent::get_context_param( $args );
		$allowed_contexts = array( 'view', 'embed' );

		$context['enum'] = array_intersect( $context['enum'], $allowed_contexts );

		return $context;
	}
}
