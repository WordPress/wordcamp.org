<?php
/**
 * Formal group-ownership transfer workflow.
 *
 * Ordinary co-organizer role changes (Member/Event Organizer/Organizer) are
 * handled entirely by `Members_Controller::update_member_role()` in the
 * `wporg-groups-frontend` mu-plugin. That endpoint explicitly refuses to
 * touch anyone who already holds the site's `administrator` role, so there
 * has never been a supported way for a group owner to hand off their group.
 *
 * This file adds that missing path as a four-stage state machine — initiate,
 * accept, approve, execute — plus the Network Admin screen where the
 * "approve" step happens. It lives here, rather than in
 * `wporg-groups-frontend`, because that plugin's `bootstrap()` only runs
 * when GatherPress is active on the current site, while this workflow (and
 * especially its Network Admin screen, which acts across every group site at
 * once) must work regardless. `wporg-groups-frontend`'s REST controller is a
 * thin client of the functions defined here.
 *
 * Only loaded on the Groups network, via
 * `load-other-mu-plugins.php::wcorg_include_network_only_plugins()`.
 *
 * @package WordCamp\Groups
 */

namespace WordCamp\Groups\Ownership_Transfer;

use WordCamp\Groups\Archive;
use WordCamp\Logger;
use WP_Error;

defined( 'WPINC' ) || die();

/** Site meta key holding the single in-flight transfer for a group, if any. */
const META_KEY_PENDING = '_wporg_groups_ownership_transfer';

/** Site meta key holding a capped, newest-first list of decided transfers. */
const META_KEY_HISTORY = '_wporg_groups_ownership_transfer_history';

/** Maximum number of decided transfers kept per group. */
const HISTORY_LIMIT = 20;

/** A candidate has been nominated and must accept before anything else happens. */
const STATUS_PENDING_ACCEPTANCE = 'pending_acceptance';

/** The candidate has accepted; a network admin must approve before execution. */
const STATUS_PENDING_APPROVAL = 'pending_approval';

/**
 * The only role a transfer target may hold. Mirrors "Organizer tier" from
 * `Members_Controller::ROLE_LABELS`, minus `administrator` itself — there's
 * nothing to transfer to someone who already holds it, so that case gets its
 * own validation error instead of being folded into this constant.
 */
const CANDIDATE_ROLE = 'editor';

/**
 * Longest rejection reason kept. The field is network-admin-only, so this
 * isn't a trust boundary -- it just stops a stray paste from bloating the
 * site meta this history is stored in, which is capped by entry count
 * (`HISTORY_LIMIT`) but not by size.
 */
const REASON_MAX_LENGTH = 500;

/** Network Admin page slug. */
const MENU_SLUG = 'wporg-groups-ownership-transfer';

/** `admin_post_` action the approve/reject forms submit to. */
const DECIDE_ACTION = 'wporg_groups_ownership_transfer_decide';

/** Object-cache group for per-site transfer locks. Registered global, see below. */
const LOCK_GROUP = 'wporg-groups-ownership-transfer';

/**
 * Seconds before an abandoned lock expires on its own, so a process that
 * dies mid-transition can't wedge a group's transfer state permanently.
 */
const LOCK_TIMEOUT = 10;

/** Lock acquisition attempts, `LOCK_RETRY_DELAY` apart. */
const LOCK_ATTEMPTS = 20;

/** Microseconds between lock acquisition attempts. */
const LOCK_RETRY_DELAY = 50000;

add_action( 'network_admin_menu', __NAMESPACE__ . '\add_page' );
add_action( 'admin_post_' . DECIDE_ACTION, __NAMESPACE__ . '\handle_decision' );

/*
 * A lock is keyed by site ID and acquired from contexts where the current
 * blog varies (the REST routes run on the group's own site; the Network
 * Admin handler runs after `switch_to_blog()`). Non-global cache groups are
 * keyed per blog, so without this, the same site's lock key would resolve
 * to different cache buckets depending on which blog happens to be current
 * -- mirrors `Groups\Messaging`'s identical reasoning for its own lock.
 */
wp_cache_add_global_groups( array( LOCK_GROUP ) );

/**
 * Run `$callback` with exclusive access to one group's transfer state.
 *
 * Every state transition (initiate/accept/decline/cancel/execute/reject)
 * does a read-then-write against this group's site meta with no
 * database-level locking of its own. Two concurrent requests -- a
 * double-click, a retried request, a network admin approving while the
 * candidate is declining -- would otherwise race: both read the same "no
 * pending transfer" (or the same pending record), and the second write
 * silently clobbers the first, potentially after both sides have already
 * been emailed. `wp_cache_add()` is atomic on a shared backend (memcached
 * in production), making it a usable cross-process mutex per group --
 * mirrors `Groups\Messaging\with_jobs_lock()`, scoped to one site instead
 * of one global job queue. Without a persistent object cache this
 * degrades to the unsynchronised behaviour it replaces -- no worse than
 * not having it.
 *
 * `$callback` must stay short: nothing slower than the site-meta reads and
 * writes the transition itself needs. Anything that can outlast
 * `LOCK_TIMEOUT` -- `wp_mail()` fan-out above all -- runs after the lock is
 * released, exactly as `Groups\Messaging\process_batch()` sends outside
 * `with_jobs_lock()`. That is what makes the ownership check below a
 * belt-and-braces measure rather than the only thing standing between an
 * expired lock and a caller deleting a lock someone else now holds.
 *
 * @param int      $site_id  Group site ID being locked.
 * @param callable $callback Runs while the lock is held.
 * @return mixed The callback's return value, or a `WP_Error` if the lock
 *               could not be acquired.
 */
function with_transfer_lock( int $site_id, callable $callback ) {
	$lock_key = 'transfer_' . $site_id;
	$acquired = false;

	/*
	 * A value unique to this acquisition, so the release below can tell "my
	 * lock" from "a lock someone else acquired after mine expired". Without
	 * it, a critical section that outran LOCK_TIMEOUT would end by deleting
	 * the next holder's lock and let a third request in alongside them.
	 */
	$token = uniqid( 'transfer_lock_', true );

	for ( $attempt = 0; $attempt < LOCK_ATTEMPTS; $attempt++ ) {
		if ( wp_cache_add( $lock_key, $token, LOCK_GROUP, LOCK_TIMEOUT ) ) {
			$acquired = true;
			break;
		}

		usleep( LOCK_RETRY_DELAY );
	}

	if ( ! $acquired ) {
		return new WP_Error(
			'transfer_busy',
			__( "This group's ownership transfer is being updated by another request. Please try again.", 'wordcamporg' ),
			array( 'status' => 409 )
		);
	}

	try {
		return $callback();
	} finally {
		// Not atomic -- there is no portable compare-and-delete across
		// object-cache backends -- but it turns "always steals" into "only
		// on a read/delete interleave that is itself narrower than the
		// window it protects".
		if ( wp_cache_get( $lock_key, LOCK_GROUP ) === $token ) {
			wp_cache_delete( $lock_key, LOCK_GROUP );
		}
	}
}

