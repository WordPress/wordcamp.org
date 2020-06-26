<?php
/*
 * Customizations to the v1 JSON REST API.
 *
 * WARNING: It's very important to make sure that only data intended to be public is disclosed by the API. This
 * is particularly important when it comes to areas where we're customizing the output, like post meta.
 *
 * Before making any changes to this plugin or updating the JSON API to a new version, make sure you run the
 * automated tests -- wp wc-rest verify-data-is-scrubbed -- in your sandbox, and then again on production
 * after you deploy any updates.
 *
 * You may also need to add new tests when making changes to this or other plugins, and/or update existing tests.
 */

add_filter( 'json_endpoints',              'wcorg_json_whitelist_endpoints',             999    );
add_filter( 'json_prepare_post',           'wcorg_json_expose_whitelisted_meta_data',    997, 3 );
add_filter( 'json_prepare_post',           'wcorg_json_expose_additional_post_data',     998, 3 ); // after `wcorg_json_expose_whitelisted_meta_data()`, because anything added before that method gets wiped out.
add_filter( 'json_prepare_post',           'wcorg_json_embed_related_posts',             999, 3 ); // after `wcorg_json_expose_additional_post_data()`.
add_action( 'wp_json_server_before_serve', 'wcorg_json_avoid_nested_callback_conflicts', 11     ); // after the default endpoints are added in `json_api_default_filters()`.

add_filter( 'json_prepare_post',        'deprecate_v1_endpoints' );
add_filter( 'json_prepare_page',        'deprecate_v1_endpoints' );
add_filter( 'json_prepare_attachment',  'deprecate_v1_endpoints' );
add_filter( 'json_prepare_revision',    'deprecate_v1_endpoints' );
add_filter( 'json_prepare_wcb_speaker', 'deprecate_v1_endpoints' );
add_filter( 'json_prepare_wcb_session', 'deprecate_v1_endpoints' );
add_filter( 'json_prepare_wcb_sponsor', 'deprecate_v1_endpoints' );
add_filter( 'json_prepare_mes',         'deprecate_v1_endpoints' );
add_filter( 'json_prepare_taxonomy',    'deprecate_v1_endpoints' );
add_filter( 'json_prepare_term',        'deprecate_v1_endpoints' );
add_filter( 'json_prepare_user',        'deprecate_v1_endpoints' );

add_filter( 'json_prepare_meta', 'wcorg_json_filter_session_time_value' );

// Allow some routes to skip the JSON REST API v1 plugin.
add_action( 'parse_request', 'wcorg_json_v2_compat', 9 );

// Allow users to read new post statuses.
add_filter( 'json_check_post_read_permission', 'wcorg_json_check_post_read_permission', 10, 2 );

// Query the public post statuses when querying WordCamps via the JSON API.
add_action( 'pre_get_posts', 'wcorg_json_pre_get_posts' );

/**
 * Unhook any endpoints that aren't whitelisted
 *
 * @param array $endpoints
 *
 * @return array
 */
function wcorg_json_whitelist_endpoints( $endpoints ) {
	global $wp_json_server, $wp_json_posts, $wp_json_taxonomies;

	$whitelisted_endpoints = array(
		'/'                                        => array( array( $wp_json_server, 'get_index' ), WP_JSON_Server::READABLE ),

		// Posts.
		'/posts'                                   => array(
			array( array( $wp_json_posts, 'get_posts' ), WP_JSON_Server::READABLE ),
		),
		'/posts/(?P<id>\d+)'                       => array(
			array( array( $wp_json_posts, 'get_post' ), WP_JSON_Server::READABLE ),
		),
		'/posts/types'                             => array(
			array( array( $wp_json_posts, 'get_post_types' ), WP_JSON_Server::READABLE ),
		),
		'/posts/types/(?P<type>\w+)'               => array(
			array( array( $wp_json_posts, 'get_post_type' ), WP_JSON_Server::READABLE ),
		),

		// Taxonomies.
		'/posts/types/(?P<type>[\w-]+)/taxonomies' => array(
			array( array( $wp_json_taxonomies, 'get_taxonomies_for_type' ), WP_JSON_Server::READABLE | WP_JSON_Server::HIDDEN_ENDPOINT ),
		),
		'/posts/types/(?P<type>[\w-]+)/taxonomies/(?P<taxonomy>[\w-]+)' => array(
			array( array( $wp_json_taxonomies, 'get_taxonomy' ), WP_JSON_Server::READABLE | WP_JSON_Server::HIDDEN_ENDPOINT ),
		),
		'/posts/types/(?P<type>[\w-]+)/taxonomies/(?P<taxonomy>[\w-]+)/terms' => array(
			array( array( $wp_json_taxonomies, 'get_terms' ), WP_JSON_Server::READABLE | WP_JSON_Server::HIDDEN_ENDPOINT ),
		),
		'/posts/types/(?P<type>[\w-]+)/taxonomies/(?P<taxonomy>[\w-]+)/terms/(?P<term>[\w-]+)' => array(
			array( array( $wp_json_taxonomies, 'get_term' ), WP_JSON_Server::READABLE | WP_JSON_Server::HIDDEN_ENDPOINT ),
		),
		'/taxonomies'                              => array(
			array( array( $wp_json_taxonomies, 'get_taxonomies' ), WP_JSON_Server::READABLE ),
		),
		'/taxonomies/(?P<taxonomy>[\w-]+)'         => array(
			array( array( $wp_json_taxonomies, 'get_taxonomy_object' ), WP_JSON_Server::READABLE ),
		),
		'/taxonomies/(?P<taxonomy>[\w-]+)/terms'   => array(
			array( array( $wp_json_taxonomies, 'get_taxonomy_terms' ), WP_JSON_Server::READABLE ),
		),
		'/taxonomies/(?P<taxonomy>[\w-]+)/terms/(?P<term>[\w-]+)' => array(
			array( array( $wp_json_taxonomies, 'get_taxonomy_term' ), WP_JSON_Server::READABLE ),
		),
	);

	return $whitelisted_endpoints;
}

