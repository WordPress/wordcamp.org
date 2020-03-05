<?php

namespace WordCamp\SpeakerFeedback\Capabilities;

use const WordCamp\SpeakerFeedback\Comment\COMMENT_TYPE;
use function WordCamp\SpeakerFeedback\Comment\get_feedback_comment;

defined( 'WPINC' ) || die();

add_filter( 'map_meta_cap', __NAMESPACE__ . '\map_meta_caps', 10, 4 );
add_filter( 'rest_comment_query', __NAMESPACE__ . '\exclude_feedback_from_rest_requests', 99 );

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
			$feedback = get_feedback_comment( $context );
			if ( is_null( $feedback ) ) {
				$required_caps[] = 'do_not_allow';
				break;
			}

			// Current user is a speaker for the session receiving feedback comments.
			$session_speakers = array_map( 'absint', (array) get_post_meta( $feedback->comment_post_ID, '_wcpt_speaker_id' ) );
			if ( in_array( $user_id, $session_speakers, true ) ) {
				$required_caps[] = 'read';

				// The speaker can only read approved comments.
				if ( ! in_array( $feedback->comment_approved, array( 1, '1', 'approve' ), true ) ) {
					$required_caps[] = 'do_not_allow';
				}
				break;
			}

			// Current user has a role on the site that allows them to edit content.
			$required_caps = map_meta_cap( 'edit_comment', $user_id, $feedback->comment_ID );
			break;

		case 'edit_' . COMMENT_TYPE:
		case 'moderate_' . COMMENT_TYPE:
			$feedback = get_feedback_comment( $context );
			if ( is_null( $feedback ) ) {
				$required_caps[] = 'do_not_allow';
				break;
			}

			// Current user has a role on the site that allows them to edit content.
			$required_caps = map_meta_cap( 'edit_comment', $user_id, $feedback->comment_ID );
			break;
	}

	if ( ! empty( $required_caps ) ) {
		return $required_caps;
	}

	return $user_caps;
}

/**
 * Requests to the Core comment endpoint should not return feedback comments.
 *
 * @param array $args
 *
 * @return array
 */
function exclude_feedback_from_rest_requests( $args ) {
	if ( ! isset( $args['type__not_in'] ) ) {
		$args['type__not_in'] = array();
	}

	$args['type__not_in'][] = COMMENT_TYPE;

	return $args;
}