/*
 * ----------------------------------------------------------------------
 * State.
 * ----------------------------------------------------------------------
 */

/**
 * Get the in-flight transfer for a group, if any.
 *
 * @param int $site_id Group site ID.
 * @return array{from_user_id:int,to_user_id:int,status:string,initiated_by:int,initiated_at:int,accepted_at:?int}|null
 */
function get_pending_transfer( int $site_id ): ?array {
	$pending = get_site_meta( $site_id, META_KEY_PENDING, true );

	return ( is_array( $pending ) && ! empty( $pending ) ) ? $pending : null;
}

/**
 * Get a group's decided-transfer history, newest first.
 *
 * @param int $site_id Group site ID.
 * @return array[]
 */
function get_transfer_history( int $site_id ): array {
	$history = get_site_meta( $site_id, META_KEY_HISTORY, true );

	return is_array( $history ) ? $history : array();
}

/**
 * Store a group's in-flight transfer.
 *
 * @param int   $site_id Group site ID.
 * @param array $record  Pending-transfer record.
 */
function set_pending_transfer( int $site_id, array $record ): void {
	update_site_meta( $site_id, META_KEY_PENDING, $record );
}

/**
 * Move a decided transfer from "pending" into the capped history list.
 *
 * History is written before the pending record is cleared, and the clear is
 * skipped entirely if that write failed: the failure mode of this order is a
 * transfer that is still pending and can be decided again, while the reverse
 * order's is a decided transfer that vanished without a trace. Callers MUST
 * check the return value -- a dropped write here is what turns "rejected" or
 * "completed" into a record that keeps showing up as awaiting a decision.
 *
 * @param int    $site_id      Group site ID.
 * @param array  $pending      The pending record being decided.
 * @param string $final_status One of 'declined', 'cancelled', 'completed', 'rejected'.
 * @param array  $extra        Extra fields to merge in (e.g. `decided_by`, `reason`).
 * @return bool Whether both meta writes landed.
 */
function finalize_transfer( int $site_id, array $pending, string $final_status, array $extra = array() ): bool {
	$entry = array_merge(
		$pending,
		array(
			'final_status' => $final_status,
			'decided_at'   => time(),
		),
		$extra
	);

	$history = get_transfer_history( $site_id );
	array_unshift( $history, $entry );
	$history = array_slice( $history, 0, HISTORY_LIMIT );

	if ( ! update_site_meta( $site_id, META_KEY_HISTORY, $history ) ) {
		return false;
	}

	return (bool) delete_site_meta( $site_id, META_KEY_PENDING );
}

/**
 * The `WP_Error` returned when `finalize_transfer()` couldn't persist a decision.
 *
 * @param int    $site_id      Group site ID.
 * @param string $final_status The decision that couldn't be recorded.
 * @return WP_Error
 */
function finalize_failed_error( int $site_id, string $final_status ): WP_Error {
	trigger_error(
		sprintf(
			'Could not record the "%1$s" ownership-transfer decision for site %2$d -- the site-meta write failed. The transfer is still listed as pending.',
			$final_status, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Not HTML output; an internal log message this repo's error handler (0-error-handling.php) relays to Slack.
			$site_id // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ditto.
		),
		E_USER_WARNING
	);

	Logger\log(
		'groups_ownership_transfer_finalize_failed',
		array(
			'site_id'      => $site_id,
			'final_status' => $final_status,
		)
	);

	return new WP_Error(
		'transfer_not_recorded',
		__( 'The decision could not be saved. Please try again.', 'wordcamporg' ),
		array( 'status' => 500 )
	);
}

/*
 * ----------------------------------------------------------------------
 * Capabilities.
 *
 * Deliberately narrow, purpose-built checks rather than a broad core
 * capability — see `gatherpress-groups-tweaks.php`'s removed `promote_users`
 * grant to editors for the class of bug this is avoiding repeating.
 * ----------------------------------------------------------------------
 */

/**
 * Whether the current user may initiate a transfer on `$site_id`.
 *
 * The current owner (the site's `administrator`) may always initiate. A
 * network admin may also initiate, on behalf of an unresponsive/inactive
 * owner — the candidate must still explicitly accept and a network admin
 * must still approve, so this only skips the "owner clicks initiate" step,
 * not any safety check.
 *
 * Callers MUST ensure `get_current_blog_id() === $site_id` before calling
 * this — the role check below reads the current user's roles on whatever
 * blog is currently active, exactly like the existing inline check in
 * `Members_Controller::update_member_role()`.
 *
 * @param int $site_id Group site ID. Unused directly; documents the caller's
 *                      blog-context obligation and allows a future per-site
 *                      capability without changing this function's signature.
 */
function current_user_can_initiate( int $site_id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Deliberately unused; see docblock.
	if ( ! is_user_logged_in() ) {
		return false;
	}

	if ( is_super_admin() ) {
		return true;
	}

	return in_array( 'administrator', wp_get_current_user()->roles, true );
}

/**
 * Whether the current user may approve or reject a pending transfer.
 *
 * `manage_sites` matches `Archive\current_user_can_archive_groups()` — this
 * codebase already uses that capability for "one specific group's sensitive
 * state, decided one at a time, surfaced on a network-wide listing screen,"
 * which is exactly this shape of action. `manage_network` is reserved
 * elsewhere in this codebase (`Groups\Messaging`) for tooling that
 * broadcasts to every group at once, which this isn't.
 */
function current_user_can_approve(): bool {
	return current_user_can( 'manage_sites' );
}

/*
 * ----------------------------------------------------------------------
 * Queries used by both the REST controller and the Network Admin screen.
 * ----------------------------------------------------------------------
 */

/**
 * Get every user holding `administrator` on a group site.
 *
 * @param int $site_id Group site ID.
 * @return \WP_User[]
 */
function get_site_administrators( int $site_id ): array {
	return get_users(
		array(
			'blog_id' => $site_id,
			'role'    => 'administrator',
			'orderby' => 'display_name',
		)
	);
}

/**
 * Get every member eligible to be nominated as a transfer target.
 *
 * @param int $site_id Group site ID.
 * @return \WP_User[]
 */
