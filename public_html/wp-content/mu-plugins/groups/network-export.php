<?php
/**
 * Network-admin export of event + RSVP-count history across all group sites.
 *
 * The network-wide half of the export feature (#1780): the per-group export in
 * `wporg-groups-frontend/inc/export.php` gives one group's organisers their
 * own full history; this gives Program Managers (network admins) the whole
 * network's picture in one file.
 *
 * Deliberately aggregate-only: per event, the RSVP counts by status — never
 * attendee names, logins, or emails. A network-wide file travels further than
 * any one group's, so identities stay out of it until there's an explicit
 * consent basis for including them (see the issue's privacy discussion). The
 * per-site collector below counts RSVPs in SQL rather than reusing the
 * per-group collector, so attendee rows are never even loaded into this
 * process.
 *
 * One row per event *instance*: a recurring series contributes a row per
 * occurrence, dated and counted separately, because the questions this export
 * answers — which groups are active, which have gone quiet, is attendance
 * growing — are all about instances held. Collapsing a year of monthly
 * meetups into one row dated last January would report an active group as
 * dormant.
 *
 * Generation runs on cron, a few sites per batch, rather than in the request:
 * walking every group site's events and RSVPs synchronously would time out as
 * the network grows. The cursor, the rows collected so far, and the finished
 * artifact each live in a network option; downloads are rendered from the
 * artifact on demand.
 *
 * Loaded on the groups network only (sits in the `groups/` mu-plugins folder).
 *
 * @package WordCamp\Groups
 */

namespace WordCamp\Groups\Network_Export;

use WordCamp\Logger;
use WP_Error;

defined( 'WPINC' ) || die();

/**
 * Capability required to generate and download the export. `manage_network`
 * is what makes someone a Program Manager on this network, and this tool
 * reads every group at once.
 */
const CAPABILITY = 'manage_network';

/** Network Admin page slug. */
const MENU_SLUG = 'wporg-groups-export';

/** `admin_post_` action the start-export form submits to. */
const FORM_ACTION = 'wporg_groups_start_network_export';

/** `admin_post_` action the cancel button submits to. */
const CANCEL_ACTION = 'wporg_groups_cancel_network_export';

/** `admin_post_` action the download links point at. */
const DOWNLOAD_ACTION = 'wporg_groups_download_network_export';

/** Cron hook that processes the export in batches. */
const CRON_HOOK = 'wporg_groups_export_batch';

/**
 * Network option holding the in-flight job. Its presence is the "an export
 * is running" signal; there is deliberately no queue — two overlapping runs
 * would produce the same snapshot, so a second start while one is in flight
 * is just refused.
 */
const JOB_OPTION = 'wporg_groups_export_job';

/**
 * Network option holding the rows collected so far, keyed by site ID.
 *
 * Kept out of `JOB_OPTION` so the cursor stays small: it's read and written
 * once per site, and the screen reads it on every load just to show progress.
 * Keying by site also makes a re-collected site idempotent — a site retried
 * after a crash overwrites its own rows instead of appending them twice.
 */
const ROWS_OPTION = 'wporg_groups_export_job_rows';

/**
 * Network option holding the last completed export's rows and metadata.
 *
 * Size bound: one aggregate row is ~200 bytes serialized, and memcached caps
 * a cached item at ~1 MB, so past roughly 5,000 event rows the option still
 * works but stops being cached. Revisit with chunked storage if the network
 * ever approaches that.
 */
const EXPORT_OPTION = 'wporg_groups_export_artifact';

/** Object-cache key serialising read-modify-write cycles on `JOB_OPTION`. */
const LOCK_KEY = 'job_lock';

/** Object-cache group for `LOCK_KEY`. Registered global, see below. */
const LOCK_GROUP = 'wporg-groups-export';

/**
 * Seconds before an abandoned lock expires on its own, so a process that dies
 * mid-update can't wedge the export permanently.
 */
const LOCK_TIMEOUT = 30;

/** Lock acquisition attempts, `LOCK_RETRY_DELAY` apart. */
const LOCK_ATTEMPTS = 20;

/** Microseconds between lock acquisition attempts. */
const LOCK_RETRY_DELAY = 50000;

/**
 * Sites processed per cron run. A unit of work here is a whole site's bulk
 * collect — several queries — so this is much smaller than the messaging
 * tool's per-recipient batch size.
 */
const SITES_PER_BATCH = 10;

/**
 * Seconds a batch's claim on the job stays valid. Long enough for a slow
 * `SITES_PER_BATCH` sites; short enough that a process killed mid-batch
 * doesn't stall the export for long before another run can resume it.
 */
const CLAIM_LEASE = 5 * MINUTE_IN_SECONDS;

/**
 * How many times a single site may be claimed before the export gives up on
 * it. A site whose collection kills the process (an uncatchable fatal, an
 * OOM) never gets to record itself as failed, so without this the same site
 * would be re-claimed after every lease expiry, forever.
 */
const MAX_SITE_ATTEMPTS = 3;

/**
 * Column order for the CSV download: one flat row per event instance,
 * aggregate counts only.
 *
 * `recurrence_id` and `occurrence_status` are empty for one-off events. For a
 * recurring series they identify the instance, so a cancelled occurrence can
 * be filtered out rather than silently counting as activity.
 */
const CSV_COLUMNS = array(
	'group_name',
	'group_url',
	'event_id',
	'event_title',
	'event_start_gmt',
	'event_end_gmt',
	'recurrence_id',
	'occurrence_status',
	'venue',
	'attending_count',
	'waiting_list_count',
	'not_attending_count',
);

