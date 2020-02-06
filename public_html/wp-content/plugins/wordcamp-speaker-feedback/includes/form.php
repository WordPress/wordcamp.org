<?php

namespace WordCamp\SpeakerFeedback\Form;
use function WordCamp\SpeakerFeedback\{ get_views_path, get_assets_url };

add_filter( 'the_content', __NAMESPACE__ . '\render' );
add_filter( 'wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_assets' );

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

/**
 * Add stylesheet to the form page.
 */
function enqueue_assets() {
	if ( is_page( get_option( 'feedback_page' ) ) ) {
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
