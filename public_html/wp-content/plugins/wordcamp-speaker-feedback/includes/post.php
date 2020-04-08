<?php

namespace WordCamp\SpeakerFeedback\Post;

use WP_Error;

defined( 'WPINC' ) || die();

define( __NAMESPACE__ . '\ACCEPT_INTERVAL_IN_SECONDS', WEEK_IN_SECONDS * 2 );

/**
 * Check to see if feedback comments can be added to a post.
 *
 * @param int $post_id
 *
 * @return bool|WP_Error
 */
function post_accepts_feedback( $post_id ) {
	$post = get_post( $post_id );
	$now  = date_create( 'now', wp_timezone() );

	if ( ! $post ) {
		return new WP_Error(
			'speaker_feedback_invalid_post_id',
			__( 'The post does not exist. Feedback must relate to a post.', 'wordcamporg' )
		);
	}

	if ( ! post_type_supports( get_post_type( $post ), 'wordcamp-speaker-feedback' ) ) {
		return new WP_Error(
			'speaker_feedback_post_not_supported',
			__( 'This post does not support feedback.', 'wordcamporg' )
		);
	}

	if ( 'publish' !== get_post_status( $post ) ) {
		return new WP_Error(
			'speaker_feedback_post_unavailable',
			__( 'This post is not available for feedback.', 'wordcamporg' )
		);
	}

	// These assume the post type is `wcb_session`.
	if ( 'session' !== $post->_wcpt_session_type ) {
		return new WP_Error(
			'speaker_feedback_invalid_session_type',
			__( 'This type of session does not accept feedback.', 'wordcamporg' )
		);
	}

	if ( $now->getTimestamp() < absint( $post->_wcpt_session_time ) ) {
		return new WP_Error(
			'speaker_feedback_session_too_soon',
			__( 'This session will not accept feedback until it has started.', 'wordcamporg' )
		);
	}

	if ( $now->getTimestamp() > absint( $post->_wcpt_session_time ) + ACCEPT_INTERVAL_IN_SECONDS ) {
		return new WP_Error(
			'speaker_feedback_session_too_late',
			__( 'This session is no longer accepting feedback.', 'wordcamporg' )
		);
	}

	return true;
}

/**
 * Get a list of user IDs of the speakers for a given session.
 *
 * @param int $session_id
 *
 * @return array
 */
function get_session_speaker_user_ids( $session_id ) {
	$user_ids         = array();
	$speaker_post_ids = get_post_meta( $session_id, '_wcpt_speaker_id' );

	foreach ( $speaker_post_ids as $post_id ) {
		$user_id = absint( get_post_meta( $post_id, '_wcpt_user_id', true ) );

		if ( $user_id ) {
			$user_ids[] = $user_id;
		}
	}

	return $user_ids;
}
