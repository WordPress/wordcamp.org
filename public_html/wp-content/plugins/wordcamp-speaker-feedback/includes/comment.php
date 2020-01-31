<?php

namespace WordCamp\SpeakerFeedback\Comment;

use WP_Comment;
use WordCamp\SpeakerFeedback\Feedback;

defined( 'WPINC' ) || die();

const COMMENT_TYPE = 'wordcamp-speaker-feedback';

/**
 * Add a new feedback submission.
 *
 * @param int       $post_id         The ID of the post to attach the feedback to.
 * @param array|int $feedback_author Either an array containing 'name' and 'email' values, or a user ID.
 * @param array     $feedback_meta   An associative array of key => value pairs.
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

	if ( is_int( $feedback_author ) ) {
		$args['user_id'] = $feedback_author;
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
 * @param int   $comment_id    The ID of the comment to update.
 * @param array $feedback_meta An associative array of key => value pairs.
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
 * @param array $status     An array of statuses to include in the results.
 * @param array $post__in   An array of post IDs whose feedback comments should be included.
 * @param array $meta_query A valid `WP_Meta_Query` array.
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

	$comments = get_comments( $args );

	// This makes loading meta values for comments much faster.
	wp_queue_comments_for_comment_meta_lazyload( $comments );

	return array_map(
		function( WP_Comment $comment ) {
			return new Feedback( $comment );
		},
		$comments
	);
}

/**
 * Trash a feedback submission.
 *
 * @param int $comment_id The ID of the comment to delete.
 *
 * @return bool
 */
function delete_feedback( $comment_id ) {
	return wp_delete_comment( $comment_id );
}
