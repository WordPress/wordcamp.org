<?php
/**
 * Which status changes are allowed, when a sponsorship may be approved, and the log of both.
 *
 * Enforced on save, not only in the screens, so the rules hold for every writer: wp-admin, WP-CLI, intake from
 * camp sites, and code.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting;

defined( 'WPINC' ) || die();

/**
 * The statuses each status may change to.
 *
 * Approving a sponsorship is further limited by may_approve().
 *
 * @param string $post_type The post type.
 * @return array[] Allowed next statuses, keyed by status.
 */
function get_allowed_transitions( string $post_type ): array {
	if ( COMPANY_POST_TYPE === $post_type ) {
		return array(
			'sv-needs-vetting'  => array( 'sv-in-progress', 'sv-compliance-req', 'sv-compliant', 'sv-not-eligible' ),
			'sv-in-progress'    => array( 'sv-compliance-req', 'sv-compliant', 'sv-not-eligible' ),
			'sv-compliance-req' => array( 'sv-in-progress', 'sv-compliant', 'sv-not-eligible' ),
			'sv-compliant'      => array( 'sv-in-progress', 'sv-needs-vetting', 'sv-not-eligible' ),
			'sv-not-eligible'   => array( 'sv-needs-vetting', 'sv-in-progress' ),
		);
	}

	if ( SPONSORSHIP_POST_TYPE === $post_type ) {
		return array(
			'svs-pending'      => array( 'svs-approved', 'svs-not-approved', 'svs-canceled' ),
			'svs-approved'     => array( 'svs-canceled' ),
			'svs-not-approved' => array( 'svs-pending' ),
			'svs-canceled'     => array( 'svs-pending' ),
		);
	}

	return array();
}

/**
 * The status a new record starts in.
 *
 * @param string $post_type The post type.
 * @return string
 */
function get_initial_status( string $post_type ): string {
	return COMPANY_POST_TYPE === $post_type ? 'sv-needs-vetting' : 'svs-pending';
}

/**
 * Whether a record may change from one status to another.
 *
 * @param string $post_type The post type.
 * @param string $from      The current status.
 * @param string $to        The new status.
 * @return bool
 */
function is_allowed_transition( string $post_type, string $from, string $to ): bool {
	if ( $from === $to ) {
		return true;
	}

	// Trashing is always possible; untrashing goes back to where it was (see restore_status_on_untrash()).
	if ( 'trash' === $to || ( 'trash' === $from && isset( get_statuses( $post_type )[ $to ] ) ) ) {
		return true;
	}

	return in_array( $to, get_allowed_transitions( $post_type )[ $from ] ?? array(), true );
}

/**
 * Keeps a record's status when a save would move it somewhere the workflow doesn't go.
 *
 * New records start in their first status, whatever core's screens ask for (`draft`, `publish`). Approving a
 * sponsorship also needs may_approve(), and an approved one keeps its company.
 *
 * @param array $data    Post data about to be saved.
 * @param array $postarr The data passed to wp_insert_post().
 * @return array
 */
function enforce_status_transition( array $data, array $postarr ): array {
	$statuses = get_statuses( (string) $data['post_type'] );

	if ( ! $statuses ) {
		return $data;
	}

	$post = empty( $postarr['ID'] ) ? null : get_post( (int) $postarr['ID'] );
	$to   = (string) $data['post_status'];

	// Core's Add New screen saves an auto-draft before anyone picks a status.
	if ( 'auto-draft' === $to ) {
		return $data;
	}

	if ( ! $post || ( ! isset( $statuses[ $post->post_status ] ) && 'trash' !== $post->post_status ) ) {
		if ( ! isset( $statuses[ $to ] ) || 'svs-approved' === $to ) {
			$data['post_status'] = get_initial_status( (string) $data['post_type'] );
		}

		return $data;
	}

	/*
	 * The approval was for its company's vetting, and restoring one from the trash skips may_approve(), so a trashed
	 * approval keeps its company too. Unlinking stays possible, for when the company is deleted.
	 */
	$was_approved = 'svs-approved' === $post->post_status
		|| ( 'trash' === $post->post_status && 'svs-approved' === get_post_meta( $post->ID, '_wp_trash_meta_status', true ) );

	if ( $was_approved && (int) $data['post_parent'] && (int) $data['post_parent'] !== (int) $post->post_parent ) {
		$data['post_parent'] = $post->post_parent;
	}

	// A screen loaded before someone else changed the status would otherwise change it back.
	$is_stale = isset( $postarr['original_post_status'] ) && $postarr['original_post_status'] !== $post->post_status;

	// Restoring an approved sponsorship from the trash isn't a new approval.
	$is_restoring = 'trash' === $post->post_status && restoring_approval() === $post->ID;

	if ( $is_stale || ! is_allowed_transition( $post->post_type, $post->post_status, $to ) ) {
		$data['post_status'] = $post->post_status;
	} elseif ( 'svs-approved' === $to && 'svs-approved' !== $post->post_status && ! $is_restoring && ! may_approve( $post->ID, (int) $data['post_parent'] ) ) {
		$data['post_status'] = $post->post_status;
	}

	return $data;
}
add_filter( 'wp_insert_post_data', __NAMESPACE__ . '\enforce_status_transition', 9, 2 );

