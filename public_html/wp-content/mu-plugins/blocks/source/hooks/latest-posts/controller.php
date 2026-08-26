<?php
namespace WordCamp\Blocks\Hooks\Latest_Posts;

defined( 'WPINC' ) || die();

const POLL_CACHE_SECONDS = 60;

/**
 * Check editor versions.
 *
 * To show live content, we need the `wp.serverSideRender` component available, which arrived in Gutenberg 5.9 or
 * WordPress 5.3.
 *
 * @return boolean
 */
function check_version_support() {
	return (
		version_compare( $GLOBALS['wp_version'], '5.3-alpha', '>' ) ||
		defined( 'GUTENBERG_VERSION' ) && version_compare( GUTENBERG_VERSION, '5.9.0', '>' )
	);
}

/**
 * Register block types and enqueue scripts.
 *
 * @return void
 */
function init() {
	if ( ! check_version_support() ) {
		return;
	}

	$path        = \WordCamp\Blocks\PLUGIN_DIR . 'build/live-posts.min.js';
	$deps_path   = \WordCamp\Blocks\PLUGIN_DIR . 'build/live-posts.min.asset.php';
	$script_info = file_exists( $deps_path )
		? require $deps_path
		: array(
			'dependencies' => array(),
			'version'      => filemtime( $path ),
		);

	wp_register_script(
		'wordcamp-live-posts',
		\WordCamp\Blocks\PLUGIN_URL . 'build/live-posts.min.js',
		$script_info['dependencies'],
		$script_info['version'],
		true
	);

	/** This filter is documented in mu-plugins/blocks/blocks.php */
	$data = apply_filters( 'wordcamp_blocks_script_data', array() );

	wp_add_inline_script(
		'wordcamp-live-posts',
		sprintf(
			'var WordCampBlocks = JSON.parse( decodeURIComponent( \'%s\' ) );',
			rawurlencode( wp_json_encode( $data ) )
		),
		'before'
	);

	$block_type = \WP_Block_Type_Registry::get_instance()->get_registered( 'core/latest-posts' );
	if ( $block_type ) {
		unregister_block_type( $block_type->name );
		$block_type->attributes = array_merge(
			$block_type->attributes,
			array(
				'liveUpdateEnabled' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			)
		);
		$block_type->script     = 'wordcamp-live-posts';

		register_block_type( $block_type );
	}
}
add_action( 'init', __NAMESPACE__ . '\init', 21 ); // 21 to be after block registration in Gutenberg plugin.

/**
 * Allow all users to read the "Latest Posts" renderer endpoint.
 *
 * The front-end block polls this route with no session, so core's `edit_posts`
 * check denies it and the callback has to run again here. This filter fires
 * whether or not that check passed, so each guard below keeps the re-run
 * pinned to the request the live block actually makes.
 *
 * @param WP_HTTP_Response|WP_Error $response Result to send to the client. Usually a WP_REST_Response or WP_Error.
 * @param array                     $handler  Route handler used for the request.
 * @param WP_REST_Request           $request  Request used to generate the response.
 * @return WP_HTTP_Response|WP_Error Response returned by the callback.
 */
function safelist_block_renderer( $response, $handler, $request ) {
	// Only apply to the latest posts block.
	if ( '/wp/v2/block-renderer/core/latest-posts' !== $request->get_route() ) {
		return $response;
	}

	// In `get_param()` the query string outranks the route path, so the path does
	// not say which block renders.
	if ( 'core/latest-posts' !== $request->get_param( 'name' ) ) {
		return $response;
	}

	// `post_id` is what core gates on `edit_post`; this block never sends one.
	if ( (int) $request->get_param( 'post_id' ) > 0 ) {
		return $response;
	}

	// `postsToShow` has no maximum in the block's schema. A live block shows a handful.
	$attributes = $request->get_param( 'attributes' );
	if ( isset( $attributes['postsToShow'] ) && (int) $attributes['postsToShow'] > 100 ) {
		return $response;
	}

	// Leave any other objection standing, including the Coming Soon lockdown.
	if ( ! is_wp_error( $response ) || 'block_cannot_read' !== $response->get_error_code() ) {
		return $response;
	}

	$response = call_user_func( $handler['callback'], $request );

	/*
	 * Everyone on a page polls the same URL, so let a cache answer most of them. A
	 * minute is short against the five the block waits between polls, and core only
	 * sends no-cache headers for a logged-in request, which this never is.
	 */
	if ( $response instanceof \WP_REST_Response ) {
		$response->header( 'Cache-Control', 'max-age=' . POLL_CACHE_SECONDS );
	}

	return $response;
}
add_filter( 'rest_request_after_callbacks', __NAMESPACE__ . '\safelist_block_renderer', 10, 3 );

