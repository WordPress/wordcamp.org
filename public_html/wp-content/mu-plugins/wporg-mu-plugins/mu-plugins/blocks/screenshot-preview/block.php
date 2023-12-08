<?php
/**
 * Plugin name: Gutenberg: Pattern Previewer
 * Description: A block that displays a pattern.
 * Version:     2.0
 * Author:      WordPress.org
 * Author URI:  http://wordpress.org/
 * License:     GPLv2 or later
 */

namespace WordPressdotorg\Gutenberg\ScreenshotPreview;

defined( 'WPINC' ) || die();

add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\register_assets', 20 );

/**
 * Renders the `wporg/screenshot-preview` block on the server.
 *
 * @param array    $attributes Block attributes.
 * @param string   $content    Block default content.
 * @param WP_Block $block      Block instance.
 *
 * @return string Returns the event year for the current post.
 */
function render_block( $attributes, $content ) {
	if( ! isset( $attributes['link'] ) || ! isset( $attributes['preview-link'] ) ) {
		return '';
	}

	return sprintf(
		'<div 
			class="wporg-screenshot-preview-js"
			data-link="%1$s" 
			data-preview-link="%2$s" 
		></div>',
		esc_attr( $attributes['link'] ),		
		esc_attr( $attributes['preview-link'] )
	);
}

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
			'wporg-screenshot-preview',
			plugin_dir_url( __FILE__ ) . 'build/index.js',
			$block_info['dependencies'],
			$block_info['version'],
			true
		);

		wp_enqueue_style(
			'wporg-screenshot-preview-style',
			plugin_dir_url( __FILE__ ) . '/build/style.css',
			array(),
			filemtime( __DIR__ . '/build/style.css' )
		);

		wp_style_add_data( 'wporg-screenshot-preview-style', 'rtl', 'replace' ); 
	}

	register_block();
}

/**
 * Registers the `wporg/screenshot-preview` block on the server.
 */
function register_block() {
	register_block_type(
		__DIR__ . '/block.json',
		array(
			'render_callback' => __NAMESPACE__ . '\render_block',
		)
	);
}