function get_eligible_candidates( int $site_id ): array {
	return get_users(
		array(
			'blog_id' => $site_id,
			'role'    => CANDIDATE_ROLE,
			'orderby' => 'display_name',
		)
	);
}

/**
 * Validate a nominated transfer target.
 *
 * @param int $candidate_id Candidate user ID.
 * @param int $from_user_id The owner being replaced.
 * @param int $site_id      Group site ID.
 * @return true|WP_Error
 */
function validate_candidate( int $candidate_id, int $from_user_id, int $site_id ) {
	if ( $candidate_id === $from_user_id ) {
		return new WP_Error(
			'cannot_transfer_to_self',
			__( 'You cannot transfer ownership to the current owner.', 'wordcamporg' ),
			array( 'status' => 400 )
		);
	}

	$candidate = get_userdata( $candidate_id );

	if ( ! $candidate || ! is_user_member_of_blog( $candidate_id, $site_id ) ) {
		return new WP_Error(
			'candidate_not_found',
			__( 'That user is not a member of this group.', 'wordcamporg' ),
			array( 'status' => 404 )
		);
	}

	if ( in_array( 'administrator', $candidate->roles, true ) ) {
		return new WP_Error(
			'candidate_already_administrator',
			__( 'That user is already an administrator of this group.', 'wordcamporg' ),
			array( 'status' => 400 )
		);
	}

	if ( ! in_array( CANDIDATE_ROLE, $candidate->roles, true ) ) {
		return new WP_Error(
			'candidate_not_eligible',
			__( 'Ownership can only be transferred to an existing Organizer (editor) of this group.', 'wordcamporg' ),
			array( 'status' => 400 )
		);
	}

	return true;
}

/*
 * ----------------------------------------------------------------------
 * State transitions.
 *
 * Each transition is a thin public wrapper around a `*_unlocked()` worker.
 * The wrapper holds the per-site lock for exactly as long as the worker's
 * site-meta reads and writes take, then fires the transition's `do_action`
 * once the lock is released -- the notification layer hanging off those
 * hooks does `wp_mail()` fan-out, and mail transport can easily outlast
 * `LOCK_TIMEOUT`. The workers therefore return the record the hook needs
 * rather than a bare `true`, and the wrappers normalise that back to
 * `true|WP_Error` for callers.
 * ----------------------------------------------------------------------
 */

/**
 * Initiate a transfer.
 *
 * @param int $site_id      Group site ID. The current blog MUST already be this site.
 * @param int $from_user_id The owner being replaced.
 * @param int $to_user_id   The nominated candidate.
 * @param int $initiated_by The acting user (may differ from `$from_user_id` when a
 *                           network admin initiates on an inactive owner's behalf).
 * @return true|WP_Error
 */
function initiate_transfer( int $site_id, int $from_user_id, int $to_user_id, int $initiated_by ) {
	$record = with_transfer_lock(
		$site_id,
		static function () use ( $site_id, $from_user_id, $to_user_id, $initiated_by ) {
			return initiate_transfer_unlocked( $site_id, $from_user_id, $to_user_id, $initiated_by );
		}
	);

	if ( is_wp_error( $record ) ) {
		return $record;
	}

	/**
	 * Fires after a transfer has been initiated.
	 *
	 * @param int   $site_id Group site ID.
	 * @param array $record  The new pending-transfer record.
	 */
	do_action( 'wporg_groups_ownership_transfer_initiated', $site_id, $record );

	return true;
}

/**
 * @see initiate_transfer() -- runs under that function's per-site lock.
 *
 * @return array|WP_Error The new pending record, for the caller to announce.
 */
function initiate_transfer_unlocked( int $site_id, int $from_user_id, int $to_user_id, int $initiated_by ) {
	if ( get_pending_transfer( $site_id ) ) {
		return new WP_Error(
			'transfer_already_pending',
			__( 'A transfer is already pending for this group.', 'wordcamporg' ),
			array( 'status' => 409 )
		);
	}

	$from_user = get_userdata( $from_user_id );

	if ( ! $from_user || ! in_array( 'administrator', $from_user->roles, true ) ) {
		return new WP_Error(
			'invalid_from_user',
			__( 'The selected current owner does not hold the administrator role on this group.', 'wordcamporg' ),
			array( 'status' => 400 )
		);
	}

	$candidate_check = validate_candidate( $to_user_id, $from_user_id, $site_id );
	if ( is_wp_error( $candidate_check ) ) {
		return $candidate_check;
	}

	$record = array(
		'from_user_id' => $from_user_id,
		'to_user_id'   => $to_user_id,
		'status'       => STATUS_PENDING_ACCEPTANCE,
		'initiated_by' => $initiated_by,
		'initiated_at' => time(),
		'accepted_at'  => null,
	);

	set_pending_transfer( $site_id, $record );

	Logger\log(
		'groups_ownership_transfer_initiated',
		array(
			'site_id'      => $site_id,
			'from_user_id' => $from_user_id,
			'to_user_id'   => $to_user_id,
			'initiated_by' => $initiated_by,
		)
	);

	return $record;
}

/**
 * The nominated candidate accepts the transfer.
 *
 * @param int $site_id Group site ID.
 * @param int $user_id The acting user; must be the pending record's `to_user_id`.
 * @return true|WP_Error
 */
function accept_transfer( int $site_id, int $user_id ) {
	$record = with_transfer_lock(
		$site_id,
		static function () use ( $site_id, $user_id ) {
			return accept_transfer_unlocked( $site_id, $user_id );
		}
	);

	if ( is_wp_error( $record ) ) {
		return $record;
	}

	/**
	 * Fires after the candidate accepts a transfer.
	 *
	 * @param int   $site_id Group site ID.
	 * @param array $record  The updated pending-transfer record.
	 */
	do_action( 'wporg_groups_ownership_transfer_accepted', $site_id, $record );

	return true;
}

/**
 * @see accept_transfer() -- runs under that function's per-site lock.
 *
 * @return array|WP_Error The updated pending record, for the caller to announce.
 */
function accept_transfer_unlocked( int $site_id, int $user_id ) {
	$pending = get_pending_transfer( $site_id );

	if ( ! $pending ) {
		return new WP_Error( 'no_pending_transfer', __( 'There is no pending transfer for this group.', 'wordcamporg' ), array( 'status' => 404 ) );
	}

	if ( STATUS_PENDING_ACCEPTANCE !== $pending['status'] ) {
		return new WP_Error( 'transfer_not_awaiting_acceptance', __( 'This transfer is not awaiting acceptance.', 'wordcamporg' ), array( 'status' => 400 ) );
	}

	if ( (int) $pending['to_user_id'] !== $user_id ) {
		return new WP_Error( 'not_the_candidate', __( 'Only the nominated candidate can accept this transfer.', 'wordcamporg' ), array( 'status' => 403 ) );
	}

	$pending['status']      = STATUS_PENDING_APPROVAL;
	$pending['accepted_at'] = time();

	set_pending_transfer( $site_id, $pending );

	Logger\log( 'groups_ownership_transfer_accepted', array( 'site_id' => $site_id ) + $pending );

	return $pending;
}

