<?php
/**
 * Organizers emailing every member of their group, not tied to an event (#2409).
 *
 * Plain-text `wp_mail()`, like `RSVP_Confirmation\send_email()`. Recipients are
 * the members with event emails turned on for this group, decided by
 * GatherPress's own opt-in check, which `Notifications` scopes per group.
 *
 * The send runs on cron in batches of `BATCH_SIZE` members, each batch
 * scheduling the next, so a group of thousands isn't mailed in one run. It
 * pages by member ID; someone joining or leaving mid-send can shift a batch
 * boundary by one member.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\Group_Message;

use GatherPress\Core\User;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

use function WordCamp\Groups\Frontend\RSVP_Confirmation\plain_text;
use const WordCamp\Groups\Frontend\REST\NAMESPACE_V1;

defined( 'WPINC' ) || die();

const CRON_HOOK = 'wporg_groups_send_group_message';

const BATCH_SIZE = 100;

/**
 * Hook the route and the cron job.
 */
function bootstrap(): void {
	add_action( 'rest_api_init', __NAMESPACE__ . '\register_routes' );
	add_action( CRON_HOOK, __NAMESPACE__ . '\send_batch', 10, 3 );
}

/**
 * Register `POST /wporg-groups/v1/group-message`, for organizers only.
 */
function register_routes(): void {
	register_rest_route(
		NAMESPACE_V1,
		'/group-message',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => __NAMESPACE__ . '\queue_message',
			'permission_callback' => 'WordCamp\Groups\Frontend\REST\manage_group_settings_permissions_check',
			'args'                => array(
				'subject' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => __NAMESPACE__ . '\sanitize_subject',
				),
				'message' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => __NAMESPACE__ . '\sanitize_plain_text',
				),
			),
		)
	);
}

/**
 * Clean a plain-text message without HTML-encoding it.
 *
 * The email is `text/plain` and nothing renders it as HTML, so `&`, `<3` or a
 * link with several query parameters must reach members as typed. The core
 * sanitizers (and `Event_Email\sanitize_message()`, built for GatherPress's HTML
 * template) encode entities and strip anything between `<` and `>`.
 *
 * @param mixed $text Raw text.
 * @return string
 */
function sanitize_plain_text( $text ): string {
	return is_string( $text ) ? trim( wp_check_invalid_utf8( $text ) ) : '';
}

/**
 * Clean a subject: plain text on one line, so it can't add mail headers.
 *
 * @param mixed $subject Raw subject.
 * @return string
 */
function sanitize_subject( $subject ): string {
	return trim( preg_replace( '/[\r\n\t]+/', ' ', sanitize_plain_text( $subject ) ) );
}

/**
 * Schedule the send, so a large group can't time out the request.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error
 */
function queue_message( WP_REST_Request $request ) {
	$subject = $request->get_param( 'subject' );
	$message = $request->get_param( 'message' );

	if ( '' === $subject || '' === $message ) {
		return new WP_Error(
			'empty_message',
			__( 'Add a subject and a message.', 'wordcamporg' ),
			array( 'status' => 400 )
		);
	}

	$scheduled = wp_schedule_single_event( time(), CRON_HOOK, array( $subject, $message, 0 ), true );

	if ( is_wp_error( $scheduled ) ) {
		return new WP_Error(
			'not_scheduled',
			__( 'The message could not be scheduled.', 'wordcamporg' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Fires after an organizer schedules a message to their whole group.
	 *
	 * @param int    $blog_id Group site ID.
	 * @param string $subject Message subject.
	 */
	do_action( 'wporg_groups_group_message_queued', get_current_blog_id(), $subject );

	return array( 'success' => true );
}

/**
 * Email one batch of members who have event emails turned on, then schedule the next batch.
 *
 * @param string $subject Subject the organizer wrote.
 * @param string $message Message the organizer wrote.
 * @param int    $offset  Members already handled by earlier batches.
 * @return int Number of emails handed to `wp_mail()`.
 */
function send_batch( string $subject, string $message, int $offset ): int {
	$users = get_users(
		array(
			'fields'  => array( 'ID', 'user_email', 'display_name' ),
			'orderby' => 'ID',
			'order'   => 'ASC',
			'number'  => BATCH_SIZE,
			'offset'  => $offset,
		)
	);

	if ( count( $users ) === BATCH_SIZE && ! wp_schedule_single_event( time(), CRON_HOOK, array( $subject, $message, $offset + BATCH_SIZE ) ) ) {
		trigger_error(
			sprintf(
				'Could not schedule the next batch of a group message on site %d; members after offset %d were not emailed.',
				get_current_blog_id(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Internal log message, not HTML output.
				$offset + BATCH_SIZE // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Internal log message, not HTML output.
			),
			E_USER_WARNING
		);
	}

	// One query for the batch's opt-in meta, instead of one per member.
	update_meta_cache( 'user', wp_list_pluck( $users, 'ID' ) );

	$body = $message . "\n\n-- \n" . sprintf(
		/* translators: %s: group name. */
		__( 'Sent by the organizers of %s.', 'wordcamporg' ),
		plain_text( get_bloginfo( 'name' ) )
	) . "\n" . sprintf(
		/* translators: %s: group home page URL. */
		__( 'To stop these emails, turn off event emails for this group: %s', 'wordcamporg' ),
		home_url( '/' )
	);

	$sent   = 0;
	$failed = 0;

	foreach ( $users as $user ) {
		if ( ! is_email( $user->user_email ) || ! User::get_instance()->has_event_updates_opt_in( (int) $user->ID ) ) {
			continue;
		}

		$to = $user->display_name ? sprintf( '%s <%s>', $user->display_name, $user->user_email ) : $user->user_email;

		// An array, as in `RSVP_Confirmation\send_email()`, so a comma in a display name can't add a recipient.
		if ( wp_mail( array( $to ), $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) ) ) {
			++$sent;
		} else {
			++$failed;
		}
	}

	if ( $failed ) {
		trigger_error(
			sprintf(
				'A group message on site %d failed to send to %d member(s) in the batch at offset %d.',
				get_current_blog_id(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Internal log message, not HTML output.
				$failed, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Internal log message, not HTML output.
				$offset // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Internal log message, not HTML output.
			),
			E_USER_WARNING
		);
	}

	return $sent;
}
