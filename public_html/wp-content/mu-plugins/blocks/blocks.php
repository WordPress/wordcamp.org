<?php

namespace WordCamp\Blocks;

use WP_Post;

defined( 'WPINC' ) || die();

define( __NAMESPACE__ . '\PLUGIN_DIR', \plugin_dir_path( __FILE__ ) );
define( __NAMESPACE__ . '\PLUGIN_URL', \plugins_url( '/', __FILE__ ) );

/**
 * Load files.
 *
 * @return void
 */
function load_includes() {
	// Short-circuit: If there are no WordCamp post-types, these blocks don't have anything to display.
	if ( ! class_exists( 'WordCamp_Post_Types_Plugin' ) ) {
		return;
	}

	$includes_dir   = PLUGIN_DIR . 'includes/';
	$blocks_dir     = PLUGIN_DIR . 'source/blocks/';
	$components_dir = PLUGIN_DIR . 'source/components/';
	$hooks_dir      = PLUGIN_DIR . 'source/hooks/';
	$variations_dir = PLUGIN_DIR . 'source/variations/';

	// Utilities.
	require_once $includes_dir . 'definitions.php';
	require_once $includes_dir . 'content.php';

	// Components.
	require_once $components_dir . 'item/controller.php';
	require_once $components_dir . 'image/controller.php';
	require_once $components_dir . 'post-list/controller.php';

	// Blocks.
	require_once $blocks_dir . 'avatar/controller.php';
	require_once $blocks_dir . 'live-schedule/controller.php';
	require_once $blocks_dir . 'meta-link/controller.php';
	require_once $blocks_dir . 'organizers/controller.php';
	require_once $blocks_dir . 'schedule/controller.php';
	require_once $blocks_dir . 'session-date/controller.php';
	require_once $blocks_dir . 'session-speakers/controller.php';
	require_once $blocks_dir . 'sessions/controller.php';
	require_once $blocks_dir . 'speaker-sessions/controller.php';
	require_once $blocks_dir . 'speakers/controller.php';
	require_once $blocks_dir . 'sponsors/controller.php';

	// Hooks.
	require_once $hooks_dir . 'latest-posts/controller.php';

	// Variations.
	require_once $variations_dir . 'sessions-list/controller.php';
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\load_includes' );

/**
 * Add block categories for custom blocks.
 *
 * @param array $default_categories
 *
 * @return array
 */
function register_block_categories( $default_categories ) {
	$default_categories[] = array(
		'slug'  => 'wordcamp',
		'title' => __( 'WordCamp Blocks', 'wordcamporg' ),
	);

	return $default_categories;
}

add_filter( 'block_categories_all', __NAMESPACE__ . '\register_block_categories' );

/**
 * Register assets.
 *
 * The assets get enqueued automatically by the registered block types.
 *
 * @return void
 */
function register_assets() {
	$path        = PLUGIN_DIR . 'build/blocks.min.js';
	$deps_path   = PLUGIN_DIR . 'build/blocks.min.asset.php';
	$script_info = file_exists( $deps_path )
		? require $deps_path
		: array(
			'dependencies' => array(),
			'version'      => filemtime( $path ),
		);

	// Special case, because this isn't a wp package.
	$script_info['dependencies'][] = 'wp-sanitize';

	wp_register_style(
		'wordcamp-blocks',
		PLUGIN_URL . 'build/blocks.css',
		array(),
		filemtime( PLUGIN_DIR . 'build/blocks.css' )
	);

	wp_register_script(
		'wordcamp-blocks',
		PLUGIN_URL . 'build/blocks.min.js',
		$script_info['dependencies'],
		$script_info['version'],
		false
	);

	/**
	 * Filter: Add/modify data sent to WordCamp Blocks JS scripts.
	 *
	 * @param array $data Associative multidimensional array of data.
	 */
	$data = apply_filters( 'wordcamp_blocks_script_data', array() );

	wp_add_inline_script(
		'wordcamp-blocks',
		sprintf(
			'var WordCampBlocks = JSON.parse( decodeURIComponent( \'%s\' ) );',
			rawurlencode( wp_json_encode( $data ) )
		),
		'before'
	);

	wp_set_script_translations( 'wordcamp-blocks', 'wordcamporg' );
}

add_action( 'init', __NAMESPACE__ . '\register_assets', 9 );


/**
 * Determine whether a $post or a string contains a block type with set attributes.
 * Used to check for variations of generic blocks, e.g., session video meta-link block.
 *
 * @param string                  $block_name Full block type to look for.
 * @param array                   $attrs      Associative array of attribute-name => value.
 * @param int|string|WP_Post|null $post       Optional. Post content, post ID, or post object.
 *                                            Defaults to global $post.
 * @return bool Whether the post content contains the specified block.
 */
function has_block_with_attrs( $block_name, $attrs, $post = null ) {
	// Short out if the block is not found, avoids running `parse_block` unless we need to.
	if ( ! has_block( $block_name, $post ) ) {
		return false;
	}

	if ( ! is_string( $post ) ) {
		$wp_post = get_post( $post );
		if ( $wp_post instanceof WP_Post ) {
			$post = $wp_post->post_content;
		}
	}

	$all_blocks = array();
	$blocks = parse_blocks( $post );
	$blocks_queue = $blocks;

	// Flatten the nested blocks list returned by parse_blocks.
	while ( count( $blocks_queue ) > 0 ) { // phpcs:ignore -- inline count OK.
		$block = array_shift( $blocks_queue );
		array_push( $all_blocks, $block );
		if ( ! empty( $block['innerBlocks'] ) ) {
			foreach ( $block['innerBlocks'] as $inner_block ) {
				array_push( $blocks_queue, $inner_block );
			}
		}
	}

	foreach ( $all_blocks as $block ) {
		// If there is no diff result between the requested attributes & the set attributes, all the
		// searched-for values have been found.
		if ( ( $block_name === $block['blockName'] ) && ! array_diff( $attrs, $block['attrs'] ) ) {
			return true;
		}
	}

	return false;
}
