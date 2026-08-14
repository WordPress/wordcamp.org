<?php
/**
 * Email notifications for the group-ownership transfer workflow.
 *
 * Plain-text `wp_mail()` only, matching `Groups\Messaging\send_message()` —
 * no templating system. There are no magic-link tokens: accept/decline/
 * approve are all gated by the acting user's logged-in identity (via REST or
 * the Network Admin screen), so these emails just point people at the
 * group's home URL rather than carrying any auth of their own.
 *
 * Kept as its own file, separate from the state machine in
 * `group-ownership-transfer.php`, mirroring how `wporg-groups-frontend`
 * keeps `inc/notifications.php` separate from the controllers it supports.
 *
 * Only loaded on the Groups network, via
 * `load-other-mu-plugins.php::wcorg_include_network_only_plugins()`.
 *
 * @package WordCamp\Groups
 */

namespace WordCamp\Groups\Ownership_Transfer\Notifications;

use WordCamp\Groups\Ownership_Transfer as Transfer;

defined( 'WPINC' ) || die();

add_action( 'wporg_groups_ownership_transfer_initiated', __NAMESPACE__ . '\notify_candidate_nominated', 10, 2 );
add_action( 'wporg_groups_ownership_transfer_accepted', __NAMESPACE__ . '\notify_admins_awaiting_approval', 10, 2 );
add_action( 'wporg_groups_ownership_transfer_declined', __NAMESPACE__ . '\notify_initiator_declined', 10, 2 );
add_action( 'wporg_groups_ownership_transfer_cancelled', __NAMESPACE__ . '\notify_candidate_cancelled', 10, 2 );
add_action( 'wporg_groups_ownership_transfer_executed', __NAMESPACE__ . '\notify_parties_executed', 10, 2 );
add_action( 'wporg_groups_ownership_transfer_rejected', __NAMESPACE__ . '\notify_parties_rejected', 10, 3 );

/**
 * Get every network admin's email address and display name.
 *
 * @return array<int, array{email: string, name: string}>
 */
function get_network_admin_emails(): array {
	$recipients = array();

	// `site_admins` is shared, mutable network state; a plugin or a stale
	// `update_site_option()` call elsewhere on the network can leave it in a
	// shape `get_super_admins()` doesn't expect. Cast defensively rather
	// than let a malformed option value turn a missed approval-notice email
	// into a fatal error.
	foreach ( (array) get_super_admins() as $login ) {
		$user = get_user_by( 'login', $login );

		if ( $user && is_email( $user->user_email ) ) {
			$recipients[] = array(
				'email' => $user->user_email,
				'name'  => $user->display_name,
			);
		}
	}

	return $recipients;
}

/**
 * Send a single plain-text email.
 *
 * @param array{email: string, name: string} $recipient Recipient.
 * @param string                             $subject   Message subject.
 * @param string                             $body      Message body.
 * @return bool Whether the mail was handed off successfully.
 */
function send_notification( array $recipient, string $subject, string $body ): bool {
	if ( ! is_email( $recipient['email'] ) ) {
		return false;
	}

	$to = $recipient['name']
		? sprintf( '%s <%s>', $recipient['name'], $recipient['email'] )
		: $recipient['email'];

	// Passed as an array, exactly as in `Groups\Messaging\send_message()`, so a
	// display name containing a comma can't smuggle in an extra recipient.
	$sent = wp_mail(
		array( $to ),
		$subject,
		$body,
		array( 'Content-Type: text/plain; charset=UTF-8' )
	);

	if ( ! $sent ) {
		trigger_error(
			sprintf(
				'Failed to send an ownership-transfer notification ("%1$s") to %2$s.',
				$subject, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Not HTML output; relayed to Slack by this repo's error handler.
				$recipient['email'] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ditto.
			),
			E_USER_WARNING
		);
	}

	return $sent;
}

/**
 * Build the `{name, email}` pair for a user, for use with `send_notification()`.
 *
 * @param int $user_id User ID.
 * @return array{email: string, name: string}|null
 */
function recipient_for_user( int $user_id ): ?array {
	$user = get_userdata( $user_id );

	if ( ! $user || ! is_email( $user->user_email ) ) {
		return null;
	}

	return array(
		'email' => $user->user_email,
		'name'  => $user->display_name,
	);
}

/**
 * Notify the candidate that they've been nominated.
 */
function notify_candidate_nominated( int $site_id, array $pending ): void {
	$recipient = recipient_for_user( (int) $pending['to_user_id'] );

	if ( ! $recipient ) {
		return;
	}

	$group_name = get_blog_option( $site_id, 'blogname' );
	$group_url  = get_home_url( $site_id, '/' );

	send_notification(
		$recipient,
		sprintf( __( 'You\'ve been nominated to take over "%s"', 'wordcamporg' ), $group_name ),
		sprintf(
			/* translators: 1: group name, 2: group URL. */
			__( "You've been nominated to become the owner of the WordPress group \"%1\$s\".\n\nTo accept or decline, visit the group's Members settings:\n%2\$s", 'wordcamporg' ),
			$group_name,
			$group_url
		)
	);
}

