<?php
/**
 * Server-side rendering for the wporg/group-members block.
 *
 * @package WordCamp\Groups\Frontend
 */

use WordCamp\Groups\Frontend\Members\Members_Controller;

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

$total_count = count( $users );

$wrapper_attributes = get_block_wrapper_attributes(
	array( 'class' => 'wporg-group-members' )
);
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<h2 class="wporg-group-members__heading">
		<?php
		echo esc_html(
			sprintf(
				_n( '%s Member', '%s Members', $total_count, 'wporg-groups-frontend' ),
				number_format_i18n( $total_count )
			)
		);
		?>
	</h2>

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
