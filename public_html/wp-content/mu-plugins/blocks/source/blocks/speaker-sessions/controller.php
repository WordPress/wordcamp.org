<?php
namespace WordCamp\Blocks\SpeakerSessions;

use function WordCamp\Blocks\Speakers\get_speaker_sessions;

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
	$sessions = get_speaker_sessions( array( $post_ID ) );
	$classes  = array_filter( array(
		isset( $attributes['textAlign'] ) ? 'has-text-align-' . $attributes['textAlign'] : false,
	) );

	// Speaker has no sessions.
	if ( ! isset( $sessions[ $post_ID ] ) || count( $sessions[ $post_ID ] ) < 1 ) {
		return '';
	}

	$content = '';
	foreach ( $sessions[ $post_ID ] as $session ) {
		$session_li = '<li><p>';
		if ( isset( $attributes['isLink'] ) && $attributes['isLink'] ) {
			$session_li .= sprintf( '<a href="%1$s">%2$s</a>', get_the_permalink( $session->ID ), get_the_title( $session->ID ) );
		} else {
			$session_li .= get_the_title( $session->ID );
		}
		$session_li .= '</p>';

		if ( isset( $attributes['hasSessionDetails'] ) && $attributes['hasSessionDetails'] ) {
			$tracks = get_the_terms( $session, 'wcb_track' );
			$session_li .= '<p class="wordcamp-speaker-sessions__session-info">';
			if ( ! is_wp_error( $tracks ) && ! empty( $tracks ) ) {
				$session_li .= sprintf(
					/* translators: 1: session date; 2: session time; 3: session track; */
					esc_html__( '%1$s at %2$s in %3$s', 'wordcamporg' ),
					esc_html( wp_date( get_option( 'date_format' ), $session->_wcpt_session_time ) ),
					esc_html( wp_date( get_option( 'time_format' ), $session->_wcpt_session_time ) ),
					implode( ', ', array_map( // phpcs:ignore -- escaped below.
						function ( $track ) {
							return sprintf(
								'<span class="wordcamp-speaker-sessions__track slug-%s">%s</span>',
								esc_attr( $track->slug ),
								esc_html( $track->name )
							);
						},
						$tracks
					) )
				);
			} else {
				$session_li .= sprintf(
					/* translators: 1: session date; 2: session time; */
					esc_html__( '%1$s at %2$s', 'wordcamporg' ),
					esc_html( wp_date( get_option( 'date_format' ), $session->_wcpt_session_time ) ),
					esc_html( wp_date( get_option( 'time_format' ), $session->_wcpt_session_time ) )
				);
			}
			$session_li .= '</p>';
		}

		$session_li .= '</li>';
		$content .= $session_li;
	}

	$wrapper_attributes = get_block_wrapper_attributes( array( 'class' => implode( ' ', $classes ) ) );
	return "<ul $wrapper_attributes>$content</ul>";
}
/**
 * Enable the speaker-sessions block.
 *
 * @param array $data
 * @return array
 */
function add_script_data( array $data ) {
	$data['speaker-sessions'] = true;

	return $data;
}
add_filter( 'wordcamp_blocks_script_data', __NAMESPACE__ . '\add_script_data' );