/**
 * Whether a sponsorship may be approved.
 *
 * Its company must be Compliant and vetted within the re-vetting threshold, or, only while
 * approve_sponsorship() approves it in good faith, have been asked for changes.
 *
 * @param int $sponsorship_id The sponsorship.
 * @param int $company_id     Its company.
 * @return bool
 */
function may_approve( int $sponsorship_id, int $company_id ): bool {
	if ( ! $company_id || COMPANY_POST_TYPE !== get_post_type( $company_id ) ) {
		return false;
	}

	if ( get_vetting_state( $company_id )['approvable'] ) {
		return true;
	}

	return approving_in_good_faith() === $sponsorship_id && 'sv-compliance-req' === get_post_status( $company_id );
}

/**
 * Tracks the sponsorship being approved in good faith, so only approve_sponsorship() can do that.
 *
 * Kept in memory, not in the request or the database, so no form field or meta value can unlock it.
 *
 * @param int|null $sponsorship_id The sponsorship being approved, 0 when done, or null to read.
 * @return int The sponsorship being approved in good faith, or 0.
 */
function approving_in_good_faith( ?int $sponsorship_id = null ): int {
	static $current = 0;

	if ( null !== $sponsorship_id ) {
		$current = $sponsorship_id;
	}

	return $current;
}

/**
 * Tracks the sponsorship being restored from the trash as approved, so restoring it isn't taken for a new approval.
 *
 * Kept in memory because core deletes `_wp_trash_meta_status` before it saves the restored status.
 *
 * @param int|null $sponsorship_id The sponsorship being restored, or null to read.
 * @return int The sponsorship being restored as approved, or 0.
 */
function restoring_approval( ?int $sponsorship_id = null ): int {
	static $current = 0;

	if ( null !== $sponsorship_id ) {
		$current = $sponsorship_id;
	}

	return $current;
}

/**
 * Tracks whether the plugin is acting on its own, so its log entries and approvals are put down to the system
 * rather than to whoever's request set it off.
 *
 * @param bool|null $acting Whether it's acting on its own, or null to read.
 * @return bool
 */
function acting_as_system( ?bool $acting = null ): bool {
	static $current = false;

	if ( null !== $acting ) {
		$current = $acting;
	}

	return $current;
}

/**
 * Gets who is acting, for the log: the current user, or 0 for the system.
 *
 * @return int
 */
function get_acting_user_id(): int {
	return acting_as_system() ? 0 : get_current_user_id();
}

/**
 * Approves a sponsorship.
 *
 * Callers check capabilities first.
 *
 * @param int         $sponsorship_id The sponsorship.
 * @param bool        $good_faith     Whether to approve it in good faith: the sponsor has been asked for changes,
 *                                    committed to them, and the event can't wait. Never carries over to other
 *                                    sponsorships.
 * @param string|null $shown_domain   The camp sponsor's website the vetter was shown, if a vetter is approving.
 *                                    The approval is for that website, so a different one now is refused.
 * @return true|\WP_Error
 */
