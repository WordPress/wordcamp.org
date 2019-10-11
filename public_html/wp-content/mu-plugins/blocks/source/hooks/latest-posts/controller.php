<?php
namespace WordCamp\Blocks\Hooks\Latest_Posts;

defined( 'WPINC' ) || die();

/**
 * Register block types and enqueue scripts.
 *
 * @return void
 */
function init() {
	$deps_path    = \WordCamp\Blocks\PLUGIN_DIR . 'build/live-posts.min.deps.json';
	$dependencies = file_exists( $deps_path ) ? json_decode( file_get_contents( $deps_path ) ) : array();

	wp_register_script(
		'wordcamp-live-posts',
		\WordCamp\Blocks\PLUGIN_URL . 'build/live-posts.min.js',
		$dependencies,
		filemtime( \WordCamp\Blocks\PLUGIN_DIR . 'build/live-posts.min.js' ),
		true
	);

	/** This filter is documented in mu-plugins/blocks/blocks.php */
	$data = apply_filters( 'wordcamp_blocks_script_data', [] );

	wp_add_inline_script(
		'wordcamp-live-posts',
		sprintf(
			'var WordCampBlocks = JSON.parse( decodeURIComponent( \'%s\' ) );',
			rawurlencode( wp_json_encode( $data ) )
		),
		'before'
	);

	wp_set_script_translations( 'wordcamp-live-posts', 'wordcamporg' );

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
		$block_type->script = 'wordcamp-live-posts';

		register_block_type( $block_type );
	}
}
add_action( 'init', __NAMESPACE__ . '\init', 21 ); // 21 to be after block registration in Gutenberg plugin.

/**
 * Allow all users to read the "Latest Posts" renderer endpoint.
 *
 * @param WP_HTTP_Response|WP_Error $response Result to send to the client. Usually a WP_REST_Response or WP_Error.
 * @param array                     $handler  Route handler used for the request.
 * @param WP_REST_Request           $request  Request used to generate the response.
 * @return WP_REST_Response Response returned by the callback.
 */
function safelist_block_renderer( $response, $handler, $request ) {
	// Only apply to the latest posts block.
	if ( '/wp/v2/block-renderer/core/latest-posts' === $request->get_route() ) {
		return call_user_func( $handler['callback'], $request );
	}
	return $response;
}
add_filter( 'rest_request_after_callbacks', __NAMESPACE__ . '\safelist_block_renderer', 10, 3 );

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

	$enabled = isset( $block['attrs']['liveUpdateEnabled'] ) && $block['attrs']['liveUpdateEnabled'];
	// Order by date, desc is the default, so these properties are not set.
	$order_date_desc = ! isset( $block['attrs']['orderBy'] ) && ! isset( $block['attrs']['order'] );
	if ( $enabled && $order_date_desc ) {
		$block_content = str_replace(
			'wp-block-latest-posts ',
			'wp-block-latest-posts has-live-update ',
			$block_content
		);

		$block_content = str_replace(
			'ul class=',
			sprintf( 'ul data-attributes="%s" class=', rawurlencode( wp_json_encode( $block['attrs'] ) ) ),
			$block_content
		);
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
	$data['latest-posts'] = [];

	return $data;
}
add_filter( 'wordcamp_blocks_script_data', __NAMESPACE__ . '\add_script_data' );
