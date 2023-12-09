<?php
/**
 * Block Name: Post Meta
 * Description: Display a post meta value.
 */

namespace WordPressdotorg\Events_2023\Post_Meta;
use WP_Block;

add_action( 'init', __NAMESPACE__ . '\init' );


/**
 * Register block.
 */
function init(): void {
	register_block_type(
		__DIR__,
		array(
			'render_callback' => __NAMESPACE__ . '\render',
		)
	);
}

/**
 * Render the block.
 */
function render( array $attributes, string $content, WP_Block $block ): string {
	if ( empty( $attributes['id'] ) ) {
		$attributes['id'] = get_the_ID();
	}

	$wrapper_attributes = get_block_wrapper_attributes( array(
		'class' => 'wporg-post-meta-key-' . $attributes['key']
	) );

	$value = get_post_meta( $attributes['id'], $attributes['key'], $attributes['single'] );

	return sprintf(
		'<span %1$s>%2$s</span>',
		$wrapper_attributes,
		$value
	);
}