/**
 * The nominated candidate declines the transfer.
 *
 * @param int $site_id Group site ID.
 * @param int $user_id The acting user; must be the pending record's `to_user_id`.
 * @return true|WP_Error
 */
function decline_transfer( int $site_id, int $user_id ) {
	$record = with_transfer_lock(
		$site_id,
		static function () use ( $site_id, $user_id ) {
			return decline_transfer_unlocked( $site_id, $user_id );
		}
	);

	if ( is_wp_error( $record ) ) {
		return $record;
	}

	/**
	 * Fires after the candidate declines a transfer.
	 *
	 * @param int   $site_id Group site ID.
	 * @param array $record  The declined pending-transfer record.
	 */
	do_action( 'wporg_groups_ownership_transfer_declined', $site_id, $record );

	return true;
}

/**
 * @see decline_transfer() -- runs under that function's per-site lock.
 *
 * @return array|WP_Error The declined record, for the caller to announce.
 */
function decline_transfer_unlocked( int $site_id, int $user_id ) {
	$pending = get_pending_transfer( $site_id );

	if ( ! $pending ) {
		return new WP_Error( 'no_pending_transfer', __( 'There is no pending transfer for this group.', 'wordcamporg' ), array( 'status' => 404 ) );
	}

	// Same guard as `accept_transfer_unlocked()`, for the same reason:
	// declining is the candidate's half of the acceptance step, and once
	// they have accepted, the decision belongs to a network admin. Without
	// this, a candidate could pull an already-accepted transfer out from
	// under the admin about to approve it -- and, since `finalize_transfer()`
	// is unconditional, do it from a stale panel that never saw the accept.
	if ( STATUS_PENDING_ACCEPTANCE !== $pending['status'] ) {
		return new WP_Error( 'transfer_not_awaiting_acceptance', __( 'This transfer is not awaiting acceptance. Ask a network admin to reject it instead.', 'wordcamporg' ), array( 'status' => 400 ) );
	}

	if ( (int) $pending['to_user_id'] !== $user_id ) {
		return new WP_Error( 'not_the_candidate', __( 'Only the nominated candidate can decline this transfer.', 'wordcamporg' ), array( 'status' => 403 ) );
	}

	if ( ! finalize_transfer( $site_id, $pending, 'declined' ) ) {
		return finalize_failed_error( $site_id, 'declined' );
	}

	Logger\log( 'groups_ownership_transfer_declined', array( 'site_id' => $site_id ) + $pending );

	return $pending;
}

/**
 * The initiating audience (owner or network admin) cancels a pending transfer.
 *
 * @param int $site_id Group site ID. The current blog MUST already be this site.
 * @param int $user_id The acting user.
 * @return true|WP_Error
 */
function cancel_transfer( int $site_id, int $user_id ) {
	$record = with_transfer_lock(
		$site_id,
		static function () use ( $site_id, $user_id ) {
			return cancel_transfer_unlocked( $site_id, $user_id );
		}
	);

	if ( is_wp_error( $record ) ) {
		return $record;
	}

	/**
	 * Fires after a pending transfer is cancelled.
	 *
	 * @param int   $site_id Group site ID.
	 * @param array $record  The cancelled pending-transfer record.
	 */
	do_action( 'wporg_groups_ownership_transfer_cancelled', $site_id, $record );

	return true;
}

/**
 * @see cancel_transfer() -- runs under that function's per-site lock.
 *
 * @return array|WP_Error The cancelled record, for the caller to announce.
 */
function cancel_transfer_unlocked( int $site_id, int $user_id ) {
	$pending = get_pending_transfer( $site_id );

	if ( ! $pending ) {
		return new WP_Error( 'no_pending_transfer', __( 'There is no pending transfer for this group.', 'wordcamporg' ), array( 'status' => 404 ) );
	}

	if ( ! current_user_can_initiate( $site_id ) ) {
		return new WP_Error( 'cannot_cancel_transfer', __( 'Sorry, you are not allowed to cancel this transfer.', 'wordcamporg' ), array( 'status' => 403 ) );
	}

	if ( ! finalize_transfer( $site_id, $pending, 'cancelled', array( 'decided_by' => $user_id ) ) ) {
		return finalize_failed_error( $site_id, 'cancelled' );
	}

	Logger\log( 'groups_ownership_transfer_cancelled', array( 'site_id' => $site_id ) + $pending );

	return $pending;
}

/**
 * A network admin approves an accepted transfer, executing the role swap.
 *
 * Promotes the new owner to `administrator` before demoting the old owner to
 * `editor`, so the site is never briefly without an administrator. Each half
 * is verified by re-reading the user before the next step depends on it:
 * a promotion that didn't land stops the transfer before anything is demoted,
 * and a demotion that didn't land cleanly is rolled back. Either way the
 * result is a `WP_Error` plus a Slack-relayed warning, and the transfer stays
 * pending so it can be retried -- never a false report of success.
 *
 * @param int $site_id    Group site ID. The current blog MUST already be this site.
 * @param int $decided_by The approving network admin.
 * @return true|WP_Error
 */
function execute_transfer( int $site_id, int $decided_by ) {
	$record = with_transfer_lock(
		$site_id,
		static function () use ( $site_id, $decided_by ) {
			return execute_transfer_unlocked( $site_id, $decided_by );
		}
	);

	if ( is_wp_error( $record ) ) {
		return $record;
	}

	/**
	 * Fires after a transfer has executed and roles were swapped.
	 *
	 * @param int   $site_id Group site ID.
	 * @param array $record  The completed pending-transfer record.
	 */
	do_action( 'wporg_groups_ownership_transfer_executed', $site_id, $record );

	return true;
}

/**
 * @see execute_transfer() -- runs under that function's per-site lock.
 *
 * @return array|WP_Error The completed record, for the caller to announce.
 */
