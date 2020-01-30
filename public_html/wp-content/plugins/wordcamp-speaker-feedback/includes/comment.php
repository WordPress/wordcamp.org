<?php

namespace WordCamp\SpeakerFeedback\Comment;

use WP_User;

defined( 'WPINC' ) || die();

const COMMENT_TYPE = 'speaker-feedback';

/**
 * Add a new feedback submission.
 *
 * @param int           $post_id
 * @param array|WP_User $feedback_author
 * @param array         $feedback_meta
 *
 * @return false|int
 */
function add_feedback( $post_id, $feedback_author, array $feedback_meta ) {
	$args = array(
		'comment_approved' => 0, // "hold".
		'comment_post_ID'  => $post_id,
		'comment_type'     => COMMENT_TYPE,
		'comment_meta'     => $feedback_meta,
	);

	if ( $feedback_author instanceof WP_User ) {
		$args['user_id'] = $feedback_author->ID;
	} elseif ( isset( $feedback_author['name'], $feedback_author['email'] ) ) {
		$args['comment_author']       = $feedback_author['name'];
		$args['comment_author_email'] = $feedback_author['email'];
	} else {
		// No author, bail.
		return false;
	}

	return wp_insert_comment( $args );
}

/**
 * Update an existing feedback submission.
 *
 * The only parts of a feedback submission that we'd perhaps want to update after submission are the feedback rating
 * and questions that are stored in comment meta.
 *
 * @param int   $comment_id
 * @param array $feedback_meta
 *
 * @return int
 */
function update_feedback( $comment_id, array $feedback_meta ) {
	$args = array(
		'comment_ID'   => $comment_id,
		'comment_meta' => $feedback_meta,
	);

	// This will always return `0` because the comment itself does not get updated, only comment meta.
	return wp_update_comment( $args );
}

/**
 * Retrieve a list of feedback submissions.
 *
 * @param array $status
 * @param array $post__in
 * @param array $meta_query
 *
 * @return array A collection of WP_Comment objects.
 */
function get_feedback( array $status = array( 'hold', 'approve' ), array $post__in = array(), array $meta_query = array() ) {
	$args = array(
		'status'  => $status,
		'type'    => COMMENT_TYPE,
		'orderby' => 'comment_date',
		'order'   => 'asc',
	);

	if ( ! empty( $post__in ) ) {
		$args['post__in'] = $post__in;
	}

	if ( ! empty( $meta_query ) ) {
		$args['meta_query'] = $meta_query;
	}

	$feedback = get_comments( $args );

	// This makes loading meta values for comments much faster.
	wp_queue_comments_for_comment_meta_lazyload( $feedback );

	return $feedback;
}