function approve_sponsorship( int $sponsorship_id, bool $good_faith = false, ?string $shown_domain = null ): true|\WP_Error {
	$sponsorship = get_post( $sponsorship_id );

	if ( ! $sponsorship || SPONSORSHIP_POST_TYPE !== $sponsorship->post_type || 'svs-pending' !== $sponsorship->post_status ) {
		return new \WP_Error( 'sv_not_pending', __( 'Only a pending sponsorship can be approved.', 'wordcamporg' ) );
	}

	$current = (string) get_current_domain( $sponsorship_id );

	if ( null !== $shown_domain && normalize_domain( $shown_domain ) !== $current ) {
		/* translators: %s: Website domain. */
		return new \WP_Error( 'sv_website_changed_since_shown', sprintf( __( 'The camp changed the sponsor’s website to %s while you had this page open. Check it, then approve again.', 'wordcamporg' ), $current ?: __( 'none', 'wordcamporg' ) ) );
	}

	if ( $good_faith && 'sv-compliance-req' !== get_post_status( $sponsorship->post_parent ) ) {
		return new \WP_Error( 'sv_not_good_faith', __( 'A sponsorship can only be approved in good faith while its company has been asked for changes.', 'wordcamporg' ) );
	}

	approving_in_good_faith( $good_faith ? $sponsorship_id : 0 );

	wp_update_post(
		array(
			'ID'          => $sponsorship_id,
			'post_status' => 'svs-approved',
		)
	);

	approving_in_good_faith( 0 );

	if ( 'svs-approved' !== get_post_status( $sponsorship_id ) ) {
		return new \WP_Error( 'sv_not_approvable', __( 'The company must be Compliant, and vetted within the re-vetting period, before its sponsorships can be approved.', 'wordcamporg' ) );
	}

	// The website just checked, not one read again since.
	snapshot_approved_domain( $sponsorship_id, $current );

	if ( $good_faith ) {
		update_post_meta(
			$sponsorship_id,
			'_sv_good_faith',
			array(
				'user_id' => get_current_user_id(),
				'time'    => time(),
			)
		);
		// A later Good Faith approval doesn't move the deadline the sponsor already has.
		if ( ! get_post_meta( $sponsorship->post_parent, '_sv_good_faith_due', true ) ) {
			update_post_meta( $sponsorship->post_parent, '_sv_good_faith_due', gmdate( 'Y-m-d', time() + GOOD_FAITH_DAYS * DAY_IN_SECONDS ) );
		}
		/* translators: %s: Sponsorship title. */
		add_note( $sponsorship->post_parent, sprintf( __( 'Approved “%s” in good faith.', 'wordcamporg' ), $sponsorship->post_title ) );
	}

	return true;
}

/**
 * Approves a pending sponsorship whose company is already vetted, and emails the event.
 *
 * Only for a website the company was vetted for: another one needs a vetter's look. Put down to the system, not to
 * the organizer or vetter whose request set it off. A failed email is noted, and vetters can send it again.
 *
 * Runs on Central.
 *
 * @param int $sponsorship_id The sponsorship.
 * @return bool Whether it was approved.
 */
function auto_approve( int $sponsorship_id ): bool {
	$company_id = (int) get_post_field( 'post_parent', $sponsorship_id );
	$domain     = (string) get_current_domain( $sponsorship_id );

	if (
		'svs-pending' !== get_post_status( $sponsorship_id )
		|| ! $company_id
		|| ! get_vetting_state( $company_id )['approvable']
		|| ! in_array( $domain, get_company_domains( $company_id ), true )
	) {
		return false;
	}

	$was_system = acting_as_system();
	acting_as_system( true );

	try {
		// The website just checked, so the approval is for that one.
		$approved = true === approve_sponsorship( $sponsorship_id, false, $domain );

		if ( $approved ) {
			add_note( $sponsorship_id, __( 'Approved automatically: the company is vetted.', 'wordcamporg' ) );

			$sent = send_approval_email( $sponsorship_id );

			if ( is_wp_error( $sent ) ) {
				add_note( $sponsorship_id, get_send_error_message( $sent ) );
			}
		}
	} finally {
		acting_as_system( $was_system );
	}

	return $approved;
}

/**
 * Approves a company's pending sponsorships once it's Compliant, and tells a vetter on the screen how that went.
 *
 * After the save, so the domains saved with the status count. Restoring a company from the trash isn't a new vetting.
 *
 * @param int           $post_id     The post ID.
 * @param \WP_Post      $post        The post.
 * @param bool          $update      Whether this is an update.
 * @param \WP_Post|null $post_before The post before the save, if it existed.
 */