/**
 * Expose a whitelisted set of meta data to unauthenticated JSON API requests
 *
 * Note: Some additional fields are added in `wcorg_json_expose_additional_post_data()`
 *
 * @param array  $prepared_post
 * @param array  $raw_post
 * @param string $context
 *
 * @return array
 */
function wcorg_json_expose_whitelisted_meta_data( $prepared_post, $raw_post, $context ) {
	$whitelisted_post_meta = array(
		'wordcamp'    => array(
			'Start Date (YYYY-mm-dd)', 'End Date (YYYY-mm-dd)', 'Location', 'URL', 'Twitter', 'WordCamp Hashtag',
			'Number of Anticipated Attendees', 'Organizer Name', 'WordPress.org Username', 'Venue Name',
			'Physical Address', 'Maximum Capacity', 'Available Rooms', 'Website URL', 'Exhibition Space Available',
			// todo add Multi-Event Sponsor Region, but convert from ID to name so it will be meaningful without having to do extra lookup? Or convert to whitelisted object?
		),

		'wcb_session' => array( '_wcpt_session_time', '_wcpt_session_type' ),

		'wcb_sponsor' => array( '_wcpt_sponsor_website' ),
	);
	$targeted_post_types = array_keys( $whitelisted_post_meta );

	/*
	 * Wipe out any existing meta being exposed.
	 *
	 * It should already be empty unless $context == 'edit', but that may not be true in the future.
	 */
	$prepared_post['post_meta'] = array();

	if ( is_wp_error( $prepared_post ) || ! in_array( $prepared_post['type'], $targeted_post_types ) ) {
		return $prepared_post;
	}

	$post_meta_endpoint = new WP_JSON_Meta_Posts( $GLOBALS['wp_json_server'] );
	add_filter( 'json_check_post_edit_permission',    '__return_true'  ); // The API only exposes post meta to authenticated users, but we want to expose whitelisted items to everyone.
	add_filter( 'is_protected_meta',                  '__return_false' ); // We want to include whitelisted items, even if they're marked as protected.
	$post_meta = $post_meta_endpoint->get_all_meta( $raw_post['ID'] );
	remove_filter( 'is_protected_meta',               '__return_false' );
	remove_filter( 'json_check_post_edit_permission', '__return_true'  );

	foreach ( $post_meta as $meta_item ) {
		if ( in_array( $meta_item['key'], $whitelisted_post_meta[ $prepared_post['type'] ] ) ) {
			$prepared_post['post_meta'][] = $meta_item;
		}
	}

	return $prepared_post;
}

/**
 * Reset the session time value to the "faux-UTC" value, for backwards-compatibility.
 *
 * See https://github.com/WordPress/wordcamp.org/issues/226, https://github.com/WordPress/wordcamp.org/pull/348
 *
 * @param array $meta_array Meta values for the current object.
 * @return array Updated meta values.
 */
function wcorg_json_filter_session_time_value( $meta_array ) {
	foreach ( $meta_array as $i => $meta ) {
		if ( '_wcpt_session_time' === $meta['key'] ) {
			// Create a DateTime object using the session date, but fake the timezone to UTC (`Z`).
			$datetime = date_create( wp_date( 'Y-m-d\TH:i:s\Z', $meta['value'] ) );
			$meta_array[ $i ]['value'] = $datetime->getTimestamp();
		}
	}
	return $meta_array;
}

