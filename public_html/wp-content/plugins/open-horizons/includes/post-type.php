<?php
/**
 * The application post type, its statuses, which status changes are allowed, and the log of them.
 *
 * @package WordCamp\Open_Horizons
 */

declare( strict_types = 1 );

namespace WordCamp\Open_Horizons;

defined( 'WPINC' ) || die();

add_action( 'init', __NAMESPACE__ . '\register_post_type' );
add_action( 'init', __NAMESPACE__ . '\register_statuses' );
add_filter( 'wp_insert_post_data', __NAMESPACE__ . '\enforce_status_transition', 9, 2 );
add_filter( 'wp_insert_post_data', __NAMESPACE__ . '\keep_unowned' );
add_action( 'transition_post_status', __NAMESPACE__ . '\log_status_change', 10, 3 );
add_filter( 'wp_untrash_post_status', __NAMESPACE__ . '\restore_status_on_untrash', 10, 3 );

/**
 * The statuses an application moves through.
 *
 * Status slugs are global across post types, hence the prefix, and `wp_posts.post_status` holds 20 characters.
 *
 * @return string[] Labels, keyed by status.
 */
function get_statuses(): array {
	return array(
		'oh-received'  => __( 'Received', 'wordcamporg' ),
		'oh-reviewing' => __( 'Reviewing', 'wordcamporg' ),
		'oh-accepted'  => __( 'Accepted', 'wordcamporg' ),
		'oh-rejected'  => __( 'Rejected', 'wordcamporg' ),
		'oh-funded'    => __( 'Funded', 'wordcamporg' ),
		'oh-withdrawn' => __( 'Withdrawn', 'wordcamporg' ),
	);
}

/**
 * The statuses each status may change to.
 *
 * @return array[] Allowed next statuses, keyed by status.
 */
function get_allowed_transitions(): array {
	return array(
		'oh-received'  => array( 'oh-reviewing', 'oh-withdrawn' ),
		'oh-reviewing' => array( 'oh-accepted', 'oh-rejected', 'oh-withdrawn' ),
		'oh-accepted'  => array( 'oh-funded', 'oh-withdrawn' ),
		'oh-rejected'  => array( 'oh-reviewing' ),
		'oh-funded'    => array(),
		'oh-withdrawn' => array(),
	);
}

/**
 * Whether an application may change from one status to another.
 *
 * @param string $from The current status.
 * @param string $to   The new status.
 * @return bool
 */
function is_allowed_transition( string $from, string $to ): bool {
	if ( $from === $to ) {
		return true;
	}

	// Trashing is always possible; untrashing goes back to where it was (see restore_status_on_untrash()).
	if ( 'trash' === $to || ( 'trash' === $from && isset( get_statuses()[ $to ] ) ) ) {
		return true;
	}

	return in_array( $to, get_allowed_transitions()[ $from ] ?? array(), true );
}

/**
 * Registers the application post type.
 */
function register_post_type(): void {
	\register_post_type(
		POST_TYPE,
		array(
			'labels'              => array(
				'name'               => __( 'Open Horizons', 'wordcamporg' ),
				'singular_name'      => __( 'Application', 'wordcamporg' ),
				'all_items'          => __( 'Applications', 'wordcamporg' ),
				'edit_item'          => __( 'Review Application', 'wordcamporg' ),
				'search_items'       => __( 'Search Applications', 'wordcamporg' ),
				'not_found'          => __( 'No applications found.', 'wordcamporg' ),
				'not_found_in_trash' => __( 'No applications found in Trash.', 'wordcamporg' ),
			),
			'public'              => false,
			'show_ui'             => true,
			'show_in_rest'        => false,
			'exclude_from_search' => true,
			'publicly_queryable'  => false,
			'rewrite'             => false,
			'query_var'           => false,
			'can_export'          => false,
			'supports'            => array( 'title' ),
			'menu_icon'           => 'dashicons-airplane',
			'capability_type'     => POST_TYPE,
			// Every capability, editing included, belongs to reviewers alone.
			'capabilities'        => array(
				'read'                   => CAPABILITY,
				// Applications come from the form only, so every one has an applicant behind it.
				'create_posts'           => 'do_not_allow',
				'edit_posts'             => CAPABILITY,
				'edit_others_posts'      => CAPABILITY,
				'edit_private_posts'     => CAPABILITY,
				'edit_published_posts'   => CAPABILITY,
				'publish_posts'          => CAPABILITY,
				'read_private_posts'     => CAPABILITY,
				'delete_posts'           => CAPABILITY,
				'delete_others_posts'    => CAPABILITY,
				'delete_private_posts'   => CAPABILITY,
				'delete_published_posts' => CAPABILITY,
			),
			'map_meta_cap'        => true,
		)
	);
}

