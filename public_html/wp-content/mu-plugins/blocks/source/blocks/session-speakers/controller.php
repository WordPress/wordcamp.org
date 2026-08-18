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
 * @return string Returns the speaker list for the current session post.
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

	// Resolve the ids through a query, the way add_speaker_info_to_session_posts()
	// does for the classic themes, so that the post type is enforced and the
	// posts are fetched together rather than one lookup at a time.
	$speakers = get_posts( array(
		'post_type'      => 'wcb_speaker',
		'post__in'       => array_map( 'absint', $speaker_ids ),
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'orderby'        => 'post__in',
		'no_found_rows'  => true,
		// Only the title, slug, permalink and status are used below.
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	) );

	// Mirrors WP_REST_Posts_Controller::check_read_permission(): published posts
	// are readable by everyone, including logged out visitors, who hold no
	// capabilities at all.
	$speakers = array_filter(
		$speakers,
		function ( $speaker ) {
			return 'publish' === $speaker->post_status
				|| current_user_can( 'read_post', $speaker->ID );
		}
	);

	// Session has no speakers this viewer can read.
	if ( empty( $speakers ) ) {
		return '';
	}

	$byline  = ! empty( $attributes['byline'] ) ? $attributes['byline'] : false;
	$classes = array_filter( array(
		isset( $attributes['textAlign'] ) ? 'has-text-align-' . $attributes['textAlign'] : false,
	) );

	$content = '';
	if ( ! empty( $byline ) ) {
		$content .= '<span class="wp-block-wordcamp-session-speakers__byline">' . wp_kses_post( $byline ) . '</span>';
	}

	foreach ( $speakers as $speaker ) {
		$content .= '<span class="wp-block-wordcamp-session-speakers__name">';
		if ( isset( $attributes['isLink'] ) && $attributes['isLink'] ) {
			$content .= sprintf( '<a href="%1$s">%2$s</a>', get_the_permalink( $speaker->ID ), get_the_title( $speaker->ID ) );
		} else {
			$content .= get_the_title( $speaker->ID );
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
