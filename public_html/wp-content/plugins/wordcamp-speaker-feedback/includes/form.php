<?php

namespace WordCamp\SpeakerFeedback\Form;
use const WordCamp\SpeakerFeedback\{ OPTION_KEY, QUERY_VAR };
use function WordCamp\SpeakerFeedback\{ get_views_path, get_assets_url };

add_filter( 'the_content', __NAMESPACE__ . '\render' );
add_filter( 'wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_assets' );

/**
 * Check if the current page should include the feedback form.
 */
function has_feedback_form() {
	global $wp_query;
	return false !== $wp_query->get( QUERY_VAR, false );
}

/**
 * Short-circuit the content, and output the feedback form (or the session select).
 */
function render( $content ) {
	if ( has_feedback_form() ) {
		ob_start();
		require get_views_path() . 'form-feedback.php';
		return $content . ob_get_clean();
	} elseif ( is_page( get_option( OPTION_KEY ) ) ) {
		ob_start();
		require get_views_path() . 'form-select-sessions.php';
		return ob_get_clean();
	}

	return $content;
}

/**
 * Add stylesheet to the form page.
 */
function enqueue_assets() {
	if ( has_feedback_form() || is_page( get_option( OPTION_KEY ) ) ) {
		wp_enqueue_style(
			'speaker-feedback',
			get_assets_url() . 'css/style.css',
			array(),
			filemtime( dirname( __DIR__ ) . '/assets/css/style.css' )
		);

		wp_enqueue_script(
			'speaker-feedback',
			get_assets_url() . 'js/script.js',
			array(),
			filemtime( dirname( __DIR__ ) . '/assets/js/script.js' ),
			true
		);
	}
}
