<?php
namespace WordCamp\Blocks\SessionDate;

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
 * @return string Returns an HTML formatted date & time for the current session.
 */
function render( $attributes, $content, $block ) {
	if ( ! isset( $block->context['postId'] ) ) {
		return '';
	}

	$post_ID = $block->context['postId'];
	$date    = absint( get_post_meta( $post_ID, '_wcpt_session_time', true ) );
	$format  = isset( $attributes['format'] ) ? $attributes['format'] : __( 'F j, Y g:i a' );

	if ( isset( $attributes['showTimezone'] ) && $attributes['showTimezone'] ) {
		$format .= ' T';
	}

	$classes = array_filter( array(
		isset( $attributes['textAlign'] ) ? 'has-text-align-' . $attributes['textAlign'] : false,
	) );
	$wrapper_attributes = get_block_wrapper_attributes( array( 'class' => implode( ' ', $classes ) ) );

	return sprintf(
		'<div %1$s><time dateTime="%2$s">%3$s</time></div>',
		$wrapper_attributes,
		esc_html( wp_date( 'c', $date ) ),
		esc_html( wp_date( $format, $date ) )
	);
}

/**
 * Enable the session-date block.
 *
 * @param array $data
 * @return array
 */
function add_script_data( array $data ) {
	$data['session-date'] = true;

	return $data;
}
add_filter( 'wordcamp_blocks_script_data', __NAMESPACE__ . '\add_script_data' );
