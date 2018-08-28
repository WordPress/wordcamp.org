<?php

namespace WordCamp\Blocks;
defined( 'WPINC' ) || die();

use WP_Post;

/**
 *
 */
function load() {
	if ( ! function_exists( 'register_block_type' ) ) {
		return;
	}

	require_once trailingslashit( plugin_dir_path( __FILE__ ) ) . 'speakers/speakers.php';
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\load' );

/**
 *
 *
 * @param array   $default_categories
 * @param WP_Post $post
 *
 * @return array
 */
function register_block_categories( $default_categories, $post ) {
	$default_categories[] = array(
		'slug'  => 'wordcamp',
		'title' => __( 'WordCamp Blocks', 'wordcamporg' ),
	);

	return $default_categories;
}

add_filter( 'block_categories', __NAMESPACE__ . '\register_block_categories', 10, 2 );