/**
 * Expose additional data on post responses.
 *
 * Some fields can't be exposed directly for privacy or other reasons, but we can still provide interesting data
 * that is derived from those fields. For example, we can't expose a Speaker's e-mail address, but can we go ahead
 * and derive their Gravatar URL and expose that instead.
 *
 * @param array  $prepared_post
 * @param array  $raw_post
 * @param string $context
 *
 * @return array
 */
function wcorg_json_expose_additional_post_data( $prepared_post, $raw_post, $context ) {
	if ( is_wp_error( $prepared_post ) || empty( $prepared_post['type'] ) ) {
		return $prepared_post;
	}

	switch ( $prepared_post['type'] ) {
		case 'wcb_speaker':
			$prepared_post['avatar'] = wcorg_json_get_speaker_avatar( $prepared_post['ID'] );
			break;
	}

	return $prepared_post;
}

/**
 * Get the avatar URL for the given speaker
 *
 * @param int $speaker_post_id
 *
 * @return string
 */
function wcorg_json_get_speaker_avatar( $speaker_post_id ) {
	$avatar          = '';
	$speaker_email   = get_post_meta( $speaker_post_id, '_wcb_speaker_email', true );
	$speaker_user_id = get_post_meta( $speaker_post_id, '_wcpt_user_id', true );

	if ( $speaker_email ) {
		$avatar = json_get_avatar_url( $speaker_email );
	} elseif ( $speaker_user_id ) {
		$avatar = json_get_avatar_url( $speaker_user_id );
	}

	return $avatar;
}

/**
 * Embed related posts within a post
 *
 * Some post data wouldn't be particularly useful or meaningful on its own, like the `_wcpt_speaker_id` attached
 * to a `wcb_session` post. Instead of providing that raw to the API, we can expand it into a full `wcb_speaker`
 * object, so that clients don't have to make additional requests to fetch the data they actually want.
 *
 * @param array  $prepared_post
 * @param array  $raw_post
 * @param string $context
 *
 * @return array
 */
function wcorg_json_embed_related_posts( $prepared_post, $raw_post, $context ) {
	/** @var $wp_json_posts WP_JSON_Posts */
	global $wp_json_posts;

	if ( is_wp_error( $prepared_post ) || empty( $prepared_post['type'] ) ) {
		return $prepared_post;
	}

	// Unhook this callback before making any other WP_JSON_Posts::get_posts() calls, to avoid infinite recursion.
	remove_filter( 'json_prepare_post', 'wcorg_json_embed_related_posts', 999 );

	switch ( $prepared_post['type'] ) {
		case 'wcb_speaker':
			$prepared_post['sessions'] = wcorg_json_get_speaker_sessions( $prepared_post['ID'] );
			break;

		case 'wcb_session':
			$speaker_id               = get_post_meta( $prepared_post['ID'], '_wcpt_speaker_id', true );
			$speaker                  = $wp_json_posts->get_post( $speaker_id );
			$prepared_post['speaker'] = is_a( $speaker, 'WP_JSON_Response' ) ? $speaker : null;
			break;
	}

	add_filter( 'json_prepare_post', 'wcorg_json_embed_related_posts', 999, 3 );

	return $prepared_post;
}

/**
 * Get the sessions for a given speaker.
 *
 * @param int $speaker_post_id
 *
 * @return array
 */
function wcorg_json_get_speaker_sessions( $speaker_post_id ) {
	/** @var $wp_json_posts WP_JSON_Posts */
	global $wp_json_posts;

	$sessions      = array();
	$transient_key = "wcorg_json_speaker_{$speaker_post_id}_session_ids";

	/*
	 * Get the IDs of the related posts from WP_Query, because WP_JSON_Posts doesn't support meta queries yet.
	 *
	 * This can be removed when https://github.com/WP-API/WP-API/issues/479 is resolved.
	 */
	$session_ids = get_transient( $transient_key );
	if ( ! $session_ids ) {
		$session_ids = get_posts( array(
			'posts_per_page' => -1,
			'post_type'      => 'wcb_session',
			'meta_key'       => '_wcpt_speaker_id',
			'meta_value'     => $speaker_post_id,
		) );

		$session_ids = wp_list_pluck( $session_ids, 'ID' );
		set_transient( $transient_key, $session_ids, 2 * HOUR_IN_SECONDS );
	}

	if ( $session_ids ) {
		add_filter( 'json_query_vars', 'wcorg_json_allow_post_in' );
		$sessions = $wp_json_posts->get_posts(
			array(
				'posts_per_page' => -1,
				'post__in'       => $session_ids,
				'orderby'        => 'title',
				'order'          => 'asc',
			),
			'view',
			'wcb_session'
		);
		remove_filter( 'json_query_vars', 'wcorg_json_allow_post_in' );
	}

	return $sessions;
}

