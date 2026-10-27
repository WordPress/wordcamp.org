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

add_filter( 'wp_insert_post_data', __NAMESPACE__ . '\enforce_status_transition', 9, 2 );
add_action( 'transition_post_status', __NAMESPACE__ . '\log_status_change', 10, 3 );
add_action( 'transition_post_status', __NAMESPACE__ . '\apply_status_side_effects', 10, 3 );
add_filter( 'wp_untrash_post_status', __NAMESPACE__ . '\restore_status_on_untrash', 10, 3 );

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
 * sponsorship also needs may_approve().
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

	// A screen loaded before someone else changed the status would otherwise change it back.
	$is_stale = isset( $postarr['original_post_status'] ) && $postarr['original_post_status'] !== $post->post_status;

	if ( $is_stale || ! is_allowed_transition( $post->post_type, $post->post_status, $to ) ) {
		$data['post_status'] = $post->post_status;
	} elseif ( 'svs-approved' === $to && 'svs-approved' !== $post->post_status && ! may_approve( $post->ID, (int) $data['post_parent'] ) ) {
		$data['post_status'] = $post->post_status;
	}

	return $data;
}

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
 * Approves a sponsorship.
 *
 * Callers check capabilities first.
 *
 * @param int  $sponsorship_id The sponsorship.
 * @param bool $good_faith     Whether to approve it in good faith: the sponsor has been asked for changes,
 *                             committed to them, and the event can't wait. Never carries over to other
 *                             sponsorships.
 * @return true|\WP_Error
 */
function approve_sponsorship( int $sponsorship_id, bool $good_faith = false ): true|\WP_Error {
	$sponsorship = get_post( $sponsorship_id );

	if ( ! $sponsorship || SPONSORSHIP_POST_TYPE !== $sponsorship->post_type || 'svs-pending' !== $sponsorship->post_status ) {
		return new \WP_Error( 'sv_not_pending', __( 'Only a pending sponsorship can be approved.', 'wordcamporg' ) );
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

	if ( $good_faith ) {
		update_post_meta(
			$sponsorship_id,
			'_sv_good_faith',
			array(
				'user_id' => get_current_user_id(),
				'time'    => time(),
			)
		);
		update_post_meta( $sponsorship->post_parent, '_sv_good_faith_due', gmdate( 'Y-m-d', time() + GOOD_FAITH_DAYS * DAY_IN_SECONDS ) );
		/* translators: %s: Sponsorship title. */
		add_note( $sponsorship->post_parent, sprintf( __( 'Approved “%s” in good faith.', 'wordcamporg' ), $sponsorship->post_title ) );
	}

	return true;
}

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
			'user_id' => get_current_user_id(),
			'time'    => time(),
			'from'    => 'auto-draft' === $old_status ? 'new' : $old_status,
			'to'      => $new_status,
		)
	);
}

/**
 * Keeps the dates and flags that follow from a status in step with it.
 *
 * @param string   $new_status The new status.
 * @param string   $old_status The previous status.
 * @param \WP_Post $post       The record.
 */
function apply_status_side_effects( string $new_status, string $old_status, \WP_Post $post ): void {
	if ( $new_status === $old_status ) {
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
		update_post_meta( $post->ID, '_sv_approved_by', get_current_user_id() );
	}
}

/**
 * Puts an untrashed record back in the status it had, instead of core's `draft`.
 *
 * @param string $new_status      The status core would restore.
 * @param int    $post_id         The post ID.
 * @param string $previous_status The status before it was trashed.
 * @return string
 */
function restore_status_on_untrash( string $new_status, int $post_id, string $previous_status ): string {
	return isset( get_statuses( (string) get_post_type( $post_id ) )[ $previous_status ] ) ? $previous_status : $new_status;
}

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
			'user_id' => get_current_user_id(),
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
