<?php
/**
 * Automatic event notifications.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\Notifications;

defined( 'WPINC' ) || die();

const PUBLISH_NOTIFICATION_SCHEDULED_META = '_wporg_groups_event_publish_notification_scheduled';

/**
 * Register notification hooks.
 */
function bootstrap(): void {
	add_action( 'transition_post_status', __NAMESPACE__ . '\schedule_new_event_notification', 10, 3 );
}

/**
 * Schedule GatherPress's existing all-members email when an event is first published.
 *
 * This intentionally delegates recipient resolution, per-user opt-in checks,
 * email rendering, and delivery to GatherPress's `gatherpress_send_emails`
 * action. An already-published event does not schedule another message when
 * it is edited.
 *
 * @param string   $new_status New post status.
 * @param string   $old_status Previous post status.
 * @param \WP_Post $post       Post being transitioned.
 */
function schedule_new_event_notification( string $new_status, string $old_status, \WP_Post $post ): void {
	if (
		'publish' !== $new_status ||
		'publish' === $old_status ||
		'gatherpress_event' !== $post->post_type ||
		get_post_meta( $post->ID, PUBLISH_NOTIFICATION_SCHEDULED_META, true )
	) {
		return;
	}

	$recipients = array(
		'all'           => true,
		'attending'     => false,
		'waiting_list'  => false,
		'not_attending' => false,
	);

	$scheduled = wp_schedule_single_event(
		time(),
		'gatherpress_send_emails',
		array( $post->ID, $recipients, '' )
	);

	if ( $scheduled ) {
		update_post_meta( $post->ID, PUBLISH_NOTIFICATION_SCHEDULED_META, 1 );
	}
}
