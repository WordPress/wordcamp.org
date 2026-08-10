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

	// `all => true` reuses GatherPress's existing "Message all members"
	// path (`Event_Rest_Api::get_recipients()`), which resolves recipients
	// via an unbatched `get_users()` and emails each one synchronously in
	// the same request. That's an existing GatherPress-level constraint,
	// not something this hook can fix, but it now also runs on every
	// automatic first-publish rather than only when an organizer
	// deliberately clicks "Message all members".
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
		return;
	}

	trigger_error(
		sprintf(
			'Failed to schedule the publish notification for event %d -- `wp_schedule_single_event()` returned false. Members were not notified.',
			$post->ID // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Not HTML output; an internal log message this repo's error handler (0-error-handling.php) relays to Slack.
		),
		E_USER_WARNING
	);
}
