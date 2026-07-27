<?php
/**
 * Server-side rendering for the wporg/group-membership block.
 *
 * @package WordCamp\Groups\Frontend
 */

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

$is_organiser    = in_array( $user_role, array( 'administrator', 'editor' ), true );
$user_count      = count_users( 'time', get_current_blog_id() );
$member_count    = $user_count['total_users'] ?? 0;
$join_api        = rest_url( 'wporg-groups/v1/members/join' );
$leave_api       = rest_url( 'wporg-groups/v1/members/leave' );
$login_url       = wp_login_url( get_permalink() ?: home_url() );
// Only logged-in visitors can join or leave, and only their markup should carry a nonce.
$rest_nonce      = $is_logged_in ? wp_create_nonce( 'wp_rest' ) : '';
$count_label     = sprintf(
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
	'loginUrl'      => $login_url,
	'nonce'         => $rest_nonce,
	'loading'       => false,
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
	<?php if ( $is_member ) : ?>
		<span class="wporg-group-membership__badge" data-wp-text="state.buttonLabel">
			<?php echo esc_html( $role_label ); ?>
		</span>
		<?php if ( ! $is_organiser ) : ?>
			<button
				class="wporg-group-membership__leave"
				data-wp-on--click="actions.leave"
				data-wp-bind--disabled="context.loading"
			><?php esc_html_e( 'Leave group', 'wporg-groups-frontend' ); ?></button>
		<?php endif; ?>
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
</div>