/**
 * Drop the attributes the renderer route will not accept.
 *
 * The route validates `attributes` against the registered schema and rejects anything
 * outside it, while the render path still honours older keys such as `postLayout`.
 * Those only feed container classes, and the container on the page keeps its own, so
 * dropping them here costs nothing and keeps the request valid.
 *
 * This filters keys, not values. A registered key with a stale value can still be
 * refused, `categories` saved as a string being the one that used to be: it survives
 * because core migrates it on `render_block_data`, before this filter runs.
 *
 * @param array $attrs Saved block attributes.
 * @return array
 */
function pollable_attributes( $attrs ) {
	$block_type = \WP_Block_Type_Registry::get_instance()->get_registered( 'core/latest-posts' );

	if ( ! $block_type || empty( $block_type->attributes ) ) {
		return $attrs;
	}

	$registered = array_intersect_key( $attrs, $block_type->attributes );

	// A null serialises into the query string as an empty value, which the route
	// refuses for anything the schema does not type as a string.
	return array_filter(
		$registered,
		static function ( $value ) {
			return null !== $value;
		}
	);
}

/**
 * Filter the content of the latest posts block.
 *
 * @param string $block_content The block content about to be appended.
 * @param array  $block         The full block, including name and attributes.
 * @return string
 */
function render( $block_content, $block ) {
	if ( 'core/latest-posts' !== $block['blockName'] ) {
		return $block_content;
	}

	if ( ! check_version_support() ) {
		return $block_content;
	}

	$enabled = isset( $block['attrs']['liveUpdateEnabled'] ) && $block['attrs']['liveUpdateEnabled'];
	// Order by date, desc is the default, so these properties are not set.
	$order_date_desc = ! isset( $block['attrs']['orderBy'] ) && ! isset( $block['attrs']['order'] );
	if ( $enabled && $order_date_desc ) {
		/*
		 * Rewrite the container through the HTML API, not by string content:
		 * `str_replace()` also matched its needle inside attribute values (the
		 * featured image's `alt`, the post title's `aria-label`) and injected a `"`
		 * that closed them. `set_attribute()` escapes, and neither it nor
		 * `add_class()` can address anything but a real tag.
		 */
		$processor = new \WP_HTML_Tag_Processor( $block_content );

		if ( $processor->next_tag() && 'UL' === $processor->get_tag() ) {
			$processor->add_class( 'has-live-update' );
			$processor->add_class( 'is-loading' );
			$processor->set_attribute( 'data-attributes', rawurlencode( wp_json_encode( pollable_attributes( $block['attrs'] ) ) ) );

			$block_content = $processor->get_updated_html();
		}
	}

	return $block_content;
}
add_filter( 'render_block', __NAMESPACE__ . '\render', 10, 2 );

/**
 * Add data to be used by the JS scripts in the block editor.
 *
 * @param array $data
 *
 * @return array
 */
function add_script_data( array $data ) {
	if ( check_version_support() ) {
		$data['latest-posts'] = array(
			// Root-relative, so the request stays same-origin on a non-canonical host.
			'renderer' => wp_make_link_relative( rest_url( 'wp/v2/block-renderer/core/latest-posts' ) ),
		);
	}

	return $data;
}
add_filter( 'wordcamp_blocks_script_data', __NAMESPACE__ . '\add_script_data' );
