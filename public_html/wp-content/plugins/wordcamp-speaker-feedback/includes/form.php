<?php

namespace WordCamp\SpeakerFeedback\Form;

use WP_Post, WP_Query;
use function WordCamp\SpeakerFeedback\{ get_views_path, get_assets_url };
use function WordCamp\SpeakerFeedback\CommentMeta\get_feedback_questions;
use function WordCamp\SpeakerFeedback\Post\post_accepts_feedback;
use const WordCamp\SpeakerFeedback\{ OPTION_KEY, QUERY_VAR };
use const WordCamp\SpeakerFeedback\Post\ACCEPT_INTERVAL_IN_SECONDS;

defined( 'WPINC' ) || die();

add_filter( 'the_content', __NAMESPACE__ . '\render' );
add_filter( 'wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_assets' );

/**
 * Check if the current page should include the feedback form.
 *
 * @global WP_Query $wp_query
 *
 * @return bool
 */
function has_feedback_form() {
	global $wp_query;
	return false !== $wp_query->get( QUERY_VAR, false );
}

/**
 * Short-circuit the content, and output the feedback form (or the session select).
 *
 * This assumes that `wcb_session` is the only post type supporting speaker feedback. This will need to be updated
 * if more post type support is added.
 *
 * @global WP_Post $post
 *
 * @return string
 */
function render( $content ) {
	global $post;

	$now = date_create( 'now', wp_timezone() );

	if ( has_feedback_form() ) {
		$accepts_feedback = post_accepts_feedback( $post->ID );

		ob_start();

		if ( is_wp_error( $accepts_feedback ) ) {
			$message = $accepts_feedback->get_error_message();
			require get_views_path() . 'form-not-available.php';
			return $content . ob_get_clean(); // Append the error message, return early.
		}

		$questions       = get_feedback_questions();
		$rating_question = $questions['rating'];
		$text_questions  = array_filter( array_map(
			function( $key, $question ) {
				return ( 'q' === $key[0] ) ? array( $key, $question ) : false;
			},
			array_keys( $questions ),
			$questions
		) );

		require get_views_path() . 'form-feedback.php';

		$content = $content . ob_get_clean(); // Append form to the normal content.
	} elseif ( is_page( get_option( OPTION_KEY ) ) ) {
		$wordcamp = get_wordcamp_post();

		if ( isset( $wordcamp->meta['Start Date (YYYY-mm-dd)'][0] ) ) {
			$date_string = gmdate( 'Y-m-d', $wordcamp->meta['Start Date (YYYY-mm-dd)'][0] );
			$start_date  = date_create( $date_string, wp_timezone() )->getTimestamp();
		} else {
			// No start date set, the event probably hasn't been scheduled yet. Use a far future date for now.
			$start_date = $now->getTimestamp() + YEAR_IN_SECONDS;
		}

		if ( isset( $wordcamp->meta['End Date (YYYY-mm-dd)'][0] ) ) {
			$date_string = gmdate( 'Y-m-d', $wordcamp->meta['End Date (YYYY-mm-dd)'][0] );
			$end_date    = date_create( $date_string, wp_timezone() )->getTimestamp();
		} else {
			// No end date set, assume it's 24 hours later.
			$end_date = $start_date + DAY_IN_SECONDS;
		}

		if ( $now->getTimestamp() < absint( $start_date ) ) {
			$message = __( 'Feedback forms are not available until the event has started.', 'wordcamporg' );
			$file    = 'form-not-available.php';
		} elseif ( $now->getTimestamp() > absint( $end_date ) + ACCEPT_INTERVAL_IN_SECONDS ) {
			$message = __( 'Feedback forms are closed for this event.', 'wordcamporg' );
			$file    = 'form-not-available.php';
		} else {
			$file = 'form-select-sessions.php';
		}

		ob_start();
		require get_views_path() . $file;

		$content = ob_get_clean(); // Replace the content.
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
			get_assets_url() . 'build/style.css',
			array(),
			filemtime( dirname( __DIR__ ) . '/assets/build/style.css' )
		);

		wp_enqueue_script(
			'speaker-feedback',
			get_assets_url() . 'js/script.js',
			array( 'wp-api-fetch' ),
			filemtime( dirname( __DIR__ ) . '/assets/js/script.js' ),
			true
		);

		$data = array(
			'messages'  => array(
				'submitSuccess' => __( 'Feedback submitted.', 'wordcamporg' ),
			),
		);

		wp_add_inline_script(
			'speaker-feedback',
			sprintf(
				'var SpeakerFeedbackData = JSON.parse( decodeURIComponent( \'%s\' ) );',
				rawurlencode( wp_json_encode( $data ) )
			),
			'before'
		);
	}
}
