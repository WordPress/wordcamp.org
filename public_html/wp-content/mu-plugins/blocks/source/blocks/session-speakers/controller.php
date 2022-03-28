<?php
namespace WordCamp\Blocks\SessionSpeakers;

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
 * @return string Returns the avatar for the current post.
 */
function render( $attributes, $content, $block ) {
	if ( ! isset( $block->context['postId'] ) ) {
		return '';
	}

	$post_ID  = $block->context['postId'];
	$speaker_ids = get_post_meta( $post_ID, '_wcpt_speaker_id' );

	// Session has no published speakers.
	if ( ! is_array( $speaker_ids ) || empty( $speaker_ids ) ) {
		return '';
	}

	$byline  = ! empty( $attributes['byline'] ) ? $attributes['byline'] : false;
	$classes = array_filter( array(
		isset( $attributes['textAlign'] ) ? 'has-text-align-' . $attributes['textAlign'] : false,
	) );

	$content = '';
	if ( ! empty( $byline ) ) {
		$content .= '<span class="wp-block-wordcamp-session-speakers__byline">' . esc_html( $byline ) . '</span>';
	}

	foreach ( $speaker_ids as $speaker_id ) {
		$content .= '<span class="wp-block-wordcamp-session-speakers__name">';
		if ( isset( $attributes['isLink'] ) && $attributes['isLink'] ) {
			$content .= sprintf( '<a href="%1$s">%2$s</a>', get_the_permalink( $speaker_id ), get_the_title( $speaker_id ) );
		} else {
			$content .= get_the_title( $speaker_id );
		}
		$content .= '</span>';
	}

	$wrapper_attributes = get_block_wrapper_attributes( array( 'class' => implode( ' ', $classes ) ) );
	return "<div $wrapper_attributes>$content</div>";
}
/**
 * Enable the session-speakers block.
 *
 * @param array $data
 * @return array
 */
function add_script_data( array $data ) {
	$data['session-speakers'] = true;

	return $data;
}
add_filter( 'wordcamp_blocks_script_data', __NAMESPACE__ . '\add_script_data' );
