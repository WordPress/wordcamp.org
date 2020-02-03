<?php

namespace WordCamp\SpeakerFeedback\Form;
use function WordCamp\SpeakerFeedback\get_views_path;

add_filter( 'the_content', __NAMESPACE__ . '\render' );

/**
 * Short-circuit the content, and output the feedback form (or the session select).
 */
function render( $content ) {
	if ( is_page( get_option( 'feedback_page' ) ) ) {
		ob_start();
		require get_views_path() . 'form-select-sessions.php';
		return ob_get_clean();
	}

	return $content;
}
