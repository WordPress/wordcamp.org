<?php
namespace WordCamp\Blocks\MetaLink;

defined( 'WPINC' ) || die();

/**
 * Register block types and enqueue scripts.
 *
 * @return void
 */
function init() {
	register_block_type_from_metadata(
		__DIR__,
		array(
			'render_callback' => __NAMESPACE__ . '\render',
		)
	);
}
add_action( 'init', __NAMESPACE__ . '\init' );


/**
 * Renders the block on the server.
 *
 * @param array    $attributes Block attributes.
 * @param string   $content    Block default content.
 * @param WP_Block $block      Block instance.
 * @return string Returns an HTML link using the selected meta value as the URL.
 */
function render( $attributes, $content, $block ) {
	if ( ! isset( $block->context['postId'] ) ) {
		return '';
	}

	$meta_keys = array_merge(
		get_registered_meta_keys( 'post' ),
		get_registered_meta_keys( 'post', $block->context['postType'] )
	);
	// If the meta value is not visible in the API, it should not be visible here. This prevents leaking data
	// that should not be public.
	if ( ! isset( $meta_keys[ $attributes['key'] ] ) || ! $meta_keys[ $attributes['key'] ]['show_in_rest'] ) {
		return '';
	}

	$post_ID = $block->context['postId'];
	$url     = get_post_meta( $post_ID, $attributes['key'], true );
	$text    = $attributes['text'];

	if ( ! $url ) {
		return '';
	}

	$classes = array_filter( array(
		isset( $attributes['textAlign'] ) ? 'has-text-align-' . $attributes['textAlign'] : false,
	) );
	$wrapper_attributes = get_block_wrapper_attributes( array( 'class' => implode( ' ', $classes ) ) );

	return sprintf(
		'<div %1$s><a href="%2$s">%3$s</a></div>',
		$wrapper_attributes,
		esc_url( $url ),
		wp_kses_post( $text )
	);
}

/**
 * Enable the meta-link block & variations.
 *
 * @param array $data
 * @return array
 */
function add_script_data( array $data ) {
	$data['meta-link'] = true;

	return $data;
}
add_filter( 'wordcamp_blocks_script_data', __NAMESPACE__ . '\add_script_data' );
