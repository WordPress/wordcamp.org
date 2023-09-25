<?php
/**
 * Block Name: Horizontal Slider
 * Description: A block for use across the whole wp.org network.
 *
 * @package wporg
 */

namespace WordPressdotorg\MU_Plugins\wporg;

/**
 * Actions and filters.
 */
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\register_assets', 20 );
add_action( 'init', __NAMESPACE__ . '\horizontal_slider_block_init' );

/**
 * Register scripts, styles, and block.
 */
function register_assets() {
	$deps_path = __DIR__ . '/build/index.asset.php';
	
	if ( ! file_exists( $deps_path ) ) {
		return;
	}

	$block_info = require $deps_path;

	if ( ! is_admin() && function_exists( 'wporg_themes_init' ) ) {
		wp_enqueue_script(
			'wporg-horizontal-slider',
			plugin_dir_url( __FILE__ ) . 'build/index.js',
			$block_info['dependencies'],
			$block_info['version'],
			true
		);

		wp_enqueue_style(
			'wporg-horizontal-slider-style',
			plugin_dir_url( __FILE__ ) . '/build/style.css',
			array(),
			filemtime( __DIR__ . '/build/style.css' )
		);

		wp_style_add_data( 'wporg-horizontal-slider-style', 'rtl', 'replace' ); 
	}
}

/**
 * Registers the block using the metadata loaded from the `block.json` file.
 * Behind the scenes, it registers also all assets so they can be enqueued
 * through the block editor in the corresponding context.
 *
 * @see https://developer.wordpress.org/reference/functions/register_block_type/
 */
function horizontal_slider_block_init() {
	register_block_type( __DIR__ . '/build' );
}
