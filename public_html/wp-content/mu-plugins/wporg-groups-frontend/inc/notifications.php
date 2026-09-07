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
 * The GatherPress user meta key this file scopes per group.
 *
 * GatherPress stores event-update opt-in as a single, network-wide user
 * meta value (`wp_usermeta` isn't per-site), so toggling it on one group's
 * site changes it on every group the member belongs to. The filters below
 * redirect reads/writes of this key to a key namespaced by blog ID instead,
 * so the preference can differ per group.
 */
const GATHERPRESS_OPT_IN_META_KEY = 'gatherpress_event_updates_opt_in';

/**
 * Register notification hooks.
 */
function bootstrap(): void {
	add_action( 'transition_post_status', __NAMESPACE__ . '\schedule_new_event_notification', 10, 3 );
	add_filter( 'get_user_metadata', __NAMESPACE__ . '\scope_opt_in_read_to_current_group', 10, 4 );
	add_filter( 'update_user_metadata', __NAMESPACE__ . '\scope_opt_in_write_to_current_group', 10, 4 );
}

/**
 * The per-group user meta key for a given site.
 *
 * @param int|null $blog_id Site ID. Defaults to the current site.
 * @return string
 */
function get_scoped_opt_in_meta_key( ?int $blog_id = null ): string {
	return '_wporg_groups_event_updates_opt_in_' . ( $blog_id ?? get_current_blog_id() );
}

/**
 * Read the event-updates opt-in from this group's own meta, when it's been set.
 *
 * Falls through to GatherPress's own (network-wide) value -- by returning
 * the untouched `$value` so `get_metadata()` proceeds with its normal
 * lookup -- until a member makes an explicit choice on this group's site,
 * so existing opt-outs aren't silently reset to the default.
 *
 * @param mixed  $value    The filtered meta value. A non-null value means an earlier
 *                         `get_user_metadata` callback already short-circuited the read,
 *                         which this filter must not override.
 * @param int    $object_id User ID.
 * @param string $meta_key  Meta key being read.
 * @param bool   $single    Whether to return a single value.
 * @return mixed
 */
function scope_opt_in_read_to_current_group( $value, int $object_id, string $meta_key, bool $single ) {
	if ( GATHERPRESS_OPT_IN_META_KEY !== $meta_key || null !== $value ) {
		return $value;
	}

	$scoped_key = get_scoped_opt_in_meta_key();

	if ( ! metadata_exists( 'user', $object_id, $scoped_key ) ) {
		return $value;
	}

	$scoped_value = get_user_meta( $object_id, $scoped_key, true );

	return $single ? $scoped_value : array( $scoped_value );
}

/**
 * Redirect writes of the opt-in to this group's own meta key.
 *
 * Returning a non-null value here short-circuits `update_metadata()`, so
 * GatherPress's own network-wide meta key is never touched from a group
 * site -- each group keeps an independent value.
 *
 * @param mixed  $check     Whether to short-circuit the write. A non-null value means an
 *                          earlier `update_user_metadata` callback already handled it, and
 *                          this filter must not write again.
 * @param int    $object_id User ID.
 * @param string $meta_key  Meta key being written.
 * @param mixed  $meta_value New value.
 * @return mixed
 */
function scope_opt_in_write_to_current_group( $check, int $object_id, string $meta_key, $meta_value ) {
	if ( GATHERPRESS_OPT_IN_META_KEY !== $meta_key || null !== $check ) {
		return $check;
	}

	return update_user_meta( $object_id, get_scoped_opt_in_meta_key(), $meta_value );
}

/**
 * Queue the all-members email when an event is first published.
 *
 * Only records the event: `transition_post_status` fires inside
 * `wp_insert_post()`, before the event's datetimes/venue/image are written,
 * so sending here would render an email without them. The send happens on
 * late `shutdown`. An already-published event does not queue another message
 * when it is edited.
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

	pending_notification_queue( $post->ID );
	add_action( 'shutdown', __NAMESPACE__ . '\send_pending_new_event_notifications', PHP_INT_MAX );
}

/**
 * Event IDs queued for this request's shutdown send.
 *
 * @param int|null $enqueue_id Event ID to add, if any.
 * @param bool     $drain      When true, empty the queue and return what it held.
 * @return int[]
 */
function pending_notification_queue( ?int $enqueue_id = null, bool $drain = false ): array {
	static $pending = array();

	if ( null !== $enqueue_id ) {
		$pending[ $enqueue_id ] = $enqueue_id;
	}

	$queued = array_values( $pending );

	if ( $drain ) {
		$pending = array();
	}

	return $queued;
}

/**
 * Send the queued first-publish notifications.
 *
 * This intentionally delegates recipient resolution, per-user opt-in checks,
 * email rendering, and delivery to GatherPress's own `Rest_Api::send_emails()`
 * (the same method its "Message all members" REST action and its
 * `gatherpress_send_emails` cron handler both call).
 *
 * Runs at PHP_INT_MAX because GatherPress resolves wp-admin datetime meta in
 * its own default-priority `shutdown` callback, and the email must render
 * after that.
 */
function send_pending_new_event_notifications(): void {
	// `all => true` reuses GatherPress's existing "Message all members"
	// path (`Rest_Api::get_recipients()`), which resolves recipients via
	// an unbatched `get_users()` and emails each one synchronously in the
	// same request. That's an existing GatherPress-level constraint, not
	// something this hook can fix, but it now also runs on every
	// automatic first-publish rather than only when an organizer
	// deliberately clicks "Message all members".
	$recipients = array(
		'all'           => true,
		'attending'     => false,
		'waiting_list'  => false,
		'not_attending' => false,
	);

	foreach ( pending_notification_queue( null, true ) as $event_id ) {
		if ( 'publish' !== get_post_status( $event_id ) ) {
			continue;
		}

		$datetime = ( new \GatherPress\Core\Event\Event( $event_id ) )->get_datetime();

		if ( empty( $datetime['datetime_start_gmt'] ) ) {
			trigger_error(
				sprintf(
					'Skipped the publish notification for event %d -- it has no start datetime. Members were not notified.',
					$event_id // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Not HTML output; an internal log message this repo's error handler (0-error-handling.php) relays to Slack.
				),
				E_USER_WARNING
			);
			continue;
		}

		// Deliberately not `wp_schedule_single_event() + gatherpress_send_emails`
		// (GatherPress's own dispatch path for this): that hands off through
		// WordPress core's single-option cron store, and every
		// `wp_schedule_single_event()` call anywhere on the site does an
		// unsynchronized read-modify-write of that same option -- two events
		// publishing close together (plausible in real usage, and something
		// this repo's own E2E suite reliably triggers under parallel test
		// execution) can silently clobber each other's job at any point up
		// until wp-cron actually gets around to running it, with no error
		// anywhere. A bounded retry around the scheduling call alone can't
		// close this, since the vulnerable window is "until cron executes
		// it", not "until scheduling is confirmed". Calling the same handler
		// GatherPress's own cron action would have called, directly and
		// synchronously, sidesteps that store entirely -- at the cost of the
		// publish request blocking briefly on sending mail, which is already
		// true of GatherPress's own "Message all members" action once cron
		// picks it up.
		$sent = \GatherPress\Core\Event\Rest_Api::get_instance()->send_emails( $event_id, $recipients, '' );

		if ( $sent ) {
			update_post_meta( $event_id, PUBLISH_NOTIFICATION_SCHEDULED_META, 1 );
			continue;
		}

		trigger_error(
			sprintf(
				'Failed to send the publish notification for event %d -- `Rest_Api::send_emails()` returned false. Members were not notified.',
				$event_id // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Not HTML output; an internal log message this repo's error handler (0-error-handling.php) relays to Slack.
			),
			E_USER_WARNING
		);
	}
}
