<?php
/**
 * Server-side rendering for the wporg/group-membership block.
 *
 * The block renders a set of independently toggleable parts, so the same
 * interactivity store and REST wiring can back placements that answer quite
 * different questions:
 *
 *   - `showIdentity` (default true) — the join button for visitors, or the
 *     role badge plus member count for members. Answers "what is this group
 *     and am I in it?", which belongs with the group's supporting details.
 *   - `showLeave` — the "Leave group" action. Destructive account management,
 *     not identity, so it sits with the member directory rather than the hero.
 *   - `showPreference` — the GatherPress event-email opt-in. Stored as
 *     `gatherpress_event_updates_opt_in` usermeta, which on multisite is
 *     shared across the whole install: this toggle is not scoped to the group
 *     rendering it. It therefore belongs with the member controls rather
 *     than anywhere that reads as a property of this particular group.
 *   - `showHeadings` (default false) — labels the rendered membership and
 *     preference sections. When identity is hidden, the preference receives
 *     a standalone section heading instead of a subordinate heading.
 *
 * @package WordCamp\Groups\Frontend
 */

$show_identity   = ! isset( $attributes['showIdentity'] ) || ! empty( $attributes['showIdentity'] );
$show_leave      = ! isset( $attributes['showLeave'] ) || ! empty( $attributes['showLeave'] );
$show_preference = ! isset( $attributes['showPreference'] ) || ! empty( $attributes['showPreference'] );
$show_headings   = ! empty( $attributes['showHeadings'] );

$is_logged_in = is_user_logged_in();
$is_member    = $is_logged_in && is_user_member_of_blog();
$user_role    = '';
$role_label   = '';

if ( $is_member ) {
	$user      = wp_get_current_user();
	$user_role = reset( $user->roles ) ?: 'subscriber';

	$labels = array(
		'administrator' => __( 'Organiser', 'wporg-groups-frontend' ),
		'editor'        => __( 'Organiser', 'wporg-groups-frontend' ),
		'author'        => __( 'Event Organiser', 'wporg-groups-frontend' ),
	);

	$role_label = $labels[ $user_role ] ?? __( 'Member', 'wporg-groups-frontend' );
}

// Only logged-in visitors can join or leave, and only their markup should carry a nonce.
$rest_nonce = $is_logged_in ? wp_create_nonce( 'wp_rest' ) : '';

$is_organiser = in_array( $user_role, array( 'administrator', 'editor' ), true );

/*
 * Bail before rendering a wrapper that would hold nothing. Every part except
 * the identity row is member-only, so a placement that asks for the leave
 * action or the email preference alone has nothing to show a logged-out
 * visitor. Returning here also skips the `count_users()` call below, which is
 * only needed for the member count in the identity row.
 */
$renders_leave      = $show_leave && $is_member && ! $is_organiser;
$renders_preference = $show_preference && $is_member;

if ( ! $show_identity && ! $renders_leave && ! $renders_preference ) {
	return;
}

$user_count          = count_users( 'time', get_current_blog_id() );
$member_count        = $user_count['total_users'] ?? 0;
$join_api            = rest_url( 'wporg-groups/v1/members/join' );
$leave_api           = rest_url( 'wporg-groups/v1/members/leave' );
$preference_api      = rest_url( 'wporg-groups/v1/members/notification-preference' );
$login_url           = wp_login_url( get_permalink() ?: home_url() );
$notification_opt_in = $is_member
	? \GatherPress\Core\User::get_instance()->has_event_updates_opt_in( get_current_user_id() )
	: false;
$count_label         = sprintf(
	_n( '%s member', '%s members', $member_count, 'wporg-groups-frontend' ),
	number_format_i18n( $member_count )
);

