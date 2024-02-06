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
 * @return string Returns the session list for the current speaker post.
 */
function render( $attributes, $content, $block ) {
	if ( ! isset( $block->context['postId'] ) ) {
		return '';
	}

	$post_ID = $block->context['postId'];
	$classes = array_filter( array(
		isset( $attributes['textAlign'] ) ? 'has-text-align-' . $attributes['textAlign'] : false,
	) );

	$session_args = array(
		'post_type'      => 'wcb_session',
		'posts_per_page' => -1,
		'meta_key'       => '_wcpt_speaker_id',
		'meta_value'     => $post_ID,
		'orderby'        => 'title',
		'order'          => 'asc',
		'post_status'    => array( 'publish', 'private' ),
	);

	$sessions = get_posts( $session_args );

	if ( ! isset( $sessions ) || count( $sessions ) < 1 ) {
		return '';
	}

	// Sort the sessions in PHP rather than the DB query, so that we don't skip sessions without times set.
	usort(
		$sessions,
		function( $session_a, $session_b ) {
			$time_a = (int) get_post_meta( $session_a->ID, '_wcpt_session_time', true );
			$time_b = (int) get_post_meta( $session_b->ID, '_wcpt_session_time', true );
			return ( $time_a < $time_b ) ? -1 : 1;
		}
	);

	$content = '';
	foreach ( $sessions as $session ) {
		$session_li = '<li><p>';
		if ( isset( $attributes['isLink'] ) && $attributes['isLink'] ) {
			$session_li .= sprintf( '<a href="%1$s">%2$s</a>', get_the_permalink( $session->ID ), get_the_title( $session->ID ) );
		} else {
			$session_li .= get_the_title( $session->ID );
		}
		$session_li .= '</p>';

		if ( isset( $attributes['hasSessionDetails'] ) && $attributes['hasSessionDetails'] ) {
			$tracks     = get_the_terms( $session, 'wcb_track' );
			$has_date   = (bool) $session->_wcpt_session_time;
			$has_tracks = ! is_wp_error( $tracks ) && ! empty( $tracks );

			$session_li .= '<p class="wordcamp-speaker-sessions__session-info">';

			if ( ! $has_date && $has_tracks ) {
				$session_li .= sprintf(
					/* translators: %s: session tracks */
					esc_html__( 'In %s', 'wordcamporg' ),
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
			} else if ( $has_tracks ) {
				$session_li .= sprintf(
					/* translators: 1: session date; 2: session time; 3: session tracks */
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
			} else if ( $has_date ) {
				$session_li .= sprintf(
					/* translators: 1: session date; 2: session time */
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