function auto_approve_on_compliant( int $post_id, \WP_Post $post, bool $update, ?\WP_Post $post_before ): void {
	if ( COMPANY_POST_TYPE !== $post->post_type || 'sv-compliant' !== $post->post_status || in_array( $post_before?->post_status, array( 'sv-compliant', 'trash' ), true ) ) {
		return;
	}

	$sponsorship_ids = get_posts(
		array(
			'post_type'      => SPONSORSHIP_POST_TYPE,
			'post_status'    => 'svs-pending',
			'post_parent'    => $post->ID,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);

	$approved = array_filter( $sponsorship_ids, __NAMESPACE__ . '\auto_approve' );
	$unsent   = array_filter( $approved, __NAMESPACE__ . '\needs_approval_email' );

	// Outside wp-admin, the history is where vetters find it.
	if ( ! $approved || ! function_exists( __NAMESPACE__ . '\add_notice' ) ) {
		return;
	}

	/* translators: %d: Number of sponsorships. */
	add_notice( 'success', sprintf( _n( '%d pending sponsorship was approved automatically.', '%d pending sponsorships were approved automatically.', count( $approved ), 'wordcamporg' ), count( $approved ) ) );

	if ( $unsent ) {
		/* translators: %d: Number of sponsorships. */
		add_notice( 'error', sprintf( _n( '%d approval email wasn’t sent. The sponsorship’s history says why; send it from there.', '%d approval emails weren’t sent. Each sponsorship’s history says why; send them from there.', count( $unsent ), 'wordcamporg' ), count( $unsent ) ) );
	}
}
add_action( 'wp_after_insert_post', __NAMESPACE__ . '\auto_approve_on_compliant', 10, 4 );

/**
 * Works out where a company's vetting stands today.
 *
 * Computed when read, so no cron job changes a status as time passes.
 *
 * @param int $company_id The company.
 * @return array {
 *     @type string $status              The company's status.
 *     @type bool   $approvable          Compliant, and vetted within the re-vetting threshold.
 *     @type bool   $expired             Compliant, but vetted before the threshold.
 *     @type bool   $not_eligible_active Not Eligible, with no end date or one still to come.
 *     @type bool   $good_faith_overdue  A Good Faith due date has passed and the company still isn't Compliant.
 *     @type bool   $needs_requeue       A new sponsorship should put the company back in the queue.
 * }
 */
function get_vetting_state( int $company_id ): array {
	$status      = (string) get_post_status( $company_id );
	$last_vetted = (int) get_post_meta( $company_id, '_sv_last_vetted', true );
	$until       = (string) get_post_meta( $company_id, '_sv_not_eligible_until', true );
	$due         = (string) get_post_meta( $company_id, '_sv_good_faith_due', true );
	$today       = gmdate( 'Y-m-d' );
	$is_fresh    = $last_vetted >= strtotime( sprintf( '-%d months', REVETTING_MONTHS ) );

	$state = array(
		'status'              => $status,
		'approvable'          => 'sv-compliant' === $status && $is_fresh,
		'expired'             => 'sv-compliant' === $status && ! $is_fresh,
		'not_eligible_active' => 'sv-not-eligible' === $status && ( '' === $until || $until > $today ),
		'good_faith_overdue'  => '' !== $due && $due < $today && 'sv-compliant' !== $status,
	);

	$state['needs_requeue'] = $state['expired'] || ( 'sv-not-eligible' === $status && ! $state['not_eligible_active'] );

	return $state;
}

/**
 * Logs every status change, whoever made it and however.
 *
 * @param string   $new_status The new status.
 * @param string   $old_status The previous status.
 * @param \WP_Post $post       The record.
 */
function log_status_change( string $new_status, string $old_status, \WP_Post $post ): void {
	if ( ! get_statuses( $post->post_type ) || $new_status === $old_status || 'auto-draft' === $new_status ) {
		return;
	}

	add_post_meta(
		$post->ID,
		'_sv_status_change',
		array(
			'user_id' => get_acting_user_id(),
			'time'    => time(),
			'from'    => 'auto-draft' === $old_status ? 'new' : $old_status,
			'to'      => $new_status,
		)
	);
}
add_action( 'transition_post_status', __NAMESPACE__ . '\log_status_change', 10, 3 );

/**
 * Keeps the dates and flags that follow from a status in step with it.
 *
 * @param string   $new_status The new status.
 * @param string   $old_status The previous status.
 * @param \WP_Post $post       The record.
 */
function apply_status_side_effects( string $new_status, string $old_status, \WP_Post $post ): void {
	// Restoring from the trash isn't a decision, so it keeps the dates the record had.
	if ( $new_status === $old_status || 'trash' === $old_status ) {
		return;
	}

	if ( COMPANY_POST_TYPE === $post->post_type ) {
		if ( in_array( $new_status, array( 'sv-compliant', 'sv-not-eligible' ), true ) ) {
			update_post_meta( $post->ID, '_sv_last_vetted', time() );
		}

		if ( 'sv-compliant' === $new_status ) {
			delete_post_meta( $post->ID, '_sv_good_faith_due' );
		}

		if ( 'sv-compliance-req' === $old_status ) {
			delete_post_meta( $post->ID, '_sv_sponsor_replied' );
		}
	}

	if ( SPONSORSHIP_POST_TYPE === $post->post_type && 'svs-approved' === $new_status ) {
		update_post_meta( $post->ID, '_sv_approved_at', time() );
		update_post_meta( $post->ID, '_sv_approved_by', get_acting_user_id() );
		snapshot_approved_domain( $post->ID );
	}
}
add_action( 'transition_post_status', __NAMESPACE__ . '\apply_status_side_effects', 10, 3 );

/**
 * Puts an untrashed record back in the status it had, instead of core's `draft`.
 *
 * @param string $new_status      The status core would restore.
 * @param int    $post_id         The post ID.
 * @param string $previous_status The status before it was trashed.
 * @return string
 */
function restore_status_on_untrash( string $new_status, int $post_id, string $previous_status ): string {
	if ( ! isset( get_statuses( (string) get_post_type( $post_id ) )[ $previous_status ] ) ) {
		return $new_status;
	}

	if ( 'svs-approved' === $previous_status ) {
		restoring_approval( $post_id );
	}

	return $previous_status;
}
add_filter( 'wp_untrash_post_status', __NAMESPACE__ . '\restore_status_on_untrash', 10, 3 );

/**
 * Clears the restored approval once the untrash is done, so it can't unlock a later save in the same process.
 */
function done_restoring_approval(): void {
	restoring_approval( 0 );
}
add_action( 'untrashed_post', __NAMESPACE__ . '\done_restoring_approval' );

/**
 * Unlinks a deleted company's sponsorships, so they go back to reviewers instead of pointing at nothing.
 *
 * Core reparents only children of the deleted post's own type, and emptying the trash deletes companies too.
 *
 * @param int      $post_id The post being deleted.
 * @param \WP_Post $post    The post.
 */
function unlink_deleted_company( int $post_id, \WP_Post $post ): void {
	if ( COMPANY_POST_TYPE !== $post->post_type ) {
		return;
	}

	$sponsorship_ids = get_posts(
		array(
			'post_type'      => SPONSORSHIP_POST_TYPE,
			// Not `any`: it leaves out statuses excluded from search, which all of these are.
			'post_status'    => array_merge( array_keys( get_sponsorship_statuses() ), array( 'trash' ) ),
			'post_parent'    => $post_id,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);

	foreach ( $sponsorship_ids as $sponsorship_id ) {
		wp_update_post(
			array(
				'ID'          => $sponsorship_id,
				'post_parent' => 0,
			)
		);
	}
}
add_action( 'before_delete_post', __NAMESPACE__ . '\unlink_deleted_company', 10, 2 );

/**
 * Adds a private note to a company or sponsorship.
 *
 * @param int    $post_id The record.
 * @param string $text    The note.
 */
function add_note( int $post_id, string $text ): void {
	add_post_meta(
		$post_id,
		'_sv_note',
		array(
			'user_id' => get_acting_user_id(),
			'time'    => time(),
			'text'    => $text,
		)
	);
}

/**
 * Gets a record's notes and status changes, newest first.
 *
 * @param int $post_id The record.
 * @return array[] Entries with `user_id`, `time`, and either `text` or `from` and `to`.
 */
function get_log( int $post_id ): array {
	$entries = array_merge(
		get_post_meta( $post_id, '_sv_note', false ),
		get_post_meta( $post_id, '_sv_status_change', false )
	);

	usort( $entries, static fn( array $a, array $b ): int => $b['time'] <=> $a['time'] );

	return $entries;
}
