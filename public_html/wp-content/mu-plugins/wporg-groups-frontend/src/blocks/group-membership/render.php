<?php
/**
 * Server-side rendering for the wporg/group-membership block.
 *
 * @package WordCamp\Groups\Frontend
 */

use function WordCamp\Groups\Frontend\Capabilities\get_role_tier_label;
use const WordCamp\Groups\Frontend\Capabilities\EVENT_MANAGER_ROLES;
use const WordCamp\Groups\Frontend\Capabilities\ORGANIZER_ROLES;

$variant = $attributes['variant'] ?? 'combined';
if ( ! in_array( $variant, array( 'combined', 'membership', 'preference', 'count' ), true ) ) {
	$variant = 'combined';
}

$shows_membership = in_array( $variant, array( 'combined', 'membership' ), true );
$shows_preference = in_array( $variant, array( 'combined', 'preference' ), true );

// The standalone count lives in the hero's meta row next to the group
// location; the sidebar membership variant leaves it out so the number
// isn't stated twice on the same page.
$shows_count = in_array( $variant, array( 'combined', 'count' ), true );

$is_logged_in = is_user_logged_in();
$is_member    = $is_logged_in && is_user_member_of_blog();
$user_role    = '';
$role_label   = '';

if ( $shows_membership && $is_member ) {
	$user       = wp_get_current_user();
	$user_role  = reset( $user->roles ) ?: 'subscriber';
	$role_label = get_role_tier_label( $user_role );
}

$is_organizer       = in_array( $user_role, ORGANIZER_ROLES, true );
$renders_leave      = $shows_membership && $is_member && ! $is_organizer;
$renders_preference = $shows_preference && $is_member;

// Plain "Member" is the default for everyone who isn't an organizer or
// event organizer — badging it back at people who already know they've
// joined adds a status pill with nothing to say. Reserve the badge for
// roles the label actually distinguishes.
$renders_role_badge = $shows_membership && $is_member && in_array( $user_role, EVENT_MANAGER_ROLES, true );

if ( ! $shows_membership && ! $renders_preference && ! $shows_count ) {
	return;
}

// Only logged-in visitors can mutate membership or preferences, and only their markup should carry a nonce.
$rest_nonce = $is_logged_in ? wp_create_nonce( 'wp_rest' ) : '';

$member_count = 0;
$count_label  = '';
$members_url  = '';

// Gated on `$shows_count` alone. `count_users()` runs Core's CPU-intensive
// usermeta aggregate, and the group front page renders two of these blocks
// — the hero count and the sidebar membership controls — so tying this to
// `$shows_membership` as well ran that aggregate twice on every request for
// a number the membership variant no longer prints.
if ( $shows_count ) {
	$user_count   = count_users( 'time', get_current_blog_id() );
	$member_count = $user_count['total_users'] ?? 0;
	$count_label  = sprintf(
		_n( '%s member', '%s members', $member_count, 'wporg-groups-frontend' ),
		number_format_i18n( $member_count )
	);

	$members_page = get_page_by_path( 'members' );
	if ( $members_page && 'publish' === $members_page->post_status ) {
		$members_url = get_permalink( $members_page );
	}
}

$notification_opt_in = $renders_preference
	? \GatherPress\Core\User::get_instance()->has_event_updates_opt_in( get_current_user_id() )
	: false;

$context = array(
	'isLoggedIn' => $is_logged_in,
	'isMember'   => $is_member,
	'restNonce'  => $rest_nonce,
);

// The count markup reads its label through the store's context-backed
// getter, so every variant that prints a count needs these two keys.
if ( $shows_count ) {
	$context = array_merge(
		$context,
		array(
			'memberCount' => $member_count,
			'countLabel'  => $count_label,
		)
	);
}

if ( $shows_membership ) {
	$context = array_merge(
		$context,
		array(
			'roleLabel'    => $role_label,
			'memberLabel'  => __( 'Member', 'wporg-groups-frontend' ),
			'joinLabel'    => __( 'Join this group', 'wporg-groups-frontend' ),
			'leaveConfirm' => __( 'Leave this group?', 'wporg-groups-frontend' ),
			'joinApi'      => rest_url( 'wporg-groups/v1/members/join' ),
			'leaveApi'     => rest_url( 'wporg-groups/v1/members/leave' ),
			'loginUrl'     => wp_login_url( get_permalink() ?: home_url() ),
			'loading'      => false,
		)
	);
}