// Priority 11: the parent "Groups" menu is registered by the archive screen
// at priority 9, and submenus registered before their parent break.
add_action( 'network_admin_menu', __NAMESPACE__ . '\add_page', 11 );
add_action( 'admin_post_' . FORM_ACTION, __NAMESPACE__ . '\handle_start_export' );
add_action( 'admin_post_' . CANCEL_ACTION, __NAMESPACE__ . '\handle_cancel_export' );
add_action( 'admin_post_' . DOWNLOAD_ACTION, __NAMESPACE__ . '\handle_download' );
add_action( CRON_HOOK, __NAMESPACE__ . '\process_batch' );

/*
 * `JOB_OPTION` is a network option, but `process_batch()` runs under whatever
 * blog happens to be current. Non-global cache groups are keyed per blog, so
 * without this two runs on different blogs would take two different locks and
 * not exclude each other at all.
 */
wp_cache_add_global_groups( array( LOCK_GROUP ) );

/**
 * Whether the current user may generate or download the network export.
 */
function current_user_can_export(): bool {
	return current_user_can( CAPABILITY );
}

/**
 * Register the Network Admin screen.
 */
function add_page(): void {
	add_submenu_page(
		\WordCamp\Groups\Archive\PAGE_SLUG,
		'Export Events',
		'Export Events',
		CAPABILITY,
		MENU_SLUG,
		__NAMESPACE__ . '\render_page'
	);
}

/**
 * Start an export run, unless one is already in flight.
 *
 * The previous artifact is deliberately left in place until the new run
 * finishes and replaces it: an export that fails halfway shouldn't also
 * destroy the last good file.
 *
 * @param int[] $site_ids Group sites to export.
 * @return array{status: string, id: string} `status` is `queued`, `busy` (a
 *         run is already in flight) or `locked` (a concurrent update held the
 *         job store). The two failures need different notices: one is worth
 *         retrying, the other isn't.
 */
function queue_export( array $site_ids ): array {
	$job = array(
		'id'            => wp_generate_uuid4(),
		'sites'         => array_values( $site_ids ),
		'pending_sites' => array_values( $site_ids ),
		'failed_sites'  => array(),
		'site_attempts' => array(),
		'author'        => get_current_user_id(),
		'created'       => time(),
		// Both set while a batch holds the job; see claim_job().
		'claimed_until' => 0,
		'claim_token'   => '',
	);

	$queued = with_job_lock(
		function () use ( $job ) {
			if ( get_job() ) {
				return false;
			}

			// Rows from any abandoned previous run are not this run's.
			delete_site_option( ROWS_OPTION );
			update_site_option( JOB_OPTION, $job );

			return true;
		}
	);

	if ( null === $queued ) {
		return array(
			'status' => 'locked',
			'id'     => '',
		);
	}

	if ( ! $queued ) {
		return array(
			'status' => 'busy',
			'id'     => '',
		);
	}

	schedule_next_batch();

	return array(
		'status' => 'queued',
		'id'     => $job['id'],
	);
}

/**
 * Run `$callback` with exclusive access to `JOB_OPTION`.
 *
 * The start form and the cron batch both read-modify-write the job option;
 * interleaved, one silently overwrites the other. Same mutex mechanics as
 * `Messaging\with_jobs_lock()` — `wp_cache_add()` is atomic on a shared
 * backend; without a persistent object cache this degrades to the
 * unsynchronised behaviour it replaces.
 *
 * @param callable $callback Runs while the lock is held.
 * @param bool     $steal    Run the callback anyway if the lock can't be
 *                           acquired, for writes where dropping the update
 *                           loses more than a rare clobber would.
 * @return mixed The callback's return value, or null if the lock wasn't
 *               acquired and `$steal` is false. Callers that need to tell
 *               "lock lost" from a legitimate result must return something
 *               non-null from the callback.
 */
function with_job_lock( callable $callback, bool $steal = false ) {
	$acquired = false;

	for ( $attempt = 0; $attempt < LOCK_ATTEMPTS; $attempt++ ) {
		if ( wp_cache_add( LOCK_KEY, 1, LOCK_GROUP, LOCK_TIMEOUT ) ) {
			$acquired = true;
			break;
		}

		usleep( LOCK_RETRY_DELAY );
	}

	if ( ! $acquired && ! $steal ) {
		return null;
	}

	try {
		return $callback();
	} finally {
		wp_cache_delete( LOCK_KEY, LOCK_GROUP );
	}
}

/**
 * Get the in-flight job, if any.
 */
function get_job(): array {
	$job = get_site_option( JOB_OPTION, array() );

	return is_array( $job ) ? $job : array();
}

/**
 * A short human label for a site, for failure reporting.
 *
 * Uses the path rather than the blogname: reading a blogname means switching
 * into the site, which for a site that just failed to collect could fail
 * again — outside any restore.
 *
 * @param int $site_id Site ID.
 */
function site_label( int $site_id ): string {
	try {
		$site = get_site( $site_id );
	} catch ( \Throwable $error ) {
		// This runs on the failure path; it must not become a second failure
		// that escapes before the site is recorded.
		return "site {$site_id}";
	}

	return $site ? untrailingslashit( $site->path ) : "site {$site_id}";
}

/**
 * The rows collected so far, keyed by site ID.
 *
 * @return array<int, array[]>
 */
function get_collected_rows(): array {
	$rows = get_site_option( ROWS_OPTION, array() );

	return is_array( $rows ) ? $rows : array();
}

