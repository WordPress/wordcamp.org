<?php

namespace WordCamp\SpeakerFeedback\Capabilities;

use WP_User;
use function WordCamp\SpeakerFeedback\Comment\get_feedback_comment;
use function WordCamp\SpeakerFeedback\Post\get_session_speaker_user_ids;
use const WordCamp\SpeakerFeedback\Comment\COMMENT_TYPE;

defined( 'WPINC' ) || die();

add_filter( 'map_meta_cap', __NAMESPACE__ . '\map_meta_caps', 10, 4 );
add_filter( 'user_has_cap', __NAMESPACE__ . '\add_caps', 10, 4 );

/**
 * Determine capabilities needed for various feedback operations.
 *
 * @param array  $user_caps
 * @param string $current_cap
 * @param int    $user_id
 * @param array  $args
 *
 * @return array
 */
function map_meta_caps( $user_caps, $current_cap, $user_id, $args = array() ) {
	$required_caps = array();
	$context       = $args[0] ?? null;

	switch ( $current_cap ) {
		case 'read_' . COMMENT_TYPE:
			// Context is a comment ID or object.
			$feedback = get_feedback_comment( $context );
			if ( is_null( $feedback ) ) {
				$required_caps[] = 'do_not_allow';
				break;
			}

			// Current user is a speaker for the session receiving feedback comments.
			$session_speakers = get_session_speaker_user_ids( $feedback->comment_post_ID );
			if ( in_array( $user_id, $session_speakers, true ) ) {
				$required_caps[] = $current_cap;

				// The speaker can only read approved comments, unless they already can edit them.
				$can_edit = user_can( $user_id, 'edit_comment', $feedback->comment_ID );
				if ( ! $can_edit && ! in_array( $feedback->comment_approved, array( 1, '1', 'approve' ), true ) ) {
					$required_caps[] = 'do_not_allow';
				}
				break;
			}

			// Current user has a role on the site that allows them to edit the feedback comment.
			$required_caps = map_meta_cap( 'edit_comment', $user_id, $feedback->comment_ID );
			break;

		case 'read_post_' . COMMENT_TYPE:
			// Context is a post ID or object.
			$post = get_post( $context );
			if ( is_null( $post ) ) {
				$required_caps[] = 'do_not_allow';
				break;
			}

			$post_type = get_post_type( $post );
			if ( ! post_type_supports( $post_type, 'wordcamp-speaker-feedback' ) ) {
				$required_caps[] = 'do_not_allow';
				break;
			}

			// Current user is a speaker for the session receiving feedback comments.
			$session_speakers = get_session_speaker_user_ids( $post->ID );
			if ( in_array( $user_id, $session_speakers, true ) ) {
				$required_caps[] = $current_cap;
				break;
			}

			// Current user has a role on the site that allows them to edit the post.
			$required_caps = map_meta_cap( 'edit_post', $user_id, $post->ID );
			break;

		case 'moderate_' . COMMENT_TYPE:
			$required_caps[] = 'edit_others_posts';
			break;
	}

	if ( ! empty( $required_caps ) ) {
		return $required_caps;
	}

	return $user_caps;
}

/**
 * Add new capabilities to a user for some feedback operations.
 *
 * @param array   $allcaps The original list of caps for the given user.
 * @param array   $caps    Unused.
 * @param array   $args    Arguments that accompany the requested capability check.
 * @param WP_User $user    The user object.
 *
 * @return array The modified list of caps for the given user.
 */
function add_caps( $allcaps, $caps, $args, $user ) {
	$requested_cap = $args[0] ?? null;
	$context       = $args[2] ?? null;

	switch ( $requested_cap ) {
		case 'read_' . COMMENT_TYPE:
			// Context is a comment ID or object.
			$feedback = get_feedback_comment( $context );
			if ( is_null( $feedback ) ) {
				break;
			}

			// Current user is a speaker for the session receiving feedback comments.
			$session_speakers = get_session_speaker_user_ids( $feedback->comment_post_ID );
			if ( in_array( $user->ID, $session_speakers, true ) ) {
				$allcaps[ $requested_cap ] = true;
			}
			break;

		case 'read_post_' . COMMENT_TYPE:
			// Context is a post ID or object.
			$post = get_post( $context );
			if ( is_null( $post ) ) {
				break;
			}

			// Current user is a speaker for the session receiving feedback comments.
			$session_speakers = get_session_speaker_user_ids( $post->ID );
			if ( in_array( $user->ID, $session_speakers, true ) ) {
				$allcaps[ $requested_cap ] = true;
			}
			break;
	}

	return $allcaps;
}