/**
 * Notify network admins that a transfer needs their approval.
 */
function notify_admins_awaiting_approval( int $site_id, array $pending ): void {
	$group_name = get_blog_option( $site_id, 'blogname' );
	$from_user  = get_userdata( (int) $pending['from_user_id'] );
	$to_user    = get_userdata( (int) $pending['to_user_id'] );

	$body = sprintf(
		/* translators: 1: group name, 2: current owner, 3: nominated owner, 4: URL of the approval screen. */
		__( "A group ownership transfer is awaiting your approval.\n\nGroup: %1\$s\nCurrent owner: %2\$s\nNominated owner: %3\$s\n\nReview it here:\n%4\$s", 'wordcamporg' ),
		$group_name,
		$from_user ? $from_user->display_name : __( '(deleted user)', 'wordcamporg' ),
		$to_user ? $to_user->display_name : __( '(deleted user)', 'wordcamporg' ),
		network_admin_url( 'admin.php?page=' . Transfer\MENU_SLUG )
	);

	foreach ( get_network_admin_emails() as $recipient ) {
		send_notification(
			$recipient,
			sprintf( __( 'Approval needed: ownership transfer for "%s"', 'wordcamporg' ), $group_name ),
			$body
		);
	}
}

/**
 * Notify the initiator that the candidate declined.
 */
function notify_initiator_declined( int $site_id, array $pending ): void {
	$recipient = recipient_for_user( (int) $pending['initiated_by'] );

	if ( ! $recipient ) {
		return;
	}

	$group_name = get_blog_option( $site_id, 'blogname' );

	send_notification(
		$recipient,
		sprintf( __( 'Ownership transfer declined for "%s"', 'wordcamporg' ), $group_name ),
		sprintf(
			/* translators: %s: group name. */
			__( 'The candidate you nominated to take over "%s" has declined the transfer.', 'wordcamporg' ),
			$group_name
		)
	);
}

/**
 * Notify the candidate that their pending nomination was cancelled.
 */
function notify_candidate_cancelled( int $site_id, array $pending ): void {
	$recipient = recipient_for_user( (int) $pending['to_user_id'] );

	if ( ! $recipient ) {
		return;
	}

	$group_name = get_blog_option( $site_id, 'blogname' );

	send_notification(
		$recipient,
		sprintf( __( 'Ownership transfer cancelled for "%s"', 'wordcamporg' ), $group_name ),
		sprintf(
			/* translators: %s: group name. */
			__( 'Your nomination to take over "%s" has been cancelled.', 'wordcamporg' ),
			$group_name
		)
	);
}

/**
 * Notify both parties that the transfer executed.
 */
function notify_parties_executed( int $site_id, array $pending ): void {
	$group_name = get_blog_option( $site_id, 'blogname' );
	$group_url  = get_home_url( $site_id, '/' );

	$body = sprintf(
		/* translators: 1: group name, 2: group URL. */
		__( "Ownership of \"%1\$s\" has been transferred.\n\n%2\$s", 'wordcamporg' ),
		$group_name,
		$group_url
	);

	foreach ( array( $pending['from_user_id'], $pending['to_user_id'] ) as $user_id ) {
		$recipient = recipient_for_user( (int) $user_id );

		if ( $recipient ) {
			send_notification(
				$recipient,
				sprintf( __( 'Ownership transfer completed for "%s"', 'wordcamporg' ), $group_name ),
				$body
			);
		}
	}
}

/**
 * Notify both parties that a network admin rejected the transfer.
 */
function notify_parties_rejected( int $site_id, array $pending, string $reason ): void {
	$group_name = get_blog_option( $site_id, 'blogname' );

	$body = sprintf(
		/* translators: 1: group name, 2: optional reason. */
		__( 'The proposed ownership transfer for "%1$s" was rejected by a network admin.%2$s', 'wordcamporg' ),
		$group_name,
		$reason ? "\n\n" . sprintf( /* translators: %s: reason given. */ __( 'Reason: %s', 'wordcamporg' ), $reason ) : ''
	);

	foreach ( array( $pending['from_user_id'], $pending['to_user_id'] ) as $user_id ) {
		$recipient = recipient_for_user( (int) $user_id );

		if ( $recipient ) {
			send_notification(
				$recipient,
				sprintf( __( 'Ownership transfer rejected for "%s"', 'wordcamporg' ), $group_name ),
				$body
			);
		}
	}
}
