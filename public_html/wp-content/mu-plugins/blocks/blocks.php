<?php
namespace WordCamp\Blocks;

defined( 'WPINC' ) || die();

define( __NAMESPACE__ . '\PLUGIN_DIR', \plugin_dir_path( __FILE__ ) );
define( __NAMESPACE__ . '\PLUGIN_URL', \plugins_url( '/', __FILE__ ) );

/**
 * Load files.
 *
 * @return void
 */
function load_includes() {
	$includes_dir   = PLUGIN_DIR . 'includes/';
	$blocks_dir     = PLUGIN_DIR . 'source/blocks/';
	$components_dir = PLUGIN_DIR . 'source/components/';
	$hooks_dir      = PLUGIN_DIR . 'source/hooks/';

	require_once $includes_dir . 'definitions.php';

	// Utilities.
	require_once $includes_dir . 'content.php';

	// Components.
	require_once $components_dir . 'item/controller.php';
	require_once $components_dir . 'image/controller.php';
	require_once $components_dir . 'post-list/controller.php';

	// Blocks.
	require_once $blocks_dir . 'organizers/controller.php';
	require_once $blocks_dir . 'sessions/controller.php';
	require_once $blocks_dir . 'speakers/controller.php';
	require_once $blocks_dir . 'sponsors/controller.php';

	$blocks_test_sites = array(
		928,  // 2017.testing
		1190, // 2019.dublin
		1028, // 2019.us
	);

	if (
		( defined( 'WORDCAMP_ENVIRONMENT' ) && 'production' !== WORDCAMP_ENVIRONMENT )
		|| in_array( get_current_blog_id(), $blocks_test_sites, true )
	) {
		require_once $blocks_dir . 'live-schedule/controller.php';

		// Hooks.
		require_once $hooks_dir . 'latest-posts/controller.php';
	}

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

add_filter( 'block_categories', __NAMESPACE__ . '\register_block_categories' );

/**
 * Register assets.
 *
 * The assets get enqueued automatically by the registered block types.
 *
 * @return void
 */
function register_assets() {
	$path        = PLUGIN_DIR . 'build/blocks.min.js';
	$deps_path   = PLUGIN_DIR . 'build/blocks.min.asset.json';
	$script_info = file_exists( $deps_path )
		? require( $deps_path )
		: array(
			'dependencies' => array(),
			'version' => filemtime( $path ),
		);

	// Special case, because this isn't a wp package.
	$script_info['dependencies'][] = 'wp-sanitize';

	wp_register_style(
		'wordcamp-blocks',
		PLUGIN_URL . 'build/blocks.min.css',
		[],
		filemtime( PLUGIN_DIR . 'build/blocks.min.css' )
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
	$data = apply_filters( 'wordcamp_blocks_script_data', [] );

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