/**
 * Schedule the next batch, unless one is already due.
 */
function schedule_next_batch(): void {
	if ( wp_next_scheduled( CRON_HOOK ) ) {
		return;
	}

	$scheduled = wp_schedule_single_event( time(), CRON_HOOK );

	if ( false === $scheduled ) {
		trigger_error(
			'Failed to schedule the next group export batch -- `wp_schedule_single_event()` returned false. The export will not finish on its own.',
			E_USER_WARNING
		);
	}
}

/**
 * Take the in-flight job for this run, if it's available to take.
 *
 * Claiming stamps a lease and a per-claim token onto the stored job rather
 * than removing it: deleting it would make the run invisible to the busy
 * check, letting a concurrent start queue a second job for this batch to
 * later clobber. The token is what makes the commit safe — the job ID is the
 * same for every batch of a run, so an overrunning batch could otherwise
 * commit its stale cursor over the progress of the batch that replaced it.
 *
 * Call inside `with_job_lock()`.
 *
 * @return array{status: string, job: array} `status` is one of `claimed`,
 *         `none` (nothing to do) or `leased` (another run holds it).
 */
function claim_job(): array {
	$job = get_job();

	if ( empty( $job ) ) {
		return array(
			'status' => 'none',
			'job'    => array(),
		);
	}

	if ( ! empty( $job['claimed_until'] ) && $job['claimed_until'] > time() ) {
		return array(
			'status' => 'leased',
			'job'    => array(),
		);
	}

	$job['claim_token']   = wp_generate_uuid4();
	$job['claimed_until'] = time() + CLAIM_LEASE;

	update_site_option( JOB_OPTION, $job );

	return array(
		'status' => 'claimed',
		'job'    => $job,
	);
}

/**
 * Process up to `SITES_PER_BATCH` group sites of the in-flight export.
 *
 * The job is claimed under the lock, the per-site collection runs unlocked
 * (holding the lock across several sites' queries would block the start form
 * for that long), and the commit re-checks that the stored job still carries
 * this run's claim token. If the process dies mid-batch, the lease expires
 * and a later run resumes from the last committed cursor.
 */
function process_batch(): void {
	$claim = with_job_lock( __NAMESPACE__ . '\claim_job' );

	if ( null === $claim ) {
		/*
		 * Lock contention, not "nothing to do" — and the cron event that got
		 * us here is already consumed, so without rescheduling the export
		 * would sit idle until someone loaded the screen.
		 */
		schedule_next_batch();

		return;
	}

	if ( 'claimed' !== $claim['status'] ) {
		if ( 'leased' === $claim['status'] ) {
			// Another run holds the job; make sure a tick is still queued for
			// whatever it leaves behind.
			schedule_next_batch();
		}

		return;
	}

	$token     = $claim['job']['claim_token'];
	$processed = 0;

	/*
	 * Each site's outcome is persisted as it goes, so a `Throwable` escaping
	 * here costs at most the site in flight — but log it and fall through to
	 * `finalize_job()` anyway, which clears the lease so the next tick can
	 * resume immediately instead of waiting it out.
	 */
	try {
		while ( $processed < SITES_PER_BATCH ) {
			$site_id = with_job_lock(
				function () use ( $token ) {
					return begin_site( $token );
				},
				true
			);

			if ( ! $site_id ) {
				break;
			}

			++$processed;

			try {
				$rows = collect_site_rows( $site_id );
			} catch ( \Throwable $site_error ) {
				$rows = new WP_Error( 'export_site_crashed', $site_error->getMessage() );
			}

			with_job_lock(
				function () use ( $token, $site_id, $rows ) {
					finish_site( $token, $site_id, $rows );
				},
				// The site's rows only exist in memory at this point;
				// abandoning the write would mean collecting it all over again.
				true
			);
		}
	} catch ( \Throwable $error ) {
		// Hand-built array rather than the Throwable itself; see the
		// equivalent note in `Messaging\process_batch()` about `redact_keys()`
		// mangling non-Exception Throwables.
		Logger\log(
			'groups_export_batch_crashed',
			array(
				'job'   => $claim['job']['id'],
				'error' => array(
					'class'   => get_class( $error ),
					'message' => $error->getMessage(),
					'file'    => $error->getFile(),
					'line'    => $error->getLine(),
				),
			)
		);
	}

	$finished = with_job_lock(
		function () use ( $token ) {
			return finalize_job( $token );
		},
		true
	);

	if ( ! $finished ) {
		schedule_next_batch();
	}
}

/**
 * Take the next pending site, charging an attempt against it first.
 *
 * The attempt is persisted *before* the site is read, and the cursor is left
 * pointing at it, so a fatal during collection is charged to the site that
 * actually died. Counting at claim time instead would charge every retry to
 * whichever site happened to be first in the batch — dropping healthy groups
 * from the export while the poisonous one kept running.
 *
 * Call inside `with_job_lock()`.
 *
 * @param string $token This run's claim token.
 * @return int The site to collect, or 0 when there's nothing left to do.
 */
