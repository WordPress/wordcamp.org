<?php
/**
 * Plugin Name: Notify WordCamp Central on pending posts
 * Plugin URI: http://wordcamp.org
 * Description: Send email notification to WordCamp Central when post status becomes pending.
 * Version: 1.0
 *
 * Heavily inspired from Pending Submission Notifications plugin by Razvan Horeanga.
 */

namespace Notify_Central_Pending_Posts;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Invalid request.' );
}

add_action( 'transition_post_status', __NAMESPACE__ . '\send_notification_email', 10, 3 );

/**
 * Send the notification email.
 *
 * @param string  $new_status New post status.
 * @param string  $old_status Old post status.
 * @param WP_Post $post       Post object.
 */
function send_notification_email( $new_status, $old_status, $post ) {
	if ( 'pending' === $new_status && user_can( $post->post_author, 'edit_posts' ) ) {
		// Prevent many emails from the same post.
		$sent = get_post_meta( $post->ID, '_ncpp_sent', true );
		if ( ! empty( $sent ) ) {
			return;
		}

		$edit_link    = get_edit_post_link( $post->ID, '' );
		$preview_link = get_permalink( $post->ID ) . '&preview=true';

		$username           = get_userdata( $post->post_author );
		$username_last_edit = get_the_modified_author();

		$subject = __( 'New post on WordCamp Central pending review', 'wordcamporg' ) . ": {$post->post_title}";

		$message  = __( 'Hello team! A new post on WordCamp Central is pending review.', 'wordcamporg' );
		$message .= "\r\n\r\n";
		$message .= __( 'Title' ) . ": {$post->post_title}\r\n";
		$message .= __( 'Author' ) . ": {$username->user_login}\r\n";
		$message .= "\r\n\r\n";
		$message .= __( 'Edit' ) . ": {$edit_link}\r\n";
		$message .= __( 'Preview' ) . ": {$preview_link}";

		wp_mail( 'support@wordcamp.org', $subject, $message );

		// Save a pointer that notification has been sent.
		update_post_meta( $post->ID, '_ncpp_sent', wp_date( 'Y-m-d H:i:s' ) );
	}
}
