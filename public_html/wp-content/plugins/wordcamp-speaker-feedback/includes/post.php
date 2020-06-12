<?php

namespace WordCamp\SpeakerFeedback\Post;

use WP_Error;
use WordCamp_Post_Types_Plugin;
use const WordCamp\SpeakerFeedback\Comment\COMMENT_TYPE;

defined( 'WPINC' ) || die();

define( __NAMESPACE__ . '\ACCEPT_INTERVAL_IN_SECONDS', WEEK_IN_SECONDS * 2 );
// Defined here so that wc-post-types is not a dependency for this file.
define( __NAMESPACE__ . '\SESSION_DEFAULT_DURATION', 50 * MINUTE_IN_SECONDS );

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

	// Organizers need to be able to test and style feedback forms.
	if ( current_user_can( 'moderate_' . COMMENT_TYPE ) ) {
		return true;
	}

	if ( 'publish' !== get_post_status( $post ) ) {
		return new WP_Error(
			'speaker_feedback_post_unavailable',
			__( 'This post is not available for feedback.', 'wordcamporg' )
		);
	}

	if ( 'wcb_session' === get_post_type( $post ) ) {
		$session_type     = $post->_wcpt_session_type;
		$session_time     = absint( $post->_wcpt_session_time );
		$session_duration = absint( $post->_wcpt_session_duration );
		if ( ! $session_duration ) {
			$session_duration = SESSION_DEFAULT_DURATION;
		}

		if ( 'session' !== $session_type ) {
			return new WP_Error(
				'speaker_feedback_invalid_session_type',
				__( 'This type of session does not accept feedback.', 'wordcamporg' )
			);
		}

		if ( ! $session_time ) {
			return new WP_Error(
				'speaker_feedback_invalid_session_time',
				__( 'This session cannot accept feedback without a start time.', 'wordcamporg' )
			);
		}

		if ( $now->getTimestamp() < $session_time ) {
			return new WP_Error(
				'speaker_feedback_session_too_soon',
				__( 'This session will not accept feedback until it has started.', 'wordcamporg' )
			);
		}

		if ( $now->getTimestamp() > $session_time + $session_duration + ACCEPT_INTERVAL_IN_SECONDS ) {
			return new WP_Error(
				'speaker_feedback_session_too_late',
				__( 'This session is no longer accepting feedback.', 'wordcamporg' )
			);
		}
	}

	return true;
}

/**
 * Find the timestamp of the earliest published session.
 *
 * @return bool|int An integer timestamp, or false if no valid sessions are found.
 */
function get_earliest_session_timestamp() {
	$earliest_session = get_posts( array(
		'post_type'      => 'wcb_session',
		'post_status'    => 'publish',
		'meta_key'       => '_wcpt_session_time',
		'orderby'        => 'meta_value_num',
		'order'          => 'ASC',
		'posts_per_page' => 1,
	) );

	if ( empty( $earliest_session ) ) {
		return false;
	}

	return absint( $earliest_session[0]->_wcpt_session_time );
}

/**
 * Find the timestamp of the end of the latest published session.
 *
 * @return bool|int An integer timestamp, or false if no valid sessions are found.
 */
function get_latest_session_ending_timestamp() {
	$latest_session = get_posts( array(
		'post_type'      => 'wcb_session',
		'post_status'    => 'publish',
		'meta_key'       => '_wcpt_session_time',
		'orderby'        => 'meta_value_num',
		'order'          => 'DESC',
		'posts_per_page' => 1,
	) );

	if ( empty( $latest_session ) ) {
		return false;
	}

	$duration = $latest_session[0]->_wcpt_session_duration;
	if ( ! $duration ) {
		$duration = SESSION_DEFAULT_DURATION;
	}

	return absint( $latest_session[0]->_wcpt_session_time ) + absint( $duration );
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

/**
 * Get a list of speaker names for a given session.
 *
 * @param int $session_id
 *
 * @return array
 */
function get_session_speaker_names( $session_id ) {
	$speakers         = array();
	$speaker_post_ids = get_post_meta( $session_id, '_wcpt_speaker_id' );

	if ( ! empty( $speaker_post_ids ) ) {
		$speakers = get_posts( array(
			'post_type'      => 'wcb_speaker',
			'posts_per_page' => - 1,
			'post__in'       => $speaker_post_ids,
		) );
	}

	$names = array();
	foreach ( $speakers as $speaker ) {
		$names[] = apply_filters( 'the_title', $speaker->post_title );
	}

	return $names;
}

/**
 * Generate a link to the front end feedback UI for a particular session.
 *
 * @param int $post_id
 *
 * @return string
 */
function get_session_feedback_url( $post_id ) {
	$url = '';

	if ( post_type_supports( get_post_type( $post_id ), 'wordcamp-speaker-feedback' ) ) {
		$url = trailingslashit( get_permalink( $post_id ) ) . 'feedback/';
	}

	return $url;
}
