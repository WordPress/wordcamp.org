<?php

namespace WordPressdotorg\MU_Plugins\REST_API;

/**
 * Actions and filters.
 */
add_action( 'rest_api_init', __NAMESPACE__ . '\initialize_rest_endpoints' );
add_action( 'rest_api_init', __NAMESPACE__ . '\initialize_rest_contexts' );
add_filter( 'rest_user_query', __NAMESPACE__ . '\modify_user_query_parameters', 10, 2 );

/**
 * Turn on API endpoints.
 *
 * @return void
 */
function initialize_rest_endpoints() {
	require_once __DIR__ . '/endpoints/class-wporg-rest-users-controller.php';
	require_once __DIR__ . '/endpoints/class-wporg-site-quality-controller.php';

	$users_controller = new Users_Controller();
	$users_controller->register_routes();
}

/**
 * Enable extra contexts.
 */
function initialize_rest_contexts() {
	require_once __DIR__ . '/extras/class-wporg-export-context.php';

	$export_context = new Export_Context();
	$export_context->init();
}

/**
 * Tweak the user query to allow for getting users who aren't blog members.
 *
 * @param array            $prepared_args
 * @param \WP_REST_Request $request
 *
 * @return array
 */
function modify_user_query_parameters( $prepared_args, $request ) {
	// Only for this specific endpoint.
	if ( '/wporg/v1' !== substr( $request->get_route(), 0, 9 ) ) {
		return $prepared_args;
	}

	$prepared_args['blog_id'] = 0; // Avoid check for blog membership.
	unset( $prepared_args['has_published_posts'] ); // Avoid another check for blog membership.

	return $prepared_args;
}
