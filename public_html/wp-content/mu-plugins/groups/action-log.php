<?php
/**
 * A record of who used the Groups tools in Network Admin.
 *
 * Every create, archive, reactivate, transfer decision, message and export
 * run is logged with who did it, when, and to which group. It's an audit
 * trail now that Program Supporters use these tools as well as network admins
 * (#2294), and the source for crediting that work on WordPress.org profiles
 * later (#2075, #2076).
 *
 * Each entry is a post of a non-public type on the Groups network's root
 * site, with the actor as its author, so "everything user X did" is an
 * ordinary author query. Entries are never capped or pruned, and are kept
 * when their author is deleted.
 *
 * Only loaded on the Groups network, via
 * `load-other-mu-plugins.php::wcorg_include_network_only_plugins()`.
 *
 * @package WordCamp\Groups
 */

namespace WordCamp\Groups\Action_Log;

use WordCamp\Groups\Archive;
use WordCamp\Groups\Capabilities;
use WordCamp\Groups\Messaging;
use WordCamp\Logger;
use WP_Error;
use WP_Query;

defined( 'WPINC' ) || die();

const POST_TYPE = 'wporg_groups_action';
const MENU_SLUG = 'wporg-groups-activity';
const PER_PAGE  = 50;

add_action( 'init', __NAMESPACE__ . '\register_log_post_type' );
// After Network_Export's tab at 11, before the Sponsors link at 20.
add_action( 'network_admin_menu', __NAMESPACE__ . '\add_page', 12 );

/**
 * The actions that can be logged, and how the Activity screen describes them.
 *
 * @return string[]
 */
function get_action_labels(): array {
	return array(
		'group_created'     => __( 'Created a group', 'wordcamporg' ),
		'group_archived'    => __( 'Archived a group', 'wordcamporg' ),
		'group_reactivated' => __( 'Reactivated a group', 'wordcamporg' ),
		'transfer_approved' => __( 'Approved an ownership transfer', 'wordcamporg' ),
		'transfer_rejected' => __( 'Rejected an ownership transfer', 'wordcamporg' ),
		'message_queued'    => __( 'Sent a message', 'wordcamporg' ),
		'export_started'    => __( 'Started a network export', 'wordcamporg' ),
	);
}

/**
 * Register the post type entries are stored as.
 *
 * Registered on every Groups site, not just the root, because `record()` and
 * `get_entries()` switch to the root from whichever site the request is on.
 */
function register_log_post_type(): void {
	register_post_type(
		POST_TYPE,
		array(
			'label'            => __( 'Groups activity', 'wordcamporg' ),
			'public'           => false,
			'rewrite'          => false,
			'query_var'        => false,
			'can_export'       => false,
			// The record has to outlive the account, or deleting a user would erase their credit.
			'delete_with_user' => false,
			'supports'         => array( 'author' ),
		)
	);
}

/**
 * Log an action by the current user.
 *
 * A failure is reported to the logger and returned, but never stops the
 * action itself: by the time this runs, the group has already changed.
 *
 * @param string $action  One of the keys of `get_action_labels()`.
 * @param int    $site_id The group acted on, or 0 for network-wide actions.
 * @param array  $context Anything else worth keeping, e.g. who a transfer was between.
 *
 * @return int|WP_Error The entry's ID.
 */
function record( string $action, int $site_id = 0, array $context = array() ) {
	$user_id = get_current_user_id();

	if ( ! $user_id || ! isset( get_action_labels()[ $action ] ) ) {
		Logger\log( 'invalid_entry', compact( 'action', 'site_id', 'user_id' ) );

		return new WP_Error( 'invalid_action_log_entry', 'Only known actions by a logged-in user can be logged.' );
	}

	switch_to_blog( GROUPS_ROOT_BLOG_ID );

	$entry_id = wp_insert_post(
		array(
			'post_type'   => POST_TYPE,
			'post_status' => 'publish',
			'post_author' => $user_id,
			'post_title'  => $action,
			// Unique up front; otherwise `wp_unique_post_slug()` runs one
			// query per existing entry with the same action to find a free slug.
			'post_name'   => wp_generate_uuid4(),
			'meta_input'  => array(
				'_action'  => $action,
				'_site_id' => $site_id,
				'_context' => $context,
			),
		),
		true
	);

	restore_current_blog();

	if ( is_wp_error( $entry_id ) ) {
		Logger\log( 'insert_failed', array( compact( 'action', 'site_id', 'user_id' ), $entry_id->get_error_message() ) );
	}

	return $entry_id;
}

/**
 * Get a page of entries, newest first.
 *
 * @param int $page 1-based page number.
 *
 * @return array{entries: array[], total: int}
 */
function get_entries( int $page = 1 ): array {
	switch_to_blog( GROUPS_ROOT_BLOG_ID );

	$query = new WP_Query(
		array(
			'post_type'              => POST_TYPE,
			'post_status'            => 'publish',
			'posts_per_page'         => PER_PAGE,
			'paged'                  => max( 1, $page ),
			'orderby'                => array(
				'date' => 'DESC',
				'ID'   => 'DESC',
			),
			'update_post_term_cache' => false,
		)
	);

	$entries = array();

	foreach ( $query->posts as $post ) {
		$entries[] = array(
			'id'      => $post->ID,
			'user_id' => (int) $post->post_author,
			'action'  => (string) get_post_meta( $post->ID, '_action', true ),
			'site_id' => (int) get_post_meta( $post->ID, '_site_id', true ),
			'context' => (array) get_post_meta( $post->ID, '_context', true ),
			'time'    => (int) get_post_timestamp( $post ),
		);
	}

	restore_current_blog();

	return array(
		'entries' => $entries,
		'total'   => (int) $query->found_posts,
	);
}

