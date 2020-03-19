<?php

namespace WordCamp\SpeakerFeedback\Capabilities;

use const WordCamp\SpeakerFeedback\Comment\COMMENT_TYPE;
use function WordCamp\SpeakerFeedback\Comment\{ get_feedback_comment };

defined( 'WPINC' ) || die();

add_filter( 'map_meta_cap', __NAMESPACE__ . '\map_meta_caps', 10, 4 );

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
			$session_speakers = array_map( 'absint', (array) get_post_meta( $feedback->comment_post_ID, '_wcpt_speaker_id' ) );
			if ( in_array( $user_id, $session_speakers, true ) ) {
				$required_caps[] = 'read';

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
			$session_speakers = array_map( 'absint', (array) get_post_meta( $post->ID, '_wcpt_speaker_id' ) );
			if ( in_array( $user_id, $session_speakers, true ) ) {
				$required_caps[] = 'read';
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
