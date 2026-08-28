<?php
/**
 * Server-side rendering for the wporg/group-members block.
 *
 * @package WordCamp\Groups\Frontend
 */

use WordCamp\Groups\Frontend\Members\Members_Controller;
use function WordCamp\Groups\Frontend\Capabilities\current_user_can_switch_own_role;

$role_labels = Members_Controller::ROLE_LABELS;

$users = get_users(
	array(
		'blog_id' => get_current_blog_id(),
		'orderby' => 'display_name',
		'order'   => 'ASC',
		// Bound the worst case for large groups — mirrors the REST
		// endpoint's own cap (Members_Controller::MAX_PER_PAGE) rather
		// than loading every member of the site unconditionally.
		'number'  => Members_Controller::MAX_PER_PAGE,
	)
);

// Prime the usermeta cache for all of them in one query instead of one
// `get_the_author_meta( 'description', ... )` call per user below.
update_meta_cache( 'user', wp_list_pluck( $users, 'ID' ) );

// Sort: organizers first, then event organizers, then members.
$weights = array(
	'administrator' => 0,
	'editor'        => 1,
	'author'        => 2,
	'contributor'   => 3,
	'subscriber'    => 4,
);

usort(
	$users,
	function ( $a, $b ) use ( $weights ) {
		$role_a   = reset( $a->roles ) ?: 'subscriber';
		$role_b   = reset( $b->roles ) ?: 'subscriber';
		$weight_a = $weights[ $role_a ] ?? 5;
		$weight_b = $weights[ $role_b ] ?? 5;

		if ( $weight_a !== $weight_b ) {
			return $weight_a - $weight_b;
		}

		return strcasecmp( $a->display_name, $b->display_name );
	}
);

/*
 * Self-serve role switching is a beta-testing affordance, limited to the
 * groups in `Capabilities\SELF_SERVE_ROLE_GROUPS` — see that constant for
 * why it isn't offered on real community groups.
 */
$renders_role_switcher = current_user_can_switch_own_role();
$current_user_role     = '';

if ( $renders_role_switcher ) {
	$switcher_user     = wp_get_current_user();
	$current_user_role = reset( $switcher_user->roles ) ?: 'subscriber';

	// A role outside the three switchable tiers (a stray `contributor`, say)
	// has no button to mark as current; the switcher still lets them move
	// into one of the three.
	$switcher_roles = array(
		'subscriber' => array(
			'label'       => __( 'Member', 'wporg-groups-frontend' ),
			'description' => __( 'Join and leave, RSVP, set email preferences.', 'wporg-groups-frontend' ),
		),
		'author'     => array(
			'label'       => __( 'Event Organizer', 'wporg-groups-frontend' ),
			'description' => __( 'Everything a Member can do, plus create and manage your own events and venues.', 'wporg-groups-frontend' ),
		),
		'editor'     => array(
			'label'       => __( 'Organizer', 'wporg-groups-frontend' ),
			'description' => __( "Everything an Event Organizer can do, plus manage everyone's events, member roles, group info and design.", 'wporg-groups-frontend' ),
		),
	);

	$switcher_context = array(
		'currentRole' => $current_user_role,
		'saving'      => false,
		'message'     => '',
		'isError'     => false,
		'roleApi'     => rest_url( 'wporg-groups/v1/members/me/role' ),
		'restNonce'   => wp_create_nonce( 'wp_rest' ),
		'errorLabel'  => __( 'Your role could not be changed. Please try again.', 'wporg-groups-frontend' ),
	);
}

$total_count = count( $users );

$wrapper_attributes = get_block_wrapper_attributes(
	array( 'class' => 'wporg-group-members' )
);
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<h2 class="wporg-section-heading wporg-group-members__heading">
		<?php
		echo esc_html(
			sprintf(
				_n( '%s Member', '%s Members', $total_count, 'wporg-groups-frontend' ),
				number_format_i18n( $total_count )
			)
		);
		?>
	</h2>

	<?php if ( $renders_role_switcher ) : ?>
		<div
			class="wporg-group-members__role-switcher"
			data-wp-interactive="wporg/group-members"
			data-wp-context="<?php echo esc_attr( wp_json_encode( $switcher_context ) ); ?>"
		>
			<h3 class="wporg-group-members__role-switcher-heading" id="wporg-role-switcher-heading">
				<?php esc_html_e( 'Your role in this group', 'wporg-groups-frontend' ); ?>
			</h3>
			<p class="wporg-group-members__role-switcher-help">
				<?php esc_html_e( 'This is a testing group, so you can switch your own role to try out the organizer tools. Pick a role below, then switch back whenever you like.', 'wporg-groups-frontend' ); ?>
			</p>

			<div class="wporg-group-members__role-options" role="group" aria-labelledby="wporg-role-switcher-heading">
				<?php foreach ( $switcher_roles as $role_slug => $switcher_role ) :
					$is_current = $role_slug === $current_user_role;
					?>
					<button
						type="button"
						class="wporg-group-members__role-option<?php echo $is_current ? ' is-current' : ''; ?>"
						data-role="<?php echo esc_attr( $role_slug ); ?>"
						aria-pressed="<?php echo $is_current ? 'true' : 'false'; ?>"
						data-wp-on--click="actions.switchRole"
						data-wp-bind--disabled="context.saving"
						data-wp-bind--aria-busy="context.saving"
					>
						<span class="wporg-group-members__role-option-label">
							<?php echo esc_html( $switcher_role['label'] ); ?>
						</span>
						<span class="wporg-group-members__role-option-description">
							<?php echo esc_html( $switcher_role['description'] ); ?>
						</span>
					</button>
				<?php endforeach; ?>
			</div>

			<p
				class="wporg-group-members__role-switcher-status"
				role="status"
				aria-live="polite"
				data-wp-text="context.message"
				data-wp-class--is-error="context.isError"
			></p>
		</div>
	<?php endif; ?>

	<div class="wporg-group-members__grid">
		<?php foreach ( $users as $user ) :
			$user_role  = reset( $user->roles ) ?: 'subscriber';
			$role_label = $role_labels[ $user_role ] ?? __( 'Member', 'wporg-groups-frontend' );
			$bio        = wp_trim_words( get_the_author_meta( 'description', $user->ID ), 20, "\u{2026}" );
			$profile    = sprintf( 'https://profiles.wordpress.org/%s/', $user->user_nicename );
			?>
			<a class="wporg-group-members__card" href="<?php echo esc_url( $profile ); ?>" target="_blank" rel="noopener">
				<img
					class="wporg-group-members__avatar"
					src="<?php echo esc_url( get_avatar_url( $user->ID, array( 'size' => 128 ) ) ); ?>"
					alt=""
					width="64"
					height="64"
					loading="lazy"
				/>
				<div class="wporg-group-members__info">
					<span class="wporg-group-members__name"><?php echo esc_html( $user->display_name ); ?></span>
					<span class="wporg-group-members__role wporg-group-members__role--<?php echo esc_attr( $user_role ); ?>">
						<?php echo esc_html( $role_label ); ?>
					</span>
					<?php if ( $bio ) : ?>
						<span class="wporg-group-members__bio"><?php echo esc_html( $bio ); ?></span>
					<?php endif; ?>
				</div>
			</a>
		<?php endforeach; ?>
	</div>
</div>