/*
 * The server-side directive processor can't run the store's JavaScript
 * getters, so it resolves `state.*` straight out of what's registered here.
 * Both halves matter and neither implies the other: without `buttonLabel`
 * the Join button and the role badge render with empty text until
 * hydration, and without `countLabel` the count does. Register each
 * alongside the markup that reads it.
 */
if ( $shows_membership || $shows_count ) {
	$interactivity_state = array( 'isMember' => $is_member );

	if ( $shows_membership ) {
		$interactivity_state['buttonLabel'] = $is_member
			? $role_label
			: __( 'Join this group', 'wporg-groups-frontend' );
	}

	if ( $shows_count ) {
		$interactivity_state['memberCount'] = $member_count;
		$interactivity_state['countLabel']  = $count_label;
	}

	wp_interactivity_state( 'wporg/group-membership', $interactivity_state );
}

if ( $renders_preference ) {
	$context = array_merge(
		$context,
		array(
			'preferenceApi'           => rest_url( 'wporg-groups/v1/members/notification-preference' ),
			'notificationOptIn'       => $notification_opt_in,
			'preferenceSaving'        => false,
			'preferenceMessage'       => '',
			'preferenceNoticeSuccess' => false,
			'preferenceNoticeError'   => false,
			'preferenceSavedLabel'    => __( 'Email preference saved.', 'wporg-groups-frontend' ),
			'preferenceErrorLabel'    => __( 'The email preference could not be saved. Please try again.', 'wporg-groups-frontend' ),
		)
	);
}

$wrapper_attributes = get_block_wrapper_attributes(
	array(
		'data-wp-interactive' => 'wporg/group-membership',
		'data-wp-context'     => wp_json_encode( $context ),
		'class'               => 'wporg-group-membership',
	)
);
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( $shows_membership ) : ?>
		<?php if ( 'membership' === $variant ) : ?>
			<h2 class="wporg-group-membership__heading">
				<?php esc_html_e( 'Membership', 'wporg-groups-frontend' ); ?>
			</h2>
		<?php endif; ?>

		<?php if ( $is_member ) : ?>
			<?php if ( $renders_role_badge ) : ?>
				<span class="wporg-group-membership__badge" data-wp-text="state.buttonLabel">
					<?php echo esc_html( $role_label ); ?>
				</span>
			<?php endif; ?>
		<?php else : ?>
			<div class="wp-block-button">
				<button
					type="button"
					class="wp-block-button__link wp-element-button"
					data-wp-on--click="actions.join"
					data-wp-text="state.buttonLabel"
					data-wp-bind--disabled="context.loading"
					data-wp-bind--aria-busy="context.loading"
				><?php esc_html_e( 'Join this group', 'wporg-groups-frontend' ); ?></button>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( $shows_count || $renders_leave ) : ?>
		<div class="wporg-group-membership__meta">
			<?php if ( $shows_count ) : ?>
				<?php if ( $members_url ) : ?>
					<a class="wporg-group-membership__count" href="<?php echo esc_url( $members_url ); ?>">
						<span data-wp-text="state.countLabel"><?php echo esc_html( $count_label ); ?></span>
					</a>
				<?php else : ?>
					<span class="wporg-group-membership__count" data-wp-text="state.countLabel">
						<?php echo esc_html( $count_label ); ?>
					</span>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( $shows_count && $renders_leave ) : ?>
				<span class="wporg-group-membership__meta-divider" aria-hidden="true">&middot;</span>
			<?php endif; ?>

			<?php if ( $renders_leave ) : ?>
				<button
					class="wporg-group-membership__leave"
					data-wp-on--click="actions.leave"
					data-wp-bind--disabled="context.loading"
					data-wp-bind--aria-busy="context.loading"
				><?php esc_html_e( 'Leave group', 'wporg-groups-frontend' ); ?></button>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( $renders_preference ) : ?>
		<?php if ( 'preference' === $variant ) : ?>
			<h2 class="wporg-group-membership__preference-heading">
				<?php esc_html_e( 'Email preferences', 'wporg-groups-frontend' ); ?>
			</h2>
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
				<span><?php esc_html_e( 'Email me updates and information about events from organizers.', 'wporg-groups-frontend' ); ?></span>
			</label>
			<span class="wporg-group-membership__preference-help">
				<?php esc_html_e( 'This preference applies to this group only. Set it separately for each group you belong to.', 'wporg-groups-frontend' ); ?>
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