$context = array(
	'isLoggedIn'    => $is_logged_in,
	'isMember'      => $is_member,
	'isOrganiser'   => $is_organiser,
	'roleLabel'     => $role_label,
	'memberCount'   => $member_count,
	'memberLabel'   => __( 'Member', 'wporg-groups-frontend' ),
	'joinLabel'     => __( 'Join this group', 'wporg-groups-frontend' ),
	'countLabel'    => $count_label,
	'leaveConfirm' => __( 'Leave this group?', 'wporg-groups-frontend' ),
	'joinApi'       => $join_api,
	'leaveApi'      => $leave_api,
	'preferenceApi' => $preference_api,
	'restNonce'     => $rest_nonce,
	'loginUrl'      => $login_url,
	'loading'       => false,
	'notificationOptIn'     => $notification_opt_in,
	'preferenceSaving'      => false,
	'preferenceMessage'     => '',
	'preferenceNoticeSuccess' => false,
	'preferenceNoticeError'   => false,
	'preferenceSavedLabel'  => __( 'Email preference saved.', 'wporg-groups-frontend' ),
	'preferenceErrorLabel'  => __( 'The email preference could not be saved. Please try again.', 'wporg-groups-frontend' ),
);

wp_interactivity_state(
	'wporg/group-membership',
	array(
		'buttonLabel'  => $is_member ? $role_label : __( 'Join this group', 'wporg-groups-frontend' ),
		'isMember'     => $is_member,
		'memberCount'  => $member_count,
		'countLabel'   => $count_label,
	)
);

$wrapper_attributes = get_block_wrapper_attributes(
	array(
		'data-wp-interactive' => 'wporg/group-membership',
		'data-wp-context'     => wp_json_encode( $context ),
		'class'               => 'wporg-group-membership',
	)
);
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( $show_identity ) : ?>
		<?php if ( $show_headings ) : ?>
			<h2 class="wporg-group-membership__heading">
				<?php esc_html_e( 'Membership', 'wporg-groups-frontend' ); ?>
			</h2>
		<?php endif; ?>

		<?php if ( $is_member ) : ?>
			<span class="wporg-group-membership__badge" data-wp-text="state.buttonLabel">
				<?php echo esc_html( $role_label ); ?>
			</span>
		<?php else : ?>
			<button
				class="wporg-group-membership__join wp-element-button"
				data-wp-on--click="actions.join"
				data-wp-text="state.buttonLabel"
				data-wp-bind--disabled="context.loading"
			><?php esc_html_e( 'Join this group', 'wporg-groups-frontend' ); ?></button>
		<?php endif; ?>

		<span class="wporg-group-membership__count" data-wp-text="state.countLabel">
			<?php
			echo esc_html(
				sprintf(
					_n( '%s member', '%s members', $member_count, 'wporg-groups-frontend' ),
					number_format_i18n( $member_count )
				)
			);
			?>
		</span>
	<?php endif; ?>

	<?php if ( $renders_leave ) : ?>
		<button
			class="wporg-group-membership__leave"
			data-wp-on--click="actions.leave"
			data-wp-bind--disabled="context.loading"
		><?php esc_html_e( 'Leave group', 'wporg-groups-frontend' ); ?></button>
	<?php endif; ?>

	<?php if ( $renders_preference ) : ?>
		<?php if ( $show_headings ) : ?>
			<?php if ( $show_identity ) : ?>
				<h3 class="wporg-group-membership__preference-heading">
					<?php esc_html_e( 'Email preferences', 'wporg-groups-frontend' ); ?>
				</h3>
			<?php else : ?>
				<h2 class="wporg-group-membership__preference-heading wporg-group-membership__preference-heading--standalone">
					<?php esc_html_e( 'Email preferences', 'wporg-groups-frontend' ); ?>
				</h2>
			<?php endif; ?>
		<?php endif; ?>

		<div class="wporg-group-membership__preference">
			<label>
				<input
					type="checkbox"
					<?php checked( $notification_opt_in ); ?>
					data-wp-on--change="actions.updateNotificationPreference"
					data-wp-bind--checked="context.notificationOptIn"
					data-wp-bind--disabled="context.preferenceSaving"
				/>
				<span><?php esc_html_e( 'Email me updates and information about events from organisers.', 'wporg-groups-frontend' ); ?></span>
			</label>
			<span class="wporg-group-membership__preference-help">
				<?php esc_html_e( 'This preference applies to all your groups.', 'wporg-groups-frontend' ); ?>
			</span>
			<span
				class="wporg-group-membership__preference-status"
				role="status"
				aria-live="polite"
				data-wp-text="context.preferenceMessage"
				data-wp-class--is-success="context.preferenceNoticeSuccess"
				data-wp-class--is-error="context.preferenceNoticeError"
			></span>
		</div>
	<?php endif; ?>
</div>