function begin_site( string $token ): int {
	$job = get_job();

	if ( empty( $job ) || ( $job['claim_token'] ?? '' ) !== $token ) {
		return 0;
	}

	while ( ! empty( $job['pending_sites'] ) ) {
		$head     = (int) $job['pending_sites'][0];
		$attempts = (int) ( $job['site_attempts'][ $head ] ?? 0 ) + 1;

		$job['site_attempts'][ $head ] = $attempts;

		if ( $attempts <= MAX_SITE_ATTEMPTS ) {
			update_site_option( JOB_OPTION, $job );

			return $head;
		}

		/*
		 * A site that has killed the process this many times never gets to
		 * record itself as failed, so record it here and move on — otherwise
		 * it would be re-claimed after every lease expiry, forever.
		 */
		array_shift( $job['pending_sites'] );

		$job['failed_sites'][ $head ] = site_label( $head );

		Logger\log(
			'groups_export_site_abandoned',
			array(
				'job'      => $job['id'],
				'site'     => $head,
				'attempts' => $attempts,
			)
		);
	}

	update_site_option( JOB_OPTION, $job );

	return 0;
}

/**
 * Record one site's result and advance the cursor past it.
 *
 * Call inside `with_job_lock()`.
 *
 * @param string         $token   This run's claim token.
 * @param int            $site_id The site just collected.
 * @param array[]|object $rows    Its rows, or a `WP_Error`.
 */
function finish_site( string $token, int $site_id, $rows ): void {
	$job = get_job();

	// A different token means another run took the job over while this site
	// was being read; its cursor is authoritative, not ours.
	if ( empty( $job ) || ( $job['claim_token'] ?? '' ) !== $token ) {
		return;
	}

	if ( ! empty( $job['pending_sites'] ) && (int) $job['pending_sites'][0] === $site_id ) {
		array_shift( $job['pending_sites'] );
	}

	if ( is_wp_error( $rows ) ) {
		// A failed site is recorded, not silently omitted — a file that
		// quietly misses a group reads as "that group had no events".
		$job['failed_sites'][ $site_id ] = site_label( $site_id );

		Logger\log(
			'groups_export_site_failed',
			array(
				'job'   => $job['id'],
				'site'  => $site_id,
				'error' => $rows->get_error_code(),
			)
		);
	} else {
		$collected             = get_collected_rows();
		$collected[ $site_id ] = $rows;

		update_site_option( ROWS_OPTION, $collected );
	}

	update_site_option( JOB_OPTION, $job );
}

/**
 * Store the artifact and clear the job, once nothing is pending.
 *
 * Call inside `with_job_lock()`.
 *
 * @param string $token This run's claim token.
 * @return bool Whether the export is finished and stored.
 */
function finalize_job( string $token ): bool {
	$job = get_job();

	/*
	 * Only finalize our own claim. A different token means this batch overran
	 * its lease and another run took the job over — finishing on their behalf
	 * would publish a snapshot mid-collection.
	 */
	if ( empty( $job ) || ( $job['claim_token'] ?? '' ) !== $token ) {
		return false;
	}

	$job['claimed_until'] = 0;
	$job['claim_token']   = '';

	if ( ! empty( $job['pending_sites'] ) ) {
		update_site_option( JOB_OPTION, $job );

		return false;
	}

	/*
	 * Clear the job only once the artifact is safely stored: dropping it after
	 * a failed write would leave the screen looking as if no export had ever
	 * run, with this run's work gone.
	 */
	if ( ! record_artifact( $job ) ) {
		update_site_option( JOB_OPTION, $job );

		Logger\log( 'groups_export_artifact_write_failed', array( 'job' => $job['id'] ) );

		return false;
	}

	delete_site_option( JOB_OPTION );
	delete_site_option( ROWS_OPTION );

	return true;
}

/**
 * Collect one group site's events as aggregate export rows.
 *
 * Counts RSVPs with one grouped SQL query rather than reusing the per-group
 * collector: that one loads every RSVP comment, its meta, and a `WP_User` per
 * attendee to build records this export immediately discards — unbounded work
 * in an unattended cron process, and it would materialise attendee PII inside
 * the network-wide run this file exists to keep identities out of.
 *
 * @param int $site_id Group site ID.
 * @return array[]|WP_Error Aggregate rows, or an error describing why this
 *                          site couldn't be read.
 */