function execute_transfer_unlocked( int $site_id, int $decided_by ) {
	$pending = get_pending_transfer( $site_id );

	if ( ! $pending ) {
		return new WP_Error( 'no_pending_transfer', __( 'There is no pending transfer for this group.', 'wordcamporg' ), array( 'status' => 404 ) );
	}

	if ( STATUS_PENDING_APPROVAL !== $pending['status'] ) {
		return new WP_Error( 'transfer_not_awaiting_approval', __( 'This transfer has not been accepted by the candidate yet.', 'wordcamporg' ), array( 'status' => 400 ) );
	}

	if ( get_current_blog_id() !== $site_id ) {
		return new WP_Error( 'wrong_site_context', 'execute_transfer() must be called with the target site as the current blog.' );
	}

	$old_owner = get_userdata( (int) $pending['from_user_id'] );
	$new_owner = get_userdata( (int) $pending['to_user_id'] );

	if ( ! $old_owner || ! $new_owner ) {
		return new WP_Error( 'transfer_user_missing', __( 'One of the users in this transfer no longer exists.', 'wordcamporg' ), array( 'status' => 400 ) );
	}

	// Re-validate both parties immediately before mutating roles. Approval
	// can land long after acceptance, long enough for either side to have
	// changed hands in the meantime (candidate removed from the group,
	// demoted, or promoted elsewhere; old owner already demoted by someone
	// else) -- without this, a removed candidate would be silently re-added
	// to the site as a full administrator, and an unrelated user's role
	// would be overwritten.
	if ( ! in_array( 'administrator', $old_owner->roles, true ) ) {
		return new WP_Error(
			'transfer_owner_no_longer_administrator',
			__( 'The current owner named in this transfer no longer holds the administrator role. Reject this transfer and ask the owner to start a new one.', 'wordcamporg' ),
			array( 'status' => 409 )
		);
	}

	$candidate_check = validate_candidate( $new_owner->ID, $old_owner->ID, $site_id );
	if ( is_wp_error( $candidate_check ) ) {
		return $candidate_check;
	}

	$new_owner->set_role( 'administrator' );

	/*
	 * Confirm the promotion actually landed BEFORE demoting anyone.
	 * `WP_User::set_role()` returns nothing, and another plugin hooked to
	 * `set_user_role` can undo or replace what it just wrote, so "the call
	 * returned" is not evidence the new owner is an administrator. Demoting
	 * on that assumption is precisely how a single-owner group ends up with
	 * zero administrators. Bailing here instead leaves every role exactly as
	 * it was and the record still pending, so the decision can simply be
	 * retried once whatever interfered is dealt with.
	 */
	$new_owner_after = get_userdata( (int) $pending['to_user_id'] );

	if ( ! $new_owner_after || ! in_array( 'administrator', $new_owner_after->roles, true ) ) {
		report_inconsistent_execution( $site_id, $pending, 'promotion_failed', false );

		return new WP_Error(
			'transfer_promotion_failed',
			__( 'The new owner could not be promoted, so no roles were changed. Please try again, or check wp-admin → Users for this site directly.', 'wordcamporg' ),
			array( 'status' => 500 )
		);
	}

	$old_owner->set_role( CANDIDATE_ROLE );

	// Re-read both users; `set_role()` above may have been intercepted by
	// another plugin's hook, so the in-memory objects aren't trustworthy.
	$old_owner_after = get_userdata( (int) $pending['from_user_id'] );
	$new_owner_after = get_userdata( (int) $pending['to_user_id'] );

	// `$old_is_candidate` is checked as well as `$old_is_admin` because "no
	// longer an administrator" is also satisfied by a user whose capabilities
	// were wiped entirely -- that would pass as a clean demotion while
	// actually locking them out of the group they still co-organise.
	$old_is_admin     = $old_owner_after && in_array( 'administrator', $old_owner_after->roles, true );
	$new_is_admin     = $new_owner_after && in_array( 'administrator', $new_owner_after->roles, true );
	$old_is_candidate = $old_owner_after && in_array( CANDIDATE_ROLE, $old_owner_after->roles, true );

	if ( $old_is_admin || ! $old_is_candidate || ! $new_is_admin ) {
		$rolled_back = roll_back_execution( $pending );

		report_inconsistent_execution( $site_id, $pending, 'swap_incomplete', $rolled_back );

		return new WP_Error(
			'transfer_execution_inconsistent',
			$rolled_back
				? __( 'The ownership transfer did not complete cleanly and the previous roles were restored. Please try again.', 'wordcamporg' )
				: __( 'The ownership transfer did not complete cleanly. Please check wp-admin → Users for this site directly.', 'wordcamporg' ),
			array( 'status' => 500 )
		);
	}

	if ( ! finalize_transfer( $site_id, $pending, 'completed', array( 'decided_by' => $decided_by ) ) ) {
		/*
		 * The roles ARE swapped at this point, so this is deliberately not
		 * rolled back -- undoing a transfer that succeeded because its
		 * bookkeeping didn't is the worse outcome. The pending record
		 * lingering means the group keeps appearing on the approvals screen,
		 * where a second approve now fails on `candidate_already_administrator`
		 * and a reject clears it without touching roles, which is the correct
		 * way out. Loud, because nothing about the UI would otherwise say so.
		 */
		trigger_error(
			sprintf(
				'Ownership transfer for site %1$d swapped roles successfully but could not clear its pending record. The transfer is complete; reject the leftover request on the Ownership Transfers screen to clear it.',
				$site_id // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Not HTML output; an internal log message this repo's error handler (0-error-handling.php) relays to Slack.
			),
			E_USER_WARNING
		);

		Logger\log(
			'groups_ownership_transfer_finalize_failed',
			array(
				'site_id'      => $site_id,
				'final_status' => 'completed',
			) + $pending
		);

		return new WP_Error(
			'transfer_not_recorded',
			__( 'The roles were swapped, but the transfer record could not be cleared. Reject the leftover request to tidy it up — the ownership change itself is done.', 'wordcamporg' ),
			array( 'status' => 500 )
		);
	}

	Logger\log(
		'groups_ownership_transfer_executed',
		array(
			'site_id'    => $site_id,
			'decided_by' => $decided_by,
		) + $pending
	);

	return $pending;
}

/**
 * Put both parties back the way they were after a half-applied role swap.
 *
 * Restores the old owner first, so the group is never momentarily without an
 * administrator during the rollback either, then puts the candidate back on
 * `CANDIDATE_ROLE`. Verifies the result the same way the forward path does --
 * a rollback that silently failed would be worse than none, since it would
 * turn a reported failure into a reported-and-supposedly-clean one.
 *
 * @param array $pending The pending record being executed.
 * @return bool Whether both parties are back in their original roles.
 */