/**
 * Registers the application statuses: none are public, so applications are never shown on the front end.
 */
function register_statuses(): void {
	/* translators: %s: Number of applications. */
	$label_counts = array(
		'oh-received'  => _n_noop( 'Received <span class="count">(%s)</span>', 'Received <span class="count">(%s)</span>', 'wordcamporg' ),
		'oh-reviewing' => _n_noop( 'Reviewing <span class="count">(%s)</span>', 'Reviewing <span class="count">(%s)</span>', 'wordcamporg' ),
		'oh-accepted'  => _n_noop( 'Accepted <span class="count">(%s)</span>', 'Accepted <span class="count">(%s)</span>', 'wordcamporg' ),
		'oh-rejected'  => _n_noop( 'Rejected <span class="count">(%s)</span>', 'Rejected <span class="count">(%s)</span>', 'wordcamporg' ),
		'oh-funded'    => _n_noop( 'Funded <span class="count">(%s)</span>', 'Funded <span class="count">(%s)</span>', 'wordcamporg' ),
		'oh-withdrawn' => _n_noop( 'Withdrawn <span class="count">(%s)</span>', 'Withdrawn <span class="count">(%s)</span>', 'wordcamporg' ),
	);

	foreach ( get_statuses() as $status => $label ) {
		register_post_status(
			$status,
			array(
				'label'                     => $label,
				'label_count'               => $label_counts[ $status ],
				'public'                    => false,
				'protected'                 => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
			)
		);
	}
}

/**
 * Keeps an application's status when a save would move it somewhere the workflow doesn't go.
 *
 * Enforced here, not only in the review screen, so it holds for every writer: wp-admin, WP-CLI and code.
 * Inserts aren't checked: they only come from the application form.
 *
 * @param array $data    Post data about to be saved.
 * @param array $postarr The data passed to wp_insert_post().
 * @return array
 */
function enforce_status_transition( array $data, array $postarr ): array {
	if ( POST_TYPE !== $data['post_type'] || empty( $postarr['ID'] ) ) {
		return $data;
	}

	$post = get_post( (int) $postarr['ID'] );

	if ( $post && ! is_allowed_transition( $post->post_status, (string) $data['post_status'] ) ) {
		$data['post_status'] = $post->post_status;
	}

	return $data;
}

/**
 * Keeps applications unowned, which core's edit screen would otherwise hand to the first reviewer who saves one.
 *
 * Ownership matters beyond the list screens: deleting a user from the network deletes every post they authored.
 *
 * @param array $data Post data about to be saved.
 * @return array
 */
function keep_unowned( array $data ): array {
	if ( POST_TYPE === $data['post_type'] ) {
		$data['post_author'] = 0;
	}

	return $data;
}

/**
 * Logs every status change, whoever made it and however.
 *
 * @param string   $new_status The new status.
 * @param string   $old_status The previous status.
 * @param \WP_Post $post       The application.
 */
function log_status_change( string $new_status, string $old_status, \WP_Post $post ): void {
	if ( POST_TYPE !== $post->post_type || $new_status === $old_status ) {
		return;
	}

	add_post_meta(
		$post->ID,
		'_oh_status_change',
		array(
			'user_id' => get_current_user_id(),
			'time'    => time(),
			'from'    => $old_status,
			'to'      => $new_status,
		)
	);
}

/**
 * Puts an untrashed application back in the status it had, instead of core's `draft`.
 *
 * @param string $new_status      The status core would restore.
 * @param int    $post_id         The post ID.
 * @param string $previous_status The status before it was trashed.
 * @return string
 */
function restore_status_on_untrash( string $new_status, int $post_id, string $previous_status ): string {
	return POST_TYPE === get_post_type( $post_id ) && isset( get_statuses()[ $previous_status ] ) ? $previous_status : $new_status;
}

/**
 * Adds a private note to an application.
 *
 * @param int    $post_id The application.
 * @param string $text    The note.
 */
function add_note( int $post_id, string $text ): void {
	add_post_meta(
		$post_id,
		'_oh_note',
		array(
			'user_id' => get_current_user_id(),
			'time'    => time(),
			'text'    => $text,
		)
	);
}

/**
 * Gets an application's notes and status changes, newest first.
 *
 * @param int $post_id The application.
 * @return array[] Entries with `user_id`, `time`, and either `text` or `from` and `to`.
 */
function get_log( int $post_id ): array {
	$entries = array_merge(
		get_post_meta( $post_id, '_oh_note', false ),
		get_post_meta( $post_id, '_oh_status_change', false )
	);

	usort( $entries, static fn( array $a, array $b ): int => $b['time'] <=> $a['time'] );

	return $entries;
}