function collect_site_rows( int $site_id ) {
	$site = get_site( $site_id );

	/*
	 * `get_group_site_ids()` is evaluated once when the export starts, and
	 * batches run minutes or hours later. A site deleted or archived in
	 * between would point `$wpdb` at dropped tables and quietly collect zero
	 * events, which reads exactly like a group that held none.
	 */
	if ( ! $site || $site->deleted || $site->archived || $site->spam ) {
		return new WP_Error( 'export_site_unavailable', 'The site is no longer available for export.' );
	}

	if ( ! class_exists( '\GatherPress\Core\Event\Event' ) ) {
		return new WP_Error( 'export_gatherpress_missing', 'GatherPress is not loaded.' );
	}

	// The switch sits inside the try: it pushes the switched-blog stack before
	// firing its action, so a Throwable from a `switch_blog` listener would
	// otherwise leave the process stuck on the wrong blog.
	try {
		switch_to_blog( $site_id );

		$events = get_posts(
			array(
				'post_type'              => \GatherPress\Core\Event\Event::POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				// Only IDs and titles are exported; priming meta and term
				// caches for every event would be pure overhead here.
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		if ( empty( $events ) ) {
			return array();
		}

		$event_ids = array_map( 'intval', wp_list_pluck( $events, 'ID' ) );

		$dates = \WordCamp\Groups\Frontend\Export\get_event_dates( $event_ids );

		if ( is_wp_error( $dates ) ) {
			return $dates;
		}

		$counts = get_rsvp_counts( $event_ids );

		if ( is_wp_error( $counts ) ) {
			return $counts;
		}

		$occurrences = get_occurrences( $event_ids );

		if ( is_wp_error( $occurrences ) ) {
			return $occurrences;
		}

		$venues     = \WordCamp\Groups\Frontend\Export\get_event_venue_names( $event_ids );
		$group_name = get_bloginfo( 'name' );
		$group_url  = home_url();

		$rows = array();

		foreach ( $events as $event ) {
			$event_id          = (int) $event->ID;
			$event_counts      = $counts[ $event_id ] ?? array();
			$event_occurrences = $occurrences[ $event_id ] ?? array();

			/*
			 * One row per instance: each projected occurrence, plus a row for
			 * any RSVPs that carry no occurrence (a one-off event, or RSVPs
			 * predating the recurring-events plugin) so no count is dropped.
			 * A recurrence with no occurrence row left — a deleted instance —
			 * keeps its own row for the same reason.
			 */
			$keys = array_keys( $event_occurrences );

			foreach ( array_keys( $event_counts ) as $counted ) {
				if ( ! isset( $event_occurrences[ $counted ] ) ) {
					$keys[] = $counted;
				}
			}

			if ( empty( $keys ) ) {
				$keys = array( '' );
			}

			foreach ( $keys as $key ) {
				$occurrence     = $event_occurrences[ $key ] ?? null;
				$instance_count = $event_counts[ $key ] ?? array();

				$rows[] = array(
					'group_name'          => $group_name,
					'group_url'           => $group_url,
					'event_id'            => $event_id,
					// Raw title: texturizing and entity-encoding belong to
					// HTML output, not to a data export.
					'event_title'         => $event->post_title,
					'event_start_gmt'     => $occurrence['start_gmt'] ?? $dates[ $event_id ]['start_gmt'] ?? '',
					'event_end_gmt'       => $occurrence['end_gmt'] ?? $dates[ $event_id ]['end_gmt'] ?? '',
					'recurrence_id'       => (string) $key,
					'occurrence_status'   => $occurrence['status'] ?? '',
					'venue'               => $venues[ $event_id ] ?? '',
					'attending_count'     => (int) ( $instance_count['attending'] ?? 0 ),
					'waiting_list_count'  => (int) ( $instance_count['waiting_list'] ?? 0 ),
					'not_attending_count' => (int) ( $instance_count['not_attending'] ?? 0 ),
				);
			}
		}

		return $rows;
	} finally {
		restore_current_blog();
	}
}

/**
 * Count approved RSVPs per event instance and status on the current site.
 *
 * One grouped query over the comment/term join. GatherPress stores an RSVP as
 * a `gatherpress_rsvp` comment carrying one `_gatherpress_rsvp_status` term,
 * so counting in SQL needs no comment or user objects at all.
 *
 * Every occurrence of a recurring series hangs its RSVPs off the same series
 * post, so the recurring-events plugin's mapping table is joined in to split
 * them by occurrence. Without that join a year of monthly meetups would count
 * as one event. RSVPs with no mapping row land under the empty key.
 *
 * @param int[] $event_ids Event post IDs.
 * @return array<int, array<string, array<string, int>>>|WP_Error Counts keyed
 *         by event ID, then recurrence ID (empty when unmapped), then status.
 */
function get_rsvp_counts( array $event_ids ) {
	global $wpdb;

	if ( empty( $event_ids ) ) {
		return array();
	}

	$placeholders = implode( ', ', array_fill( 0, count( $event_ids ), '%d' ) );

	$recurrence_select = "'' AS recurrence_id";
	$recurrence_join   = '';

	if ( class_exists( '\WordPressdotorg\GatherPress_Recurring_Events\Database' ) ) {
		$mapping_table     = \WordPressdotorg\GatherPress_Recurring_Events\Database::comments_table();
		$recurrence_select = 'COALESCE( occurrences.recurrence_id, %s ) AS recurrence_id';
		$recurrence_join   = "LEFT JOIN {$mapping_table} AS occurrences ON occurrences.comment_id = comments.comment_ID";
	}

	// The `%s` above, when present, comes first in the argument list.
	$query_args = '' === $recurrence_join ? $event_ids : array_merge( array( '' ), $event_ids );

	/*
	 * Disabled across the whole statement rather than per line: the query is a
	 * multi-line string, so a single-line ignore wouldn't cover the
	 * interpolation. Only table names and a locally generated `%d` list are
	 * interpolated; every value goes through `prepare()`.
	 */
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT comments.comment_post_ID AS event_id, {$recurrence_select}, terms.slug AS status, COUNT(*) AS total
			FROM {$wpdb->comments} AS comments
			INNER JOIN {$wpdb->term_relationships} AS relationships ON relationships.object_id = comments.comment_ID
			INNER JOIN {$wpdb->term_taxonomy} AS taxonomy ON taxonomy.term_taxonomy_id = relationships.term_taxonomy_id
			INNER JOIN {$wpdb->terms} AS terms ON terms.term_id = taxonomy.term_id
			{$recurrence_join}
			WHERE comments.comment_post_ID IN ( {$placeholders} )
				AND comments.comment_type = 'gatherpress_rsvp'
				AND comments.comment_approved = '1'
				AND taxonomy.taxonomy = '_gatherpress_rsvp_status'
			GROUP BY event_id, recurrence_id, terms.slug",
			$query_args
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

	if ( '' !== $wpdb->last_error ) {
		return new WP_Error( 'export_rsvp_count_failed', 'Could not count RSVPs for this site.' );
	}

	$counts = array();

	foreach ( (array) $rows as $row ) {
		$counts[ (int) $row->event_id ][ (string) $row->recurrence_id ][ $row->status ] = (int) $row->total;
	}

	return $counts;
}

/**
 * The projected occurrences of any recurring series among these events.
 *
 * @param int[] $event_ids Event post IDs.
 * @return array<int, array<string, array{start_gmt: string, end_gmt: string, status: string}>>|WP_Error
 *         Occurrences keyed by event ID then recurrence ID, oldest first.
 */
function get_occurrences( array $event_ids ) {
	global $wpdb;

	if ( empty( $event_ids ) || ! class_exists( '\WordPressdotorg\GatherPress_Recurring_Events\Database' ) ) {
		return array();
	}

	$table        = \WordPressdotorg\GatherPress_Recurring_Events\Database::occurrences_table();
	$placeholders = implode( ', ', array_fill( 0, count( $event_ids ), '%d' ) );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- As in get_rsvp_counts().
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT series_post_id, recurrence_id, datetime_start_gmt, datetime_end_gmt, status
			FROM {$table}
			WHERE series_post_id IN ( {$placeholders} )
			ORDER BY datetime_start_gmt ASC",
			$event_ids
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

	if ( '' !== $wpdb->last_error ) {
		return new WP_Error( 'export_occurrence_read_failed', 'Could not read occurrences for this site.' );
	}

	$occurrences = array();

	foreach ( (array) $rows as $row ) {
		$occurrences[ (int) $row->series_post_id ][ (string) $row->recurrence_id ] = array(
			'start_gmt' => $row->datetime_start_gmt,
			'end_gmt'   => $row->datetime_end_gmt,
			'status'    => $row->status,
		);
	}

	return $occurrences;
}

/**
 * Store the finished export, so the screen can offer it for download.
 *
 * @param array $job Completed job.
 * @return bool Whether the artifact was stored.
 */
function record_artifact( array $job ): bool {
	$failed    = (array) $job['failed_sites'];
	$collected = get_collected_rows();
	$rows      = array();

	// Flatten in the order the sites were queued, so the file reads
	// group-by-group rather than in whatever order the batches finished.
	foreach ( $job['sites'] as $site_id ) {
		if ( ! empty( $collected[ $site_id ] ) ) {
			$rows = array_merge( $rows, $collected[ $site_id ] );

			unset( $collected[ $site_id ] );
		}
	}

	return update_site_option(
		EXPORT_OPTION,
		array(
			'generated_gmt'       => gmdate( 'Y-m-d H:i:s' ),
			'author'              => (int) $job['author'],
			'site_count'          => count( $job['sites'] ),
			// The file only covers the sites that were actually read; keeping
			// the queued total as the headline number would claim coverage the
			// rows don't have.
			'exported_site_count' => count( $job['sites'] ) - count( $failed ),
			// Instances, not series: a recurring meetup contributes one row
			// per occurrence.
			'event_count'         => count( $rows ),
			'failed_sites'        => $failed,
			'rows'                => $rows,
		)
	);
}

/**
 * Handle the start-export form submission.
 */
function handle_start_export(): void {
	if ( ! current_user_can_export() ) {
		wp_die( 'You do not have permission to export group data.', 403 );
	}

	check_admin_referer( FORM_ACTION );

	$site_ids = \WordCamp\Groups\Messaging\get_group_site_ids();

	if ( empty( $site_ids ) ) {
		redirect_with_notice( 'no-groups' );
	}

	$queued = queue_export( $site_ids );

	if ( 'busy' === $queued['status'] ) {
		redirect_with_notice( 'busy' );
	}

	if ( 'queued' !== $queued['status'] ) {
		redirect_with_notice( 'queue-locked' );
	}

	redirect_with_notice( 'queued', count( $site_ids ) );
}

/**
 * Handle the cancel-export form submission.
 *
 * The escape hatch for a run that can't finish on its own — a site whose
 * collection keeps killing the process gets abandoned automatically after
 * `MAX_SITE_ATTEMPTS`, but an admin shouldn't have to wait for that, or for a
 * lease, to start a fresh run.
 */
function handle_cancel_export(): void {
	if ( ! current_user_can_export() ) {
		wp_die( 'You do not have permission to cancel this export.', 403 );
	}

	check_admin_referer( CANCEL_ACTION );

	$job = get_job();

	with_job_lock(
		function () {
			delete_site_option( JOB_OPTION );
			delete_site_option( ROWS_OPTION );
		},
		true
	);

	wp_clear_scheduled_hook( CRON_HOOK );

	if ( $job ) {
		Logger\log( 'groups_export_cancelled', array( 'job' => $job['id'] ) );
	}

	redirect_with_notice( 'cancelled' );
}

/**
 * Handle a download request for the last completed export.
 */
function handle_download(): void {
	$format = validate_download_request();

	if ( is_wp_error( $format ) ) {
		wp_die( esc_html( $format->get_error_message() ), 403 );
	}

	$payload = get_download_payload( $format );

	if ( is_wp_error( $payload ) ) {
		redirect_with_notice( 'no_export' === $payload->get_error_code() ? 'no-export' : 'download-failed' );
	}

	nocache_headers();
	header( 'Content-Type: ' . $payload['content_type'] );
	header( 'Content-Disposition: attachment; filename="' . $payload['filename'] . '"' );
	header( 'X-Content-Type-Options: nosniff' );

	echo $payload['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- File body; CSV cells are escaped by esc_csv_cell(), JSON by wp_json_encode().
	exit;
}

/**
 * Check a download request's capability and nonce, and resolve its format.
 *
 * Split out of `handle_download()` so the gates are reachable from tests
 * without the handler's terminal `exit`.
 *
 * @return string|WP_Error The requested format, or why the request was refused.
 */
function validate_download_request() {
	if ( ! current_user_can_export() ) {
		return new WP_Error( 'export_forbidden', 'You do not have permission to download group data.' );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This *is* the nonce check.
	$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

	if ( ! wp_verify_nonce( $nonce, DOWNLOAD_ACTION ) ) {
		return new WP_Error( 'export_bad_nonce', 'This download link has expired. Reload the page and try again.' );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified immediately above.
	$format = sanitize_key( wp_unslash( $_GET['format'] ?? 'csv' ) );

	return in_array( $format, array( 'csv', 'json' ), true ) ? $format : 'csv';
}

/**
 * Build the downloadable file for the last completed export.
 *
 * Rendered from the stored rows on every request rather than persisted per
 * format — the rows are small and this keeps the option at half the size.
 *
 * @param string $format 'csv' or 'json'.
 * @return array{filename: string, content_type: string, body: string}|WP_Error
 */
function get_download_payload( string $format ) {
	$artifact = get_site_option( EXPORT_OPTION );

	if ( ! is_array( $artifact ) || ! isset( $artifact['rows'] ) ) {
		return new WP_Error( 'no_export', 'No completed export is available.' );
	}

	$date     = substr( (string) $artifact['generated_gmt'], 0, 10 );
	$filename = sprintf( 'groups-network-events-%s.%s', $date, $format );

	if ( 'json' === $format ) {
		$body = wp_json_encode( build_network_json( $artifact ) );

		/*
		 * `wp_json_encode()` returns false for a document it can't encode — it
		 * repairs invalid UTF-8, but not a non-finite number or nesting past
		 * its depth limit. Returning `(string) false` would then serve an empty
		 * file with a 200 and an attachment header: an export that looks
		 * complete and contains nothing.
		 */
		if ( false === $body ) {
			return new WP_Error( 'export_encode_failed', 'The export could not be encoded as JSON. Try the CSV download.' );
		}

		return array(
			'filename'     => $filename,
			'content_type' => 'application/json; charset=utf-8',
			'body'         => $body,
		);
	}

	$body = build_network_csv( $artifact['rows'] );

	if ( null === $body ) {
		return new WP_Error( 'export_render_failed', 'The export could not be rendered as CSV.' );
	}

	return array(
		'filename'     => $filename,
		'content_type' => 'text/csv; charset=utf-8',
		'body'         => $body,
	);
}

/**
 * Flatten the artifact rows into a CSV file body.
 *
 * Same serialization rules as the per-group export: in-memory stream, UTF-8
 * BOM for Excel, RFC 4180 quoting (no escape character), and formula-trigger
 * escaping on every string cell.
 *
 * @param array[] $rows Aggregate rows.
 * @return string|null The file body, or null if the stream failed.
 */
function build_network_csv( array $rows ): ?string {
	// phpcs:disable WordPress.WP.AlternativeFunctions -- php://temp is an in-memory stream for fputcsv(), not a filesystem write; WP_Filesystem doesn't apply.
	$handle = fopen( 'php://temp', 'r+' );

	if ( false === $handle ) {
		return null;
	}

	fwrite( $handle, "\xEF\xBB\xBF" );
	fputcsv( $handle, CSV_COLUMNS, ',', '"', '' );

	foreach ( $rows as $row ) {
		$line = array();

		foreach ( CSV_COLUMNS as $column ) {
			$value  = $row[ $column ] ?? '';
			$line[] = is_string( $value )
				? \WordCamp\Groups\Frontend\Export\esc_csv_cell( $value )
				: $value;
		}

		fputcsv( $handle, $line, ',', '"', '' );
	}

	rewind( $handle );
	$csv = stream_get_contents( $handle );
	fclose( $handle );
	// phpcs:enable WordPress.WP.AlternativeFunctions

	// A `false` here would become an empty file rather than a failed request.
	return false === $csv ? null : $csv;
}

/**
 * Shape the artifact for the JSON download: groups nested over their events.
 *
 * @param array $artifact Stored artifact.
 */
function build_network_json( array $artifact ): array {
	$groups = array();

	foreach ( $artifact['rows'] as $row ) {
		$key = $row['group_url'];

		if ( ! isset( $groups[ $key ] ) ) {
			$groups[ $key ] = array(
				'name'   => $row['group_name'],
				'url'    => $row['group_url'],
				'events' => array(),
			);
		}

		$groups[ $key ]['events'][] = array(
			'id'                => $row['event_id'],
			'title'             => $row['event_title'],
			'start_gmt'         => $row['event_start_gmt'],
			'end_gmt'           => $row['event_end_gmt'],
			// Empty for one-off events; set per instance for a series.
			'recurrence_id'     => $row['recurrence_id'] ?? '',
			'occurrence_status' => $row['occurrence_status'] ?? '',
			'venue'             => $row['venue'],
			'counts'            => array(
				'attending'     => $row['attending_count'],
				'waiting_list'  => $row['waiting_list_count'],
				'not_attending' => $row['not_attending_count'],
			),
		);
	}

	$failed = array();
	foreach ( (array) ( $artifact['failed_sites'] ?? array() ) as $site_id => $name ) {
		$failed[] = array(
			'site_id' => (int) $site_id,
			'name'    => $name,
		);
	}

	return array(
		'generated_gmt'       => $artifact['generated_gmt'],
		'site_count'          => (int) $artifact['site_count'],
		'exported_site_count' => (int) ( $artifact['exported_site_count'] ?? $artifact['site_count'] ),
		'event_count'         => (int) $artifact['event_count'],
		'failed_sites'        => $failed,
		'groups'              => array_values( $groups ),
	);
}

/**
 * Redirect back to the export screen with a notice, and exit.
 *
 * @param string $notice Notice slug.
 * @param int    $groups Number of groups the export covers.
 */
function redirect_with_notice( string $notice, int $groups = 0 ): void {
	$url = add_query_arg(
		array(
			'page'   => MENU_SLUG,
			'notice' => $notice,
			'groups' => $groups,
		),
		network_admin_url( 'admin.php' )
	);

	wp_safe_redirect( $url );
	exit;
}

/**
 * Render the notice for the current request, if any.
 */
function render_notice(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of the result of an already-verified POST.
	$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';

	if ( ! $notice ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Ditto.
	$groups = isset( $_GET['groups'] ) ? absint( $_GET['groups'] ) : 0;

	$messages = array(
		'queued'          => array(
			'success',
			sprintf(
				/* translators: %s: number of groups. */
				_n(
					'Export started for %s group. It runs in the background — reload this page to see progress.',
					'Export started for %s groups. It runs in the background — reload this page to see progress.',
					$groups,
					'wporg-groups-frontend'
				),
				number_format_i18n( $groups )
			),
		),
		'cancelled'       => array( 'success', 'The running export was cancelled.' ),
		'busy'            => array( 'error', 'An export is already running. Wait for it to finish, or cancel it, before starting another.' ),
		'no-groups'       => array( 'error', 'There are no group sites to export.' ),
		'queue-locked'    => array( 'error', 'The export could not be started because another update was in progress. Please try again.' ),
		'no-export'       => array( 'error', 'No completed export is available to download. Start one first.' ),
		'download-failed' => array( 'error', 'The export could not be prepared for download — see the error log.' ),
	);

	if ( ! isset( $messages[ $notice ] ) ) {
		return;
	}

	list( $type, $text ) = $messages[ $notice ];

	printf(
		'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
		esc_attr( $type ),
		esc_html( $text )
	);
}

/**
 * Render the last completed export's summary and download links.
 */
function render_summary(): void {
	$artifact = get_site_option( EXPORT_OPTION );

	if ( ! is_array( $artifact ) || ! isset( $artifact['rows'] ) ) {
		return;
	}

	$author   = get_userdata( (int) $artifact['author'] );
	$failed   = (array) ( $artifact['failed_sites'] ?? array() );
	$exported = (int) ( $artifact['exported_site_count'] ?? $artifact['site_count'] );

	printf(
		'<h2>Last export</h2><p class="description">%s</p>',
		esc_html(
			sprintf(
				'%1$s event(s) across %2$s of %3$s group(s), generated by %4$s on %5$s (UTC).%6$s',
				number_format_i18n( (int) $artifact['event_count'] ),
				number_format_i18n( $exported ),
				number_format_i18n( (int) $artifact['site_count'] ),
				$author ? $author->display_name : 'an unknown user',
				$artifact['generated_gmt'],
				$failed
					? sprintf(
						' %1$s site(s) could not be read and are missing from the file: %2$s — see the error log.',
						number_format_i18n( count( $failed ) ),
						implode( ', ', $failed )
					)
					: ''
			)
		)
	);

	$base = admin_url( 'admin-post.php?action=' . DOWNLOAD_ACTION );

	printf(
		'<p><a href="%1$s" class="button button-primary">Download CSV</a> <a href="%2$s" class="button">Download JSON</a></p>',
		esc_url( wp_nonce_url( $base . '&format=csv', DOWNLOAD_ACTION ) ),
		esc_url( wp_nonce_url( $base . '&format=json', DOWNLOAD_ACTION ) )
	);
}

/**
 * Render the export screen.
 */
function render_page(): void {
	if ( ! current_user_can_export() ) {
		wp_die( 'You do not have permission to access this page.', 403 );
	}

	$job = get_job();

	?>
	<div class="wrap">
		<h1>Export Events</h1>

		<?php render_notice(); ?>

		<p>
			Download every group's event history with its RSVP breakdown as CSV or JSON.
			The export contains aggregate RSVP counts only — no attendee names or emails.
			Recurring events are listed one row per occurrence, each with its own date
			and counts.
		</p>

		<?php if ( $job ) : ?>
			<p>
				<strong>
					Exporting&hellip;
					<?php
					echo esc_html(
						sprintf(
							'%1$s of %2$s sites processed.',
							number_format_i18n( count( $job['sites'] ) - count( $job['pending_sites'] ) ),
							number_format_i18n( count( $job['sites'] ) )
						)
					);
					?>
				</strong>
				Reload this page to see progress.
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( CANCEL_ACTION ); ?>" />
				<?php wp_nonce_field( CANCEL_ACTION ); ?>
				<?php submit_button( 'Cancel export', 'secondary', 'submit', false ); ?>
				<span class="description">Stops the run so you can start a fresh one. The last completed export stays downloadable.</span>
			</form>
			<?php
			// Self-heal: if the cron event was lost (see schedule_next_batch's
			// warning path), visiting this page re-arms it.
			schedule_next_batch();
			?>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( FORM_ACTION ); ?>" />
				<?php wp_nonce_field( FORM_ACTION ); ?>
				<?php submit_button( 'Start export' ); ?>
			</form>
		<?php endif; ?>

		<?php render_summary(); ?>
	</div>
	<?php
}