function roll_back_execution( array $pending ): bool {
	$old_owner = get_userdata( (int) $pending['from_user_id'] );
	$new_owner = get_userdata( (int) $pending['to_user_id'] );

	if ( ! $old_owner || ! $new_owner ) {
		return false;
	}

	$old_owner->set_role( 'administrator' );
	$new_owner->set_role( CANDIDATE_ROLE );

	$old_owner_after = get_userdata( (int) $pending['from_user_id'] );
	$new_owner_after = get_userdata( (int) $pending['to_user_id'] );

	return $old_owner_after && in_array( 'administrator', $old_owner_after->roles, true )
		&& $new_owner_after && ! in_array( 'administrator', $new_owner_after->roles, true )
		&& in_array( CANDIDATE_ROLE, $new_owner_after->roles, true );
}

/**
 * Raise the alarm about a role swap that didn't come out as expected.
 *
 * @param int    $site_id     Group site ID.
 * @param array  $pending     The pending record being executed.
 * @param string $stage       Which check failed: 'promotion_failed' or 'swap_incomplete'.
 * @param bool   $rolled_back Whether the original roles were successfully restored.
 */
function report_inconsistent_execution( int $site_id, array $pending, string $stage, bool $rolled_back ): void {
	$old_owner = get_userdata( (int) $pending['from_user_id'] );
	$new_owner = get_userdata( (int) $pending['to_user_id'] );

	$context = array(
		'site_id'      => $site_id,
		'stage'        => $stage,
		'rolled_back'  => $rolled_back,
		'from_user_id' => (int) $pending['from_user_id'],
		'to_user_id'   => (int) $pending['to_user_id'],
		'from_roles'   => $old_owner ? $old_owner->roles : array(),
		'to_roles'     => $new_owner ? $new_owner->roles : array(),
	);

	trigger_error(
		sprintf(
			'Ownership transfer execution failed at "%1$s" for site %2$d (old owner %3$d roles=[%4$s], new owner %5$d roles=[%6$s], rolled back: %7$s). %8$s',
			$stage, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Not HTML output; an internal log message this repo's error handler (0-error-handling.php) relays to Slack.
			$site_id, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ditto.
			$context['from_user_id'], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ditto.
			implode( ',', $context['from_roles'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ditto.
			$context['to_user_id'], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ditto.
			implode( ',', $context['to_roles'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ditto.
			$rolled_back ? 'yes' : 'no',
			$rolled_back ? 'The transfer can be retried.' : 'Manual review required in wp-admin -> Users.' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ditto.
		),
		E_USER_WARNING
	);

	Logger\log( 'groups_ownership_transfer_inconsistent', $context );
}

/**
 * A network admin rejects an in-flight transfer, at either pending stage.
 *
 * @param int    $site_id    Group site ID.
 * @param int    $decided_by The rejecting network admin.
 * @param string $reason     Optional reason shown to both parties by email.
 * @return true|WP_Error
 */
function reject_transfer( int $site_id, int $decided_by, string $reason = '' ) {
	$record = with_transfer_lock(
		$site_id,
		static function () use ( $site_id, $decided_by, $reason ) {
			return reject_transfer_unlocked( $site_id, $decided_by, $reason );
		}
	);

	if ( is_wp_error( $record ) ) {
		return $record;
	}

	/**
	 * Fires after a transfer is rejected by a network admin.
	 *
	 * @param int    $site_id Group site ID.
	 * @param array  $record  The rejected pending-transfer record.
	 * @param string $reason  Optional reason given by the network admin.
	 */
	do_action( 'wporg_groups_ownership_transfer_rejected', $site_id, $record, $reason );

	return true;
}

/**
 * @see reject_transfer() -- runs under that function's per-site lock.
 *
 * @return array|WP_Error The rejected record, for the caller to announce.
 */
function reject_transfer_unlocked( int $site_id, int $decided_by, string $reason = '' ) {
	$pending = get_pending_transfer( $site_id );

	if ( ! $pending ) {
		return new WP_Error( 'no_pending_transfer', __( 'There is no pending transfer for this group.', 'wordcamporg' ), array( 'status' => 404 ) );
	}

	$finalized = finalize_transfer(
		$site_id,
		$pending,
		'rejected',
		array(
			'decided_by' => $decided_by,
			'reason'     => $reason,
		)
	);

	if ( ! $finalized ) {
		return finalize_failed_error( $site_id, 'rejected' );
	}

	Logger\log(
		'groups_ownership_transfer_rejected',
		array(
			'site_id'    => $site_id,
			'decided_by' => $decided_by,
			'reason'     => $reason,
		) + $pending
	);

	return $pending;
}

/*
 * ----------------------------------------------------------------------
 * Network Admin screen.
 * ----------------------------------------------------------------------
 */

/**
 * Register the "Ownership Transfers" submenu under the Groups menu.
 */
function add_page(): void {
	add_submenu_page(
		Archive\PAGE_SLUG,
		__( 'Ownership Transfers', 'wordcamporg' ),
		__( 'Ownership Transfers', 'wordcamporg' ),
		'manage_sites',
		MENU_SLUG,
		__NAMESPACE__ . '\render_page'
	);
}

/**
 * Get every group site with a transfer awaiting a decision.
 *
 * @return array{site: \WP_Site, pending: array}[]|WP_Error
 */
function get_sites_with_pending_transfers() {
	$sites = get_group_sites_with_meta_key( META_KEY_PENDING );

	if ( is_wp_error( $sites ) ) {
		return $sites;
	}

	$rows = array();

	foreach ( $sites as $site ) {
		$pending = get_pending_transfer( (int) $site->blog_id );

		if ( $pending ) {
			$rows[] = array(
				'site'    => $site,
				'pending' => $pending,
			);
		}
	}

	return $rows;
}

/**
 * Get the most recently decided transfers across every group site, newest first.
 *
 * @param int $limit Maximum rows to return.
 * @return array{site: \WP_Site, entry: array}[]|WP_Error
 */
function get_recent_decided_transfers( int $limit = 20 ) {
	$sites = get_group_sites_with_meta_key( META_KEY_HISTORY );

	if ( is_wp_error( $sites ) ) {
		return $sites;
	}

	$rows = array();

	foreach ( $sites as $site ) {
		foreach ( get_transfer_history( (int) $site->blog_id ) as $entry ) {
			$rows[] = array(
				'site'  => $site,
				'entry' => $entry,
			);
		}
	}

	usort(
		$rows,
		static function ( array $a, array $b ): int {
			return ( $b['entry']['decided_at'] ?? 0 ) <=> ( $a['entry']['decided_at'] ?? 0 );
		}
	);

	return array_slice( $rows, 0, $limit );
}

/**
 * Get the Groups-network sites carrying a specific blog meta key.
 *
 * The Network Admin screen only ever needs sites that actually HAVE a
 * pending transfer or decided-transfer history -- almost always a tiny
 * fraction of the network. Querying `wp_blogmeta` directly for that key
 * scales with how many groups have ever used this feature, instead of
 * `Archive\get_group_sites( true )`'s O(every group on the network), which
 * the existing Archive screen paginates at `wporg-groups-archive.php::PER_PAGE`
 * precisely because it doesn't scale otherwise.
 *
 * @param string $meta_key Blog meta key to look for.
 * @return \WP_Site[]|WP_Error
 */
function get_group_sites_with_meta_key( string $meta_key ) {
	global $wpdb;

	$site_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT blog_id FROM {$wpdb->blogmeta} WHERE meta_key = %s",
			$meta_key
		)
	);

	/*
	 * An empty result set and a failed query are the same `array()` here, and
	 * the screen renders the former as "No transfers are awaiting approval."
	 * Silently reporting "nothing to do" to the people responsible for acting
	 * on these is the one outcome this screen must never produce, so the
	 * difference is surfaced rather than swallowed.
	 */
	if ( $wpdb->last_error ) {
		trigger_error(
			sprintf(
				'Could not list group sites carrying "%1$s": %2$s',
				$meta_key, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Not HTML output; an internal log message this repo's error handler (0-error-handling.php) relays to Slack.
				$wpdb->last_error // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ditto.
			),
			E_USER_WARNING
		);

		return new WP_Error(
			'transfer_query_failed',
			__( 'The list of ownership transfers could not be loaded. Please reload the page.', 'wordcamporg' ),
			array( 'status' => 500 )
		);
	}

	$sites = array();

	foreach ( $site_ids as $site_id ) {
		$site_id = (int) $site_id;
		$site    = get_site( $site_id );

		// Same exclusions as `Archive\get_group_sites()`: only sites on this
		// network, never the placeholder root, and never a site that is
		// deleted, spammed, or archived. The last two matter most here: those
		// groups are out of circulation, and an Approve button next to one
		// would hand a live group's ownership over on the strength of a
		// request made before it was taken down.
		if ( ! $site || GROUPS_ROOT_BLOG_ID === $site_id || GROUPS_NETWORK_ID !== (int) $site->network_id || $site->deleted || $site->spam || $site->archived ) {
			continue;
		}

		$sites[] = $site;
	}

	return $sites;
}

/**
 * Translate a stored `final_status` value for display.
 *
 * Mirrors the `$final_status` values `finalize_transfer()` ever writes.
 * Falls back to the raw value for anything unrecognised, so a status this
 * function hasn't been updated for still shows something instead of blank.
 *
 * @param string $status Stored final-status value.
 * @return string
 */
function get_final_status_label( string $status ): string {
	$labels = array(
		'declined'  => __( 'Declined', 'wordcamporg' ),
		'cancelled' => __( 'Cancelled', 'wordcamporg' ),
		'completed' => __( 'Completed', 'wordcamporg' ),
		'rejected'  => __( 'Rejected', 'wordcamporg' ),
	);

	return $labels[ $status ] ?? $status;
}

/**
 * Clean up a rejection reason for storage and email.
 *
 * `maxlength` on the input is a courtesy to whoever is typing, not a
 * constraint -- the POST can carry any length regardless of what the form
 * said.
 *
 * @param string $reason Raw reason from the request.
 * @return string
 */
function sanitize_reason( string $reason ): string {
	return mb_substr( sanitize_text_field( $reason ), 0, REASON_MAX_LENGTH );
}

/**
 * Process an approve/reject decision from the Network Admin screen.
 */
function handle_decision(): void {
	if ( ! current_user_can_approve() ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to decide group ownership transfers.', 'wordcamporg' ), '', array( 'response' => 403 ) );
	}

	$site_id  = isset( $_POST['site_id'] ) ? absint( wp_unslash( $_POST['site_id'] ) ) : 0;
	$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
	$reason   = isset( $_POST['reason'] ) ? sanitize_reason( wp_unslash( $_POST['reason'] ) ) : '';

	check_admin_referer( DECIDE_ACTION . '_' . $site_id );

	if ( ! in_array( $decision, array( 'approve', 'reject' ), true ) ) {
		wp_die( esc_html__( 'Invalid decision.', 'wordcamporg' ), '', array( 'response' => 400 ) );
	}

	switch_to_blog( $site_id );
	$decided_by = get_current_user_id();

	$result = 'approve' === $decision
		? execute_transfer( $site_id, $decided_by )
		: reject_transfer( $site_id, $decided_by, $reason );

	restore_current_blog();

	if ( is_wp_error( $result ) ) {
		wp_die(
			esc_html( $result->get_error_message() ),
			esc_html__( 'Could not process transfer', 'wordcamporg' ),
			array( 'response' => 400 )
		);
	}

	$redirect_url = add_query_arg(
		array(
			'page'    => MENU_SLUG,
			'updated' => 'approve' === $decision ? 'approved' : 'rejected',
		),
		network_admin_url( 'admin.php' )
	);

	wp_safe_redirect( $redirect_url );
	exit;
}

/**
 * Render the Ownership Transfers screen.
 */
function render_page(): void {
	if ( ! current_user_can_approve() ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to decide group ownership transfers.', 'wordcamporg' ) );
	}

	$pending_rows = get_sites_with_pending_transfers();
	$recent_rows  = get_recent_decided_transfers();
	$updated      = isset( $_GET['updated'] ) ? sanitize_key( wp_unslash( $_GET['updated'] ) ) : '';

	// A failed lookup must read as "we could not check", never as the
	// identical-looking "there is nothing to approve" empty state below.
	$pending_error = is_wp_error( $pending_rows ) ? $pending_rows : null;

	if ( $pending_error ) {
		$pending_rows = array();
	}

	if ( is_wp_error( $recent_rows ) ) {
		$recent_rows = array();
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Ownership Transfers', 'wordcamporg' ); ?></h1>

		<?php if ( $pending_error ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $pending_error->get_error_message() ); ?></p></div>
		<?php endif; ?>

		<?php if ( in_array( $updated, array( 'approved', 'rejected' ), true ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>
				<?php
				echo esc_html(
					'approved' === $updated
						? __( 'Transfer approved and completed.', 'wordcamporg' )
						: __( 'Transfer rejected.', 'wordcamporg' )
				);
				?>
			</p></div>
		<?php endif; ?>

		<p>
			<?php esc_html_e( 'Group owners who want to hand off their group nominate a candidate, who must explicitly accept before a transfer lands here. Approving swaps the roles immediately; rejecting cancels the request.', 'wordcamporg' ); ?>
		</p>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Group', 'wordcamporg' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Current owner', 'wordcamporg' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Nominated owner', 'wordcamporg' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Initiated', 'wordcamporg' ); ?></th>
					<th scope="col" style="width:22%;"><?php esc_html_e( 'Action', 'wordcamporg' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $pending_rows ) ) : ?>
					<tr><td colspan="5">
						<?php
						echo esc_html(
							$pending_error
								? __( 'The list of pending transfers could not be loaded.', 'wordcamporg' )
								: __( 'No transfers are awaiting approval.', 'wordcamporg' )
						);
						?>
					</td></tr>
				<?php else : ?>
					<?php foreach ( $pending_rows as $row ) : ?>
						<?php
						$site_id      = (int) $row['site']->blog_id;
						$pending      = $row['pending'];
						$group_name   = get_blog_option( $site_id, 'blogname' );
						$from_user    = get_userdata( (int) $pending['from_user_id'] );
						$to_user      = get_userdata( (int) $pending['to_user_id'] );
						$initiated_by = get_userdata( (int) $pending['initiated_by'] );
						$awaiting     = STATUS_PENDING_ACCEPTANCE === $pending['status'];
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $group_name ?: $row['site']->domain . $row['site']->path ); ?></strong>
								<br />
								<a href="<?php echo esc_url( get_home_url( $site_id, '/' ) ); ?>"><?php echo esc_html( get_home_url( $site_id, '/' ) ); ?></a>
							</td>
							<td><?php echo esc_html( $from_user ? $from_user->display_name : __( '(deleted user)', 'wordcamporg' ) ); ?></td>
							<td><?php echo esc_html( $to_user ? $to_user->display_name : __( '(deleted user)', 'wordcamporg' ) ); ?></td>
							<td>
								<?php
								printf(
									/* translators: 1: who initiated the transfer, 2: date initiated. */
									esc_html__( 'by %1$s on %2$s', 'wordcamporg' ),
									esc_html( $initiated_by ? $initiated_by->display_name : __( '(deleted user)', 'wordcamporg' ) ),
									esc_html( wp_date( 'Y-m-d', (int) $pending['initiated_at'] ) )
								);
								?>
								<br />
								<em>
									<?php
									echo esc_html(
										$awaiting
											? __( 'Awaiting candidate acceptance.', 'wordcamporg' )
											: __( 'Accepted — awaiting your approval.', 'wordcamporg' )
									);
									?>
								</em>
							</td>
							<td>
								<?php if ( $awaiting ) : ?>
									<p class="description"><?php esc_html_e( 'Not yet accepted by the candidate.', 'wordcamporg' ); ?></p>
								<?php else : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:block; margin-bottom:8px;">
										<input type="hidden" name="action" value="<?php echo esc_attr( DECIDE_ACTION ); ?>" />
										<input type="hidden" name="site_id" value="<?php echo esc_attr( $site_id ); ?>" />
										<input type="hidden" name="decision" value="approve" />
										<?php wp_nonce_field( DECIDE_ACTION . '_' . $site_id ); ?>
										<button
											type="submit"
											class="button button-primary"
											onclick="return window.confirm( '<?php echo esc_js( __( 'Approve this transfer? Roles will be swapped immediately.', 'wordcamporg' ) ); ?>' );"
										><?php esc_html_e( 'Approve', 'wordcamporg' ); ?></button>
									</form>
								<?php endif; ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:block;">
									<input type="hidden" name="action" value="<?php echo esc_attr( DECIDE_ACTION ); ?>" />
									<input type="hidden" name="site_id" value="<?php echo esc_attr( $site_id ); ?>" />
									<input type="hidden" name="decision" value="reject" />
									<?php wp_nonce_field( DECIDE_ACTION . '_' . $site_id ); ?>
									<?php
									/*
									 * Optional, but worth offering: a rejection is the one
									 * outcome where both parties are told "no" by someone
									 * they never spoke to, and this is the only thing in the
									 * notification that can tell them why. Left blank, the
									 * email simply omits the reason line.
									 */
									$reason_field_id = 'wporg-transfer-reason-' . $site_id;
									?>
									<label class="screen-reader-text" for="<?php echo esc_attr( $reason_field_id ); ?>">
										<?php esc_html_e( 'Reason for rejecting this transfer. Optional; included in the email to both parties.', 'wordcamporg' ); ?>
									</label>
									<input
										type="text"
										id="<?php echo esc_attr( $reason_field_id ); ?>"
										name="reason"
										value=""
										maxlength="<?php echo esc_attr( REASON_MAX_LENGTH ); ?>"
										placeholder="<?php esc_attr_e( 'Reason (optional)', 'wordcamporg' ); ?>"
										title="<?php esc_attr_e( 'Included in the email sent to both parties.', 'wordcamporg' ); ?>"
										style="display:block; width:100%; margin-bottom:4px;"
									/>
									<button
										type="submit"
										class="button button-link-delete"
										onclick="return window.confirm( '<?php echo esc_js( __( 'Reject this transfer?', 'wordcamporg' ) ); ?>' );"
									><?php esc_html_e( 'Reject', 'wordcamporg' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( ! empty( $recent_rows ) ) : ?>
			<details style="margin-top: 2em;">
				<summary><strong><?php esc_html_e( 'Recently decided transfers', 'wordcamporg' ); ?></strong></summary>
				<table class="wp-list-table widefat fixed striped" style="margin-top: 1em;">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Group', 'wordcamporg' ); ?></th>
							<th scope="col"><?php esc_html_e( 'From', 'wordcamporg' ); ?></th>
							<th scope="col"><?php esc_html_e( 'To', 'wordcamporg' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Outcome', 'wordcamporg' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Decided', 'wordcamporg' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $recent_rows as $row ) : ?>
							<?php
							$site_id   = (int) $row['site']->blog_id;
							$entry     = $row['entry'];
							$from_user = get_userdata( (int) $entry['from_user_id'] );
							$to_user   = get_userdata( (int) $entry['to_user_id'] );
							?>
							<tr>
								<td><?php echo esc_html( get_blog_option( $site_id, 'blogname' ) ?: $row['site']->domain . $row['site']->path ); ?></td>
								<td><?php echo esc_html( $from_user ? $from_user->display_name : __( '(deleted user)', 'wordcamporg' ) ); ?></td>
								<td><?php echo esc_html( $to_user ? $to_user->display_name : __( '(deleted user)', 'wordcamporg' ) ); ?></td>
								<td><?php echo esc_html( get_final_status_label( $entry['final_status'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( ! empty( $entry['decided_at'] ) ? wp_date( 'Y-m-d', (int) $entry['decided_at'] ) : '' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</details>
		<?php endif; ?>
	</div>
	<?php
}
