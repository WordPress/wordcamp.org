<?php

namespace WordCamp\SpeakerFeedback\Page;

add_filter( 'pre_trash_post', __NAMESPACE__ . '\prevent_deletion', 10, 2 );
add_filter( 'pre_delete_post', __NAMESPACE__ . '\prevent_deletion', 10, 3 );
add_filter( 'display_post_states', __NAMESPACE__ . '\add_label_to_page', 10, 2 );

/**
 * Prevent deletion of the Feedback page.
 *
 * @param bool|null $check Whether to go forward with trashing/deletion.
 * @param WP_Post   $post  Post object.
 * @param bool      $force_delete Whether to bypass the trash, set when deactivating the plugin to clean up.
 */
function prevent_deletion( $check, $post, $force_delete = false ) {
	if ( $force_delete ) {
		return $check;
	}

	$feedback_page = (int) get_option( 'feedback_page' );
	if ( $feedback_page === $post->ID ) {
		return false;
	}

	return $check;
}

/**
 * Add label to the Feedback page, to indicate it's a "special" page.
 *
 * @param string[] $post_states An array of post display states.
 * @param WP_Post  $post        The current post object.
 *
 * @return array The filtered list of post display states.
 */
function add_label_to_page( $post_states, $post ) {
	$feedback_page = (int) get_option( 'feedback_page' );

	if ( $feedback_page === $post->ID ) {
		$post_states['sft-page'] = _x( 'Speaker Feedback', 'wordcamporg' );
	}

	return $post_states;
}
