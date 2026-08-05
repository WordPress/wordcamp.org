<?php
/**
 * Network administration for archiving and reactivating group sites.
 *
 * Uses WordPress multisite's native archived-site flag so group content,
 * RSVPs, and memberships remain intact. Organisers cannot request archival
 * in v1; only users who can manage network sites can use this screen.
 *
 * @package WordCamp\Groups
 */

namespace WordCamp\Groups\Archive;

use WP_Error;
use WP_Site;

defined( 'WPINC' ) || die();

const PAGE_SLUG     = 'wporg-groups';
const UPDATE_ACTION = 'wporg_groups_update_archive_status';

add_action( 'network_admin_menu', __NAMESPACE__ . '\\register_page' );
add_action( 'network_admin_edit_' . UPDATE_ACTION, __NAMESPACE__ . '\\handle_update' );

/**
 * Get the group sites on the Groups network.
 *
 * Active-only is the safe default for directories and other public listings.
 * The Groups network placeholder site is never a group and is always omitted.
 *
 * @param bool $include_archived Whether archived groups should be returned.
 * @return WP_Site[]
 */
function get_group_sites( bool $include_archived = false ): array {
	$args = array(
		'network_id'   => GROUPS_NETWORK_ID,
		'number'       => 0,
		'site__not_in' => array( GROUPS_ROOT_BLOG_ID ),
		'deleted'      => 0,
		'spam'         => 0,
		'orderby'      => 'domain',
		'order'        => 'ASC',
	);

	if ( ! $include_archived ) {
		$args['archived'] = 0;
	}

	return get_sites( $args );
}

/**
 * Register the Groups management screen in Network Admin.
 */
function register_page(): void {
	add_submenu_page(
		'sites.php',
		__( 'Groups', 'wordcamporg' ),
		__( 'Groups', 'wordcamporg' ),
		'manage_sites',
		PAGE_SLUG,
		__NAMESPACE__ . '\\render_page'
	);
}

/**
 * Archive or reactivate a group using WordPress core's site status.
 *
 * @param int  $site_id  Site ID to update.
 * @param bool $archived Whether the group should be archived.
 * @return true|WP_Error
 */
function update_group_archive_status( int $site_id, bool $archived ) {
	$site = get_site( $site_id );

	if (
		! $site ||
		GROUPS_NETWORK_ID !== (int) $site->network_id ||
		GROUPS_ROOT_BLOG_ID === (int) $site->blog_id ||
		$site->deleted ||
		$site->spam
	) {
		return new WP_Error(
			'invalid_group_site',
			__( 'That site is not an archivable group.', 'wordcamporg' )
		);
	}

	$result = wp_update_site(
		$site_id,
		array( 'archived' => $archived ? 1 : 0 )
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return true;
}

/**
 * Process an archive/reactivate request from Network Admin.
 */
function handle_update(): void {
	if ( ! current_user_can( 'manage_sites' ) ) {
		wp_die(
			esc_html__( 'Sorry, you are not allowed to manage groups.', 'wordcamporg' ),
			'',
			array( 'response' => 403 )
		);
	}

	$site_id  = isset( $_POST['site_id'] ) ? absint( wp_unslash( $_POST['site_id'] ) ) : 0;
	$archived = isset( $_POST['archived'] ) && '1' === wp_unslash( $_POST['archived'] );

	check_admin_referer( UPDATE_ACTION . '_' . $site_id );

	$result = update_group_archive_status( $site_id, $archived );

	if ( is_wp_error( $result ) ) {
		wp_die(
			esc_html( $result->get_error_message() ),
			esc_html__( 'Could not update group', 'wordcamporg' ),
			array( 'response' => 400 )
		);
	}

	$redirect_url = add_query_arg(
		array(
			'page'    => PAGE_SLUG,
			'updated' => $archived ? 'archived' : 'reactivated',
		),
		network_admin_url( 'sites.php' )
	);

	wp_safe_redirect( $redirect_url );
	exit;
}

/**
 * Render the Groups management screen.
 */
function render_page(): void {
	if ( ! current_user_can( 'manage_sites' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to manage groups.', 'wordcamporg' ) );
	}

	$groups  = get_group_sites( true );
	$updated = isset( $_GET['updated'] ) ? sanitize_key( wp_unslash( $_GET['updated'] ) ) : '';
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Groups', 'wordcamporg' ); ?></h1>

		<?php if ( in_array( $updated, array( 'archived', 'reactivated' ), true ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>
				<?php
				echo esc_html(
					'archived' === $updated
						? __( 'Group archived.', 'wordcamporg' )
						: __( 'Group reactivated.', 'wordcamporg' )
				);
				?>
			</p></div>
		<?php endif; ?>

		<p>
			<?php esc_html_e( 'Archive a group without deleting its events, RSVPs, or member records. Archived group sites are unavailable on the front end until reactivated.', 'wordcamporg' ); ?>
		</p>
		<p>
			<?php esc_html_e( 'Only network site managers can perform this action. Already scheduled jobs are left unchanged.', 'wordcamporg' ); ?>
		</p>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Group', 'wordcamporg' ); ?></th>
					<th scope="col"><?php esc_html_e( 'URL', 'wordcamporg' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'wordcamporg' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Action', 'wordcamporg' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $groups ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No groups found.', 'wordcamporg' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $groups as $group ) : ?>
						<?php
						$group_id     = (int) $group->blog_id;
						$is_archived  = (bool) $group->archived;
						$group_name   = get_blog_option( $group_id, 'blogname' );
						$group_url    = get_home_url( $group_id, '/' );
						$confirmation = $is_archived
							? ''
							: __( 'Archive this group? The site will be unavailable until it is reactivated.', 'wordcamporg' );
						?>
						<tr>
							<td><strong><?php echo esc_html( $group_name ?: $group->domain . $group->path ); ?></strong></td>
							<td><a href="<?php echo esc_url( $group_url ); ?>"><?php echo esc_html( $group_url ); ?></a></td>
							<td><?php echo esc_html( $is_archived ? __( 'Archived', 'wordcamporg' ) : __( 'Active', 'wordcamporg' ) ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=' . UPDATE_ACTION ) ); ?>">
									<input type="hidden" name="site_id" value="<?php echo esc_attr( $group_id ); ?>">
									<input type="hidden" name="archived" value="<?php echo esc_attr( $is_archived ? '0' : '1' ); ?>">
									<?php wp_nonce_field( UPDATE_ACTION . '_' . $group_id ); ?>
									<button
										type="submit"
										class="button <?php echo esc_attr( $is_archived ? 'button-secondary' : 'button-link-delete' ); ?>"
										<?php if ( $confirmation ) : ?>
											onclick="return window.confirm( '<?php echo esc_js( $confirmation ); ?>' );"
										<?php endif; ?>
									>
										<?php echo esc_html( $is_archived ? __( 'Reactivate', 'wordcamporg' ) : __( 'Archive', 'wordcamporg' ) ); ?>
									</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
	<?php
}
