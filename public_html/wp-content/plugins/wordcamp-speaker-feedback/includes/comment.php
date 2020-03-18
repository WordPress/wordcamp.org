<?php

namespace WordCamp\SpeakerFeedback\Comment;

use WP_Comment;
use WordCamp\SpeakerFeedback\Feedback;

defined( 'WPINC' ) || die();

const COMMENT_TYPE = 'wc-speaker-feedback'; // Per the database schema, this must be <= 20 characters.

/**
 * Count feedback comments, grouped by status.
 *
 * Based on Core's `get_comment_count()`.
 *
 * @param int $post_id
 *
 * @return array
 */
function count_feedback( $post_id = 0 ) {
	global $wpdb;

	$post_id = (int) $post_id;

	$where = array(
		$wpdb->prepare( 'comment_type = %s', COMMENT_TYPE ),
	);

	if ( $post_id > 0 ) {
		$where[] = $wpdb->prepare( 'comment_post_ID = %d', $post_id );
	}

	$where = implode( ' AND ', $where );

	$sql = "
		SELECT comment_approved, COUNT( * ) AS total
		FROM {$wpdb->comments}
		WHERE {$where}
		GROUP BY comment_approved
	";

	$totals = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is prepared above.

	$feedback_count = array(
		'approved'       => 0,
		'moderated'      => 0, // Originally this was `awaiting_moderation` but it doesn't appear that it is used.
		'spam'           => 0,
		'trash'          => 0,
		'post-trashed'   => 0,
		'total_comments' => 0,
		'all'            => 0,
	);

	foreach ( $totals as $row ) {
		switch ( $row['comment_approved'] ) {
			case 'trash':
				$feedback_count['trash'] = $row['total'];
				break;
			case 'post-trashed':
				$feedback_count['post-trashed'] = $row['total'];
				break;
			case 'spam':
				$feedback_count['spam']            = $row['total'];
				$feedback_count['total_comments'] += $row['total'];
				break;
			case '1':
				$feedback_count['approved']        = $row['total'];
				$feedback_count['total_comments'] += $row['total'];
				$feedback_count['all']            += $row['total'];
				break;
			case '0':
				$feedback_count['moderated']       = $row['total'];
				$feedback_count['total_comments'] += $row['total'];
				$feedback_count['all']            += $row['total'];
				break;
			default:
				break;
		}
	}

	return $feedback_count;
}

/**
 * Check if a comment is a feedback comment.
 *
 * @param WP_Comment|Feedback|string|int $comment A comment/feedback object or a comment ID.
 *
 * @return bool
 */
function is_feedback( $comment ) {
	if ( $comment instanceof Feedback ) {
		return true;
	}

	$comment = get_comment( $comment );

	if ( $comment && COMMENT_TYPE === $comment->comment_type ) {
		return true;
	}

	return false;
}

/**
 * Get a single feedback comment as a Feedback object.
 *
 * @param WP_Comment|Feedback|string|int $comment A comment/feedback object or a comment ID.
 *
 * @return Feedback|null A Feedback object, or null if the input is not a feedback comment.
 */
function get_feedback_comment( $comment ) {
	if ( $comment instanceof Feedback ) {
		return $comment;
	}

	$comment = get_comment( $comment );

	if ( ! is_feedback( $comment ) ) {
		return null;
	}

	return new Feedback( $comment );
}

/**
 * Add a new feedback submission.
 *
 * @param int       $post_id         The ID of the post to attach the feedback to.
 * @param array|int $feedback_author Either an array containing 'name' and 'email' values, or a user ID.
 * @param array     $feedback_meta   An associative array of key => value pairs.
 *
 * @return int|bool Comment ID on success. `false` on failure.
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
 * @param string|int $comment_id    The ID of the comment to update.
 * @param array      $feedback_meta An associative array of key => value pairs.
 *
 * @return int This will always return `0` because the comment itself does not get updated, only comment meta.
 */
function update_feedback( $comment_id, array $feedback_meta ) {
	$args = array(
		'comment_ID'   => $comment_id,
		'comment_meta' => $feedback_meta,
	);

	return wp_update_comment( $args );
}

/**
 * Retrieve a list of feedback submissions.
 *
 * @param array $post__in   Optional. An array of post IDs whose feedback comments should be included. An empty array
 *                          will include all posts. Default empty.
 * @param array $status     Optional. An array of statuses to include in the results.
 * @param array $meta_query Optional. A valid `WP_Meta_Query` array.
 *
 * @return array A collection of WP_Comment objects.
 */
function get_feedback( array $post__in = array(), array $status = array( 'hold', 'approve' ), array $meta_query = array() ) {
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

	$comments = array_map( __NAMESPACE__ . '\get_feedback_comment', $comments );

	return $comments;
}

/**
 * Trash a feedback submission.
 *
 * @param string|int $comment_id   The ID of the comment to delete.
 * @param bool       $force_delete Whether to bypass trash and force deletion. Default is false.
 *
 * @return bool
 */
function delete_feedback( $comment_id, $force_delete = false ) {
	return wp_delete_comment( $comment_id, $force_delete );
}