/**
 * Allow the `post_in` query var in `WP_JSON_Posts::get_post()` queries, even if the user is unauthenticated.
 *
 * @param array $query_vars
 *
 * @return array
 */
function wcorg_json_allow_post_in( $query_vars ) {
	$query_vars[] = 'post__in';

	return $query_vars;
}

/**
 * Avoid nested callback conflicts by de-registering WP_JSON_Media::add_thumbnail_data().
 *
 * There's a Core bug (#17817) where nested callbacks conflict with each other. If a post has a featured image,
 * then `json_prepare_post` will be called recursively, and `wcorg_expose_whitelisted_json_api_meta_data()` will
 * be short-circuited.
 *
 * Hopefully the fix for #17817 will land in 4.3, but until then we can avoid the issue by de-registering
 * `add_thumbnail_data()`. This obviously has the downside of removing featured image data from the API responses,
 * but we don't have a compelling use case for that data right now, and I don't see a better alternative. Ensuring
 * that only whitelisted data is exposed is a privacy issue, and therefore trumps pretty much everything else.
 *
 * This is fragile, and will probably break when WP-API's `develop` branch is merged into `master`, so -- like
 * everything else in this plugin -- it should be re-tested and updated when new versions of the API are
 * released.
 *
 * See https://github.com/WP-API/WP-API/issues/1090
 * See https://core.trac.wordpress.org/ticket/17817
 */
function wcorg_json_avoid_nested_callback_conflicts() {
	/** @var $wp_json_media WP_JSON_Media */
	global $wp_json_media;

	remove_filter( 'json_prepare_post', array( $wp_json_media, 'add_thumbnail_data' ), 10 );
}

/**
 * JSON REST API v1 and Core/v2 compatibility.
 *
 * All v2 routes are routed to the Core handler, instead of the json-rest-api plugin.
 *
 * @param WP $request
 */
function wcorg_json_v2_compat( $request ) {
	$rest_prefix = rest_get_url_prefix();
	$site_path   = get_blog_details( null, false )->path;
	$route       = $request->query_vars['json_route'] ?? false;

	// Skip non-API requests.
	if ( ! $route ) {
		return;
	}

	$is_route_v2 = false;

	// The root route should go to v2 because v1 is deprecated.
	if ( '/' === $route ) {
		$is_route_v2 = true;
	}

	if ( ! $is_route_v2 ) {
		foreach ( rest_get_server()->get_namespaces() as $namespace ) {
			if ( 0 === strpos( $_SERVER['REQUEST_URI'], $site_path . "$rest_prefix/$namespace" ) ) {
				$is_route_v2 = true;
				break;
			}
		}
	}

	// Skip API v1 requests.
	if ( ! $is_route_v2 ) {
		return;
	}

	// Route v2 requests to Core handler.
	if ( isset( $request->query_vars['json_route'] ) ) {
		$request->query_vars['rest_route'] = $request->query_vars['json_route'];
		unset( $request->query_vars['json_route'] );
		$request->matched_query = preg_replace( '#^json_route=(.+)$#', 'rest_route=$1', $request->matched_query );
	}

	return;
}

/**
 * Allow users to read new post statuses.
 */
function wcorg_json_check_post_read_permission( $permission, $post ) {
	if ( $permission || ! defined( 'WCPT_POST_TYPE_ID' ) ) {
		return $permission;
	}

	if ( WCPT_POST_TYPE_ID != $post['post_type'] ) {
		return $permission;
	}

	return in_array( $post['post_status'], WordCamp_Loader::get_public_post_statuses() );
}

/**
 * Query the public post statuses when querying WordCamps via the JSON API.
 */
function wcorg_json_pre_get_posts( $query ) {
	if ( ! defined( 'JSON_REQUEST' ) || ! JSON_REQUEST ) {
		return;
	}

	$post_types    = $query->get( 'post_type' );
	$post_statuses = $query->get( 'post_status' );

	if ( 'wordcamp' == $post_types || in_array( 'wordcamp', (array) $post_types ) ) {
		if ( empty( $post_statuses ) ) {
			$query->set( 'post_status', WordCamp_Loader::get_public_post_statuses() );
		}
	}
}

/**
 * Add a deprecation notice to objects in the response
 *
 * @param array $response_data
 *
 * @return array
 */
function deprecate_v1_endpoints( $response_data ) {
	$response_data = array_merge(
		array( '_WARNING_DEPRECATED' => 'All v1 endpoints have been deprecated, and will be deactivated after 2018-04-01. Please switch to the v2 endpoints by then, in order to ensure that your application continues to function. If you have any questions, join the #meta-wordcamp channel on Slack or email ' . EMAIL_CENTRAL_SUPPORT ),
		$response_data
	);

	return $response_data;
}