/**
 * Register the "Activity" tab under Network Admin > Groups.
 */
function add_page(): void {
	add_submenu_page(
		Archive\PAGE_SLUG,
		__( 'Activity', 'wordcamporg' ),
		__( 'Activity', 'wordcamporg' ),
		Capabilities\CAPABILITY,
		MENU_SLUG,
		__NAMESPACE__ . '\render_page'
	);
}

/**
 * A user's name for the Activity screen, which also has to cope with deleted users.
 *
 * @param int $user_id User ID.
 */
function format_user( int $user_id ): string {
	$user = get_userdata( $user_id );

	if ( ! $user ) {
		return __( '(deleted user)', 'wordcamporg' );
	}

	return sprintf( '%1$s (%2$s)', $user->display_name, $user->user_login );
}

/**
 * The details column for an entry.
 *
 * @param array $entry An entry from `get_entries()`.
 */
function format_details( array $entry ): string {
	$context = $entry['context'];

	switch ( $entry['action'] ) {
		case 'group_created':
			return sprintf(
				/* translators: %s: WordPress.org username. */
				__( 'Lead organizer: %s', 'wordcamporg' ),
				$context['organizer_login'] ?? ''
			);

		case 'transfer_approved':
		case 'transfer_rejected':
			return sprintf(
				/* translators: 1: current owner, 2: nominated owner. */
				__( 'From %1$s to %2$s', 'wordcamporg' ),
				format_user( (int) ( $context['from_user_id'] ?? 0 ) ),
				format_user( (int) ( $context['to_user_id'] ?? 0 ) )
			);

		case 'message_queued':
			return sprintf(
				/* translators: 1: "Organizers" or "All members", 2: number of groups, 3: email subject. */
				_n( '%1$s of %2$d group: "%3$s"', '%1$s of %2$d groups: "%3$s"', (int) ( $context['group_count'] ?? 0 ), 'wordcamporg' ),
				Messaging\AUDIENCE_MEMBERS === ( $context['audience'] ?? '' ) ? __( 'All members', 'wordcamporg' ) : __( 'Organizers', 'wordcamporg' ),
				(int) ( $context['group_count'] ?? 0 ),
				$context['subject'] ?? ''
			);

		case 'export_started':
			return sprintf(
				/* translators: %d: number of groups. */
				_n( '%d group', '%d groups', (int) ( $context['group_count'] ?? 0 ), 'wordcamporg' ),
				(int) ( $context['group_count'] ?? 0 )
			);
	}

	return '';
}

/**
 * Render the Activity screen.
 */
function render_page(): void {
	if ( ! Capabilities\current_user_can_manage_groups() ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to view group activity.', 'wordcamporg' ) );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
	$current_page = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
	$result       = get_entries( $current_page );
	$total_pages  = max( 1, (int) ceil( $result['total'] / PER_PAGE ) );
	$labels       = get_action_labels();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Activity', 'wordcamporg' ); ?></h1>

		<p><?php esc_html_e( 'Who used the Groups tools, and when.', 'wordcamporg' ); ?></p>

		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'When', 'wordcamporg' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Who', 'wordcamporg' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Action', 'wordcamporg' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Group', 'wordcamporg' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Details', 'wordcamporg' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $result['entries'] ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'Nothing has been logged yet.', 'wordcamporg' ); ?></td></tr>
				<?php endif; ?>

				<?php foreach ( $result['entries'] as $entry ) : ?>
					<?php $site = $entry['site_id'] ? get_site( $entry['site_id'] ) : null; ?>
					<tr>
						<?php // UTC with a fixed format: the root site's own date settings are empty, and an audit trail shouldn't depend on them. ?>
						<td><?php echo esc_html( gmdate( 'Y-m-d H:i', $entry['time'] ) . ' UTC' ); ?></td>
						<td><?php echo esc_html( format_user( $entry['user_id'] ) ); ?></td>
						<td><?php echo esc_html( $labels[ $entry['action'] ] ?? $entry['action'] ); ?></td>
						<td>
							<?php if ( $site ) : ?>
								<a href="<?php echo esc_url( get_home_url( $site->blog_id, '/' ) ); ?>"><?php echo esc_html( $site->blogname ); ?></a>
							<?php elseif ( $entry['site_id'] ) : ?>
								<?php
								/* translators: %d: site ID. */
								echo esc_html( sprintf( __( 'Deleted site %d', 'wordcamporg' ), $entry['site_id'] ) );
								?>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( format_details( $entry ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => network_admin_url( 'admin.php?page=' . MENU_SLUG . '&paged=%#%' ),
								'format'    => '',
								'current'   => $current_page,
								'total'     => $total_pages,
								'prev_text' => __( '&laquo; Previous', 'wordcamporg' ),
								'next_text' => __( 'Next &raquo;', 'wordcamporg' ),
							)
						)
					);
					?>
				</div>
			</div>
		<?php endif; ?>
	</div>
	<?php
}
