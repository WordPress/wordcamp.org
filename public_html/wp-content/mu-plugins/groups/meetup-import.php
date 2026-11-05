<?php
/**
 * Network-admin tool for importing a Meetup group into a group site.
 *
 * Moving a group off Meetup.com (#1788): pull one Meetup group's events,
 * venues, and optionally members from the Meetup Pro API into a chosen group
 * site.
 *
 * - Upcoming events land published, ready for the group to RSVP to.
 * - Past events (opt-in) land published, as the group's history, with
 *   Meetup's RSVP and attendance counts kept as meta. Individual RSVPs aren't
 *   imported.
 * - People (opt-in) are only ever matched to existing WordPress.org accounts
 *   by email: Meetup organizers get the matching role, hosts are credited,
 *   and RSVPs are kept. No account is created for anyone else.
 * - The rest of the group's members (opt-in, per import) join the group as
 *   Members with event emails off when they match an account. Only for a
 *   group whose members were told on Meetup first that they'd be added, and
 *   that they can leave: someone who leaves isn't added back by a later
 *   import (#2305).
 *
 * Nothing here sends an email: events are published with the new-event
 * notification marked as already handled, and
 * matched members are opted out of this group's event updates until they
 * choose otherwise.
 *
 * Re-running the import is safe. Events and venues are keyed on their Meetup
 * IDs: an event still in draft (one an earlier import left as a draft) is
 * refreshed from Meetup, while a published (or trashed) one is the
 * organizer's and is never touched again. Members are
 * considered once per group, so someone who leaves isn't added back.
 *
 * Imports run from the import screen, whatever the group's size: while it's
 * open, the screen sends one request per batch, the first filling the preview
 * in from Meetup and, after Import, the rest writing it (see `run_step()`).
 * Closing the screen pauses the import; opening it again carries on. That's
 * also what makes re-running safe matter: a batch that dies is simply redone.
 *
 * Only loaded on the Groups network, via
 * `load-other-mu-plugins.php::wcorg_include_network_only_plugins()`.
 *
 * @package WordCamp\Groups
 */

namespace WordCamp\Groups\Meetup_Import;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Exception;
use GatherPress\Core\Event\Event;
use GatherPress\Core\Rsvp\Rsvp;
use GatherPress\Core\Venue\Venue;
use WordCamp\Groups\Frontend\Check_In;
use WordCamp\Groups\Frontend\Defaults;
use WordCamp\Groups\Frontend\Event_Hosts;
use WordCamp\Groups\Frontend\Event_Language;
use WordCamp\Groups\Frontend\Group_Location;
use WordCamp\Logger;
use WordPressdotorg\GatherPress_Recurring_Events\Rule;
use WordPressdotorg\MU_Plugins\Utilities\Meetup_Client;
use WP_Error;
use WP_User;

use function WordCamp\Groups\Frontend\Notifications\get_scoped_opt_in_meta_key;
use function WordCamp\Groups\Frontend\REST\storable_timezone;
use function WordCamp\Groups\Frontend\REST\sync_event_venue;
use const WordCamp\Groups\Frontend\Notifications\PUBLISH_NOTIFICATION_SCHEDULED_META;

defined( 'WPINC' ) || die();

const PAGE_SLUG      = 'meetup-import';
const EVENT_ID_META  = '_wporg_meetup_event_id';
const EVENT_URL_META = '_wporg_meetup_event_url';
const VENUE_ID_META  = '_wporg_meetup_venue_id';
const RSVP_YES_META  = '_wporg_meetup_rsvp_yes_count';
const ATTENDED_META  = '_wporg_meetup_attended_count';
const PHOTO_ID_META  = '_wporg_meetup_photo_id';

/**
 * When an import last finished writing an event, as its `post_modified_gmt`.
 *
 * A draft modified since then has been edited on the site, and from then on
 * belongs to the organizer: reruns leave it alone rather than overwrite their
 * changes with Meetup's.
 */
const IMPORTED_MODIFIED_META = '_wporg_meetup_imported_modified';

/**
 * Marks a draft an import has started refreshing but not finished.
 *
 * Refreshing a draft changes its `post_modified_gmt` before the import gets to
 * record it in `IMPORTED_MODIFIED_META`, so a run that dies in between would
 * otherwise leave the import's own change looking like an organizer's edit,
 * and every later run would leave the half-refreshed draft alone.
 */
const REFRESHING_META = '_wporg_meetup_refreshing';

/**
 * Post meta on a recurring event: the Meetup series it was imported from.
 *
 * Meetup lists each date of a repeating event as its own event, all carrying
 * the same `series.id`. A rerun finds the recurring event by it, and knows to
 * leave those dates alone, even once the dates it was made from have passed.
 */
const SERIES_ID_META = '_wporg_meetup_series_id';

/**
 * Site options recording which Meetup group a site was imported from, and
 * when that group was founded, for the group's history.
 */
const GROUP_URLNAME_OPTION = 'wporg_meetup_group_urlname';
const FOUNDED_OPTION       = 'wporg_meetup_founded_date';

/**
 * The site role each Meetup organizer role maps to.
 *
 * Meetup's organizers and co-organizers run the group, the full "Organizer"
 * tier here (`editor`); assistant and event organizers run events, the "Event
 * Organizer" tier (`author`). See `Frontend\Capabilities\ORGANIZER_ROLES`.
 * Nobody is ever made an `administrator` by an import -- that stays with the
 * lead organizer the site was created for.
 */
const MEETUP_ORGANIZER_ROLES = array(
	'ORGANIZER'       => 'editor',
	'COORGANIZER'     => 'editor',
	'ASST_ORGANIZER'  => 'author',
	'EVENT_ORGANIZER' => 'author',
);

/**
 * How the site roles rank, so an import only ever promotes.
 */
const ROLE_RANK = array(
	'subscriber'    => 0,
	'contributor'   => 0,
	'author'        => 1,
	'editor'        => 2,
	'administrator' => 3,
);

/**
 * GatherPress RSVP status for each Meetup one, and whether it means they came.
 *
 * Meetup's waiting list comes over as "attending" and GatherPress places it:
 * on an event with room it promotes waitlisted RSVPs straight away anyway,
 * and on a full one it puts an attending RSVP on the waiting list itself
 * (`Rsvp::constrain_rsvp_intent()`). Saving `waiting_list` directly on an
 * event with room also trips a bug in GatherPress 0.35's waiting-list
 * promotion (`Rsvp::check_waiting_list()` walks past the end of the list).
 *
 * On a past event the waiting list is left out instead: those people never
 * got a place, and on a date with no check-ins an attending RSVP is what
 * counts as attended (`Frontend\My_Events\filter_to_attended()`).
 *
 * Meetup statuses with no equivalent (maybe, no-show, excused, haven't
 * answered) are left out rather than guessed at.
 */
const RSVP_STATUSES = array(
	'YES'                 => array( 'attending', false ),
	'YES_PENDING_PAYMENT' => array( 'attending', false ),
	'ATTENDED'            => array( 'attending', true ),
	'NO'                  => array( 'not_attending', false ),
	'WAITLIST'            => array( 'attending', false ),
);

/**
 * Marks an imported past event that still has to be published.
 *
 * Set when the event is created and cleared once it's published, so a run
 * that dies in between leaves something the next run knows to finish.
 */
const PENDING_PUBLISH_META = '_wporg_meetup_pending_publish';

/**
 * Site option listing the Meetup member IDs an import has already considered.
 *
 * Only Meetup IDs are kept, never emails, so a rerun can tell a member it has
 * seen before (and who may since have left the group) from a new one.
 */
const MEMBERS_OPTION = 'wporg_meetup_imported_member_ids';

/**
 * Site option listing the users an import has added to the group, or found
 * already in it, whichever way it got to them: as a member, an organizer, or
 * an event host.
 *
 * Someone on this list who isn't in the group any more has left it, and no
 * import adds them back (see `ensure_member()`).
 */
const IMPORTED_USERS_OPTION = 'wporg_meetup_imported_user_ids';

/**
 * Site option listing the users an import has already handled as organizers.
 *
 * Their role is the group's to change from then on: if the group demotes
 * one, a later import mustn't promote them back.
 */
const IMPORTED_ORGANIZERS_OPTION = 'wporg_meetup_imported_organizer_ids';

/**
 * How many times a job may fail at the same point before it's given up on.
 *
 * Each retry re-runs the batch, and a fetch retry calls Meetup again on the
 * shared connection, so a batch that keeps crashing mustn't loop forever.
 */
const MAX_ATTEMPTS = 3;

/**
 * Option row that marks an import batch as running, and how long it may hold it.
 *
 * One batch at a time, across the whole network: events are looked up and
 * then inserted, so two requests working the same job at once (two open tabs,
 * or a retry while the first request still runs) could both miss an event and
 * both create it. It also keeps imports to one batch's load at a time, however
 * many screens are open. The expiry frees the lock if a batch dies without
 * releasing it -- which, with the shutdown release in `process_batch()`, only
 * happens if the process is killed outright. A batch renews it before each
 * event (see `extend_lock()`), so a slow batch isn't mistaken for a dead one.
 */
const LOCK_OPTION  = 'wporg_meetup_import_lock';
const LOCK_TIMEOUT = 5 * MINUTE_IN_SECONDS;

/**
 * The placeholder venue Meetup attaches to online events.
 *
 * Mirrors `Meetup_Client::ONLINE_VENUE_ID`. It's repeated rather than
 * referenced so that normalizing an event doesn't load the client, whose
 * OAuth class requires the Meetup credentials to be defined.
 */
const ONLINE_VENUE_ID = 26906060;

/**
 * The import jobs, and the requests that work through them.
 *
 * Every import runs as a job, whatever the group's size: Preview queues a job
 * that the screen's first request fills in from Meetup, and Import has later
 * requests write it a batch at a time. Each group site has at most one job, in
 * its own network option (the prefix plus the site ID). The screen sends the
 * next request when the last one answers (see `run_step()`).
 */
const JOB_OPTION_PREFIX = 'wporg_meetup_import_job_';
const START_ACTION      = 'wporg_meetup_import_start';
const CONFIRM_ACTION    = 'wporg_meetup_import_confirm';
const CANCEL_ACTION     = 'wporg_meetup_import_cancel';
const STEP_ACTION       = 'wporg_meetup_import_step';
const EVENTS_PER_BATCH  = 10;
const MEMBERS_PER_BATCH = 200;

/**
 * How long the screen waits before asking again when another batch holds the lock.
 */
const BUSY_RETRY_SECONDS = 5;

/**
 * How long a job may go untouched before it expires, and how long a finished
 * job stays on the screen.
 *
 * A job holds the group's Meetup data, including member emails when people
 * are matched, so a preview nobody imports, or an import whose screen nobody
 * opens again, is expired rather than kept.
 */
const IDLE_TTL     = DAY_IN_SECONDS;
const FINISHED_TTL = 30 * DAY_IN_SECONDS;

/**
 * Hourly cron event, on the Groups network's root site, that runs `sweep_jobs()`
 * while any job exists.
 *
 * Only cleanup, never batches. Without it, a job whose page everyone has
 * closed keeps its Meetup data until someone opens the import screen again.
 * The root site's cron only runs when someone loads one of its admin pages
 * (its front end redirects before WordPress loads), so this is best effort:
 * it widens cleanup from the import screen to any network admin visit.
 */
const SWEEP_HOOK = 'wporg_meetup_import_sweep';

add_action( 'network_admin_menu', __NAMESPACE__ . '\register_admin_page' );
add_action( 'admin_post_' . START_ACTION, __NAMESPACE__ . '\handle_start' );
add_action( 'admin_post_' . CONFIRM_ACTION, __NAMESPACE__ . '\handle_confirm' );
add_action( 'admin_post_' . CANCEL_ACTION, __NAMESPACE__ . '\handle_cancel' );
add_action( 'wp_ajax_' . STEP_ACTION, __NAMESPACE__ . '\handle_step' );
add_action( SWEEP_HOOK, __NAMESPACE__ . '\sweep_jobs' );

/**
 * Add the "Import from Meetup" screen under Network Admin > Groups.
 */
function register_admin_page() {
	add_submenu_page(
		\WordCamp\Groups\Archive\PAGE_SLUG,
		__( 'Import from Meetup', 'wordcamporg' ),
		__( 'Import from Meetup', 'wordcamporg' ),
		'manage_sites',
		PAGE_SLUG,
		__NAMESPACE__ . '\render_admin_page'
	);
}

/**
 * The network option holding a site's import job.
 *
 * @param int $site_id Group site.
 */
function job_option( int $site_id ): string {
	return JOB_OPTION_PREFIX . $site_id;
}

/**
 * A site's import job, or an empty array for none.
 *
 * A job moves through these statuses:
 *
 * - `fetching`: queued by Preview; the screen's next request fetches the
 *   group from Meetup and works out what an import would do.
 * - `ready`: the preview is in the job, waiting for someone to click Import.
 * - `importing`: the screen's requests are writing it, one batch each, in the
 *   order group details, organizers, members, events.
 * - `done`, `failed`, or `expired` (untouched for `IDLE_TTL`): finished; kept
 *   for `FINISHED_TTL` so the screen can show how it went, unless the site's
 *   next Preview replaces it first.
 *
 * @param int $site_id Group site.
 */
function get_job( int $site_id ): array {
	$job = get_site_option( job_option( $site_id ), array() );

	return is_array( $job ) ? $job : array();
}

/**
 * A site's import job, read from the database rather than the options cache.
 *
 * `save_job()` writes past the cache, and another request may have saved the
 * job since this one first read it, so a batch works from what's stored now.
 *
 * @param int $site_id Group site.
 */
function get_stored_job( int $site_id ): array {
	global $wpdb;

	$job = maybe_unserialize(
		$wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- see above.
			$wpdb->prepare(
				"SELECT meta_value FROM $wpdb->sitemeta WHERE site_id = %d AND meta_key = %s",
				get_current_network_id(),
				job_option( $site_id )
			)
		)
	);

	return is_array( $job ) ? $job : array();
}

/**
 * Every site's import job, read from the database as `get_stored_job()` does.
 *
 * @return array[]
 */
function get_jobs(): array {
	global $wpdb;

	$values = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- see `get_stored_job()`.
		$wpdb->prepare(
			"SELECT meta_value FROM $wpdb->sitemeta WHERE site_id = %d AND meta_key LIKE %s ORDER BY meta_id",
			get_current_network_id(),
			$wpdb->esc_like( JOB_OPTION_PREFIX ) . '%'
		)
	);

	return array_values( array_filter( array_map( 'maybe_unserialize', $values ), 'is_array' ) );
}

/**
 * Whether an unfinished job has gone untouched for longer than `IDLE_TTL`.
 *
 * A preview nobody imported, or an import whose screen nobody opened again.
 *
 * @param array $job Import job.
 */
function is_job_expired( array $job ): bool {
	return in_array( $job['status'] ?? '', array( 'fetching', 'ready', 'importing' ), true )
		&& ( $job['updated'] ?? 0 ) < time() - IDLE_TTL;
}

/**
 * Whether a job still has work to do (or is waiting to be confirmed).
 *
 * @param array $job Import job.
 */
function is_job_active( array $job ): bool {
	return in_array( $job['status'] ?? '', array( 'fetching', 'ready', 'importing' ), true ) && ! is_job_expired( $job );
}

/**
 * Whether a job has a batch for the screen to run next.
 *
 * @param array $job Import job.
 */
function is_job_running( array $job ): bool {
	return in_array( $job['status'] ?? '', array( 'fetching', 'importing' ), true ) && ! is_job_expired( $job );
}

/**
 * A `LIKE` pattern that matches this job's stored row, and no other.
 *
 * The row holds the job's ID serialized as `s:2:"id";s:36:"<uuid>"`, so a
 * cancelled job (row deleted) or a replaced one (different ID) doesn't match.
 *
 * @param array $job Import job.
 */
function job_row_pattern( array $job ): string {
	global $wpdb;

	return '%' . $wpdb->esc_like( serialize( 'id' ) . serialize( (string) $job['id'] ) ) . '%'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- matching the stored serialized form.
}

/**
 * Save a job, unless it has been cancelled or replaced in the meantime.
 *
 * A batch holds its own copy of the job while it works. Without this check, a
 * batch that was running when someone clicked Cancel would write the job back
 * when it finished, and the cancelled import would carry on.
 *
 * @param array $job Import job.
 * @return bool Whether it was saved.
 */
function save_job( array $job ): bool {
	global $wpdb;

	$job['updated'] = time();
	$option         = job_option( (int) $job['site_id'] );

	// One conditional UPDATE, rather than reading the stored job and then
	// writing it: a cancel or a new preview landing between a read and a
	// write would otherwise be overwritten.
	$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- must bypass the option cache to be atomic.
		$wpdb->prepare(
			"UPDATE $wpdb->sitemeta SET meta_value = %s WHERE site_id = %d AND meta_key = %s AND meta_value LIKE %s",
			maybe_serialize( $job ),
			get_current_network_id(),
			$option,
			job_row_pattern( $job )
		)
	);

	wp_cache_delete( get_current_network_id() . ':' . $option, 'site-options' );

	return 1 === $updated;
}

/**
 * Delete a job, unless it has been replaced in the meantime.
 *
 * @param array $job Import job.
 */
function delete_job( array $job ): void {
	global $wpdb;

	$option = job_option( (int) $job['site_id'] );

	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- as in `save_job()`.
		$wpdb->prepare(
			"DELETE FROM $wpdb->sitemeta WHERE site_id = %d AND meta_key = %s AND meta_value LIKE %s",
			get_current_network_id(),
			$option,
			job_row_pattern( $job )
		)
	);

	wp_cache_delete( get_current_network_id() . ':' . $option, 'site-options' );
}

/**
 * Queue a new import job, for the screen to fetch from Meetup.
 *
 * Callers MUST verify the `manage_sites` capability before calling this, as
 * with `import_events()`.
 *
 * @param string $urlname       Meetup group urlname.
 * @param int    $site_id       Target group site.
 * @param bool   $include_past  Whether to import past events.
 * @param bool   $match_people  Whether to match people to WordPress.org accounts by email.
 * @param bool   $match_members Whether matched members join the group too. Only
 *                              with `$match_people`.
 * @return array|WP_Error The queued job.
 */
function start_job( string $urlname, int $site_id, bool $include_past, bool $match_people, bool $match_members = false ) {
	if ( '' === $urlname ) {
		return new WP_Error( 'invalid_group', __( 'Please enter a Meetup group URL or name.', 'wordcamporg' ) );
	}

	$is_valid = validate_target_site( $site_id );

	if ( is_wp_error( $is_valid ) ) {
		return $is_valid;
	}

	if ( is_job_active( get_job( $site_id ) ) ) {
		return new WP_Error( 'import_running', __( 'A Meetup import into that site is already in progress. Let it finish, or cancel it, first.', 'wordcamporg' ) );
	}

	$job = array(
		'id'           => wp_generate_uuid4(),
		'status'       => 'fetching',
		'urlname'      => $urlname,
		'site_id'      => $site_id,
		'include_past' => $include_past,
		'match_people' => $match_people,
		'match_members' => $match_people && $match_members,
		'user_id'      => get_current_user_id(),
		'created'      => time(),
		'updated'      => time(),
	);

	update_site_option( job_option( $site_id ), $job );
	schedule_sweep();

	Logger\log( 'job_queued', array_intersect_key( $job, array_flip( array( 'id', 'urlname', 'site_id', 'include_past', 'match_people', 'match_members' ) ) ) );

	return $job;
}

/**
 * Turn a previewed job into an import.
 *
 * Callers MUST verify the `manage_sites` capability before calling this.
 *
 * @param int    $site_id Group site the job imports into.
 * @param string $job_id  The job the person confirming was looking at, so a
 *                        stale screen can't confirm a different job.
 * @return array|WP_Error The confirmed job.
 */
function confirm_job( int $site_id, string $job_id ) {
	$job = get_job( $site_id );

	if ( 'ready' !== ( $job['status'] ?? '' ) || is_job_expired( $job ) || $job_id !== $job['id'] ) {
		return new WP_Error( 'job_not_ready', __( 'That preview is no longer current. Please preview again.', 'wordcamporg' ) );
	}

	$job['status']  = 'importing';
	$job['cursor']  = array(
		'step'   => 'group',
		'offset' => 0,
	);
	$job['results'] = array(
		'group_results'    => array(),
		'organizer_counts' => array(),
		'member_counts'    => array(),
		'events'           => array(),
	);

	save_job( $job );

	return $job;
}

/**
 * Cancel a site's job, whatever state it's in.
 *
 * What it already wrote stays: everything an import writes is safe to keep,
 * and a later import of the same group picks up from it. A screen still open
 * on the job finds it gone on its next request, and stops.
 *
 * @param int $site_id Group site.
 */
function cancel_job( int $site_id ): void {
	$job = get_job( $site_id );

	delete_site_option( job_option( $site_id ) );

	if ( $job ) {
		Logger\log( 'job_cancelled', array( 'id' => $job['id'] ) );
	}
}

/**
 * Mark a job that went untouched for `IDLE_TTL` as expired, dropping the
 * Meetup data it held.
 *
 * An import that expires partway keeps its cursor, so the screen can say it
 * stopped partway; what it already wrote stays, as with a cancel.
 *
 * @param array $job Import job, `fetching`, `ready`, or `importing`.
 */
function expire_job( array $job ): void {
	$job['status'] = 'expired';

	unset( $job['data'] );

	save_job( $job );

	Logger\log( 'job_expired', array( 'id' => $job['id'] ) );
}

/**
 * Expire jobs left untouched, and delete finished ones after `FINISHED_TTL`.
 *
 * Runs whenever the import screen loads, before every batch, and hourly
 * through `SWEEP_HOOK` while any job is left. It queues that event again if
 * it went missing, and clears it once the last job is gone.
 */
function sweep_jobs(): void {
	foreach ( get_jobs() as $job ) {
		if ( is_job_expired( $job ) ) {
			expire_job( $job );
		} elseif ( ! is_job_active( $job ) && ( $job['updated'] ?? 0 ) < time() - FINISHED_TTL ) {
			delete_job( $job );
		}
	}

	if ( get_jobs() ) {
		schedule_sweep();
	} else {
		with_site(
			GROUPS_ROOT_BLOG_ID,
			static function () {
				wp_clear_scheduled_hook( SWEEP_HOOK );
			}
		);
	}
}

/**
 * Queue the hourly sweep on the Groups network's root site, unless it's queued already.
 *
 * Always the root site, whichever site the request is on, as with the import
 * lock (see `lock_table()`).
 */
function schedule_sweep(): void {
	with_site(
		GROUPS_ROOT_BLOG_ID,
		static function () {
			if ( ! wp_next_scheduled( SWEEP_HOOK ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', SWEEP_HOOK );
			}
		}
	);
}

/**
 * Run the next batch of a site's job, for one request from the import screen.
 *
 * Expires old jobs first, then runs at most one batch, and reports where the
 * job got to (see `describe_step()`).
 *
 * @param int $site_id Group site.
 * @return array What the screen needs to show, and whether to ask again.
 */
function run_step( int $site_id ): array {
	sweep_jobs();

	$ran = process_batch( $site_id );

	return describe_step( get_stored_job( $site_id ), $ran );
}

/**
 * Write one batch of a site's job.
 *
 * The import lock keeps two requests from working at once: one that can't take
 * it leaves the job alone, and the screen asks again shortly. The lock is
 * released when the request ends, even on a timeout (see `release_lock()`),
 * and the batch it was on is simply redone by the next request -- everything a
 * batch writes is safe to write twice.
 *
 * @param int $site_id Group site.
 * @return bool False if another batch held the lock, so nothing ran.
 */
function process_batch( int $site_id ): bool {
	// `ready` is waiting on a person, and finished jobs are done: there's
	// nothing to do, and nothing to count against them.
	if ( ! is_job_running( get_stored_job( $site_id ) ) ) {
		return true;
	}

	if ( ! acquire_lock() ) {
		return false;
	}

	register_shutdown_function( __NAMESPACE__ . '\release_lock' );

	try {
		// Read again now that this request holds the lock, in case another
		// request moved the job on, or cancelled it, in the meantime.
		$job = get_stored_job( $site_id );

		if ( is_job_running( $job ) ) {
			// Counted, and saved, before the batch runs, so a batch that kills the
			// process is still charged for it. Reset whenever a batch makes progress.
			$job['attempts'] = ( $job['attempts'] ?? 0 ) + 1;

			if ( $job['attempts'] > MAX_ATTEMPTS ) {
				fail_job(
					$job,
					new WP_Error(
						'job_stuck',
						sprintf(
							/* translators: 1: Number of attempts, 2: What the import was doing. */
							__( 'Gave up after %1$d failed attempts while %2$s. Anything already written stays; preview again to retry.', 'wordcamporg' ),
							MAX_ATTEMPTS,
							'fetching' === $job['status'] ? __( 'fetching from Meetup', 'wordcamporg' ) : sprintf(
								/* translators: %s: Import step, e.g. "events". */
								__( 'importing %s', 'wordcamporg' ),
								$job['cursor']['step'] ?? ''
							)
						)
					)
				);
			} else {
				save_job( $job );

				if ( 'fetching' === $job['status'] ) {
					run_fetch( $job );
				} else {
					run_import_batch( $job );
				}
			}
		}
	} catch ( \Throwable $error ) {
		Logger\log(
			'batch_crashed',
			array(
				'job'   => $job['id'] ?? '',
				'error' => array(
					'class'   => get_class( $error ),
					'message' => $error->getMessage(),
					'file'    => $error->getFile(),
					'line'    => $error->getLine(),
				),
			)
		);
	} finally {
		release_lock();
	}

	return true;
}

/**
 * Fetch a job's group from Meetup, and work out what importing it would do.
 *
 * Fetched once: the import writes what Meetup had when the preview was made.
 * A later import of the same group picks up anything changed since.
 *
 * @param array $job Import job, `fetching`.
 */
function run_fetch( array $job ): void {
	$is_valid = validate_target_site( $job['site_id'] );

	if ( is_wp_error( $is_valid ) ) {
		fail_job( $job, $is_valid );
		return;
	}

	$raw = array(
		'group'      => fetch_group( $job['urlname'] ),
		'events'     => fetch_group_events( $job['urlname'], $job['include_past'] ),
		'members'    => ! empty( $job['match_members'] ) ? fetch_group_members( $job['urlname'] ) : array(),
		'organizers' => $job['match_people'] ? fetch_group_organizers( $job['urlname'] ) : array(),
		'connection' => $job['match_people'] ? fetch_connection_member_id() : '',
	);

	foreach ( $raw as $fetched ) {
		if ( is_wp_error( $fetched ) ) {
			fail_job( $job, $fetched );
			return;
		}
	}

	$raw = without_connection_account( $raw, $raw['connection'] );

	$job['data'] = array(
		'group'      => normalize_group( $raw['group'] ),
		'events'     => fold_series( $job['site_id'], array_map( __NAMESPACE__ . '\normalize_event', $raw['events'] ) ),
		'members'    => array_map( __NAMESPACE__ . '\normalize_member', $raw['members'] ),
		'organizers' => array_map( __NAMESPACE__ . '\normalize_organizer', $raw['organizers'] ),
	);

	$job['plan'] = array(
		'group_results'    => plan_group( $job['site_id'], $job['data']['group'] ),
		'events'           => array_map( __NAMESPACE__ . '\summarize_event_row', plan_events( $job['site_id'], $job['data']['events'] ) ),
		'organizer_counts' => $job['match_people'] ? plan_organizers( $job['site_id'], $job['data']['organizers'] ) : null,
		'member_counts'    => ! empty( $job['match_members'] ) ? plan_members( $job['site_id'], $job['data']['members'], $job['data']['organizers'] ) : null,
	);

	$job['status']   = 'ready';
	$job['attempts'] = 0;

	save_job( $job );
}

/**
 * Write the next batch of a confirmed job.
 *
 * Each run does one step's worth: the group details, then all the organizers
 * (a handful), then `MEMBERS_PER_BATCH` members, then `EVENTS_PER_BATCH`
 * events at a time, since an event can mean a photo download and several
 * pages of RSVPs. The cursor and results are saved after every batch.
 *
 * @param array $job Import job, `importing`.
 */
function run_import_batch( array $job ): void {
	$site_id = $job['site_id'];
	$data    = $job['data'];
	$step    = $job['cursor']['step'];
	$offset  = $job['cursor']['offset'];

	if ( 'group' === $step ) {
		$results = import_group( $site_id, $data['group'] );

		if ( is_wp_error( $results ) ) {
			fail_job( $job, $results );
			return;
		}

		$job['results']['group_results'] = $results;
		$job['cursor']                   = array(
			'step'   => $job['match_people'] ? 'organizers' : 'events',
			'offset' => 0,
		);
	} elseif ( 'organizers' === $step ) {
		$job['results']['organizer_counts'] = import_organizers( $site_id, $data['organizers'] );
		$job['cursor']                      = array(
			'step'   => ! empty( $job['match_members'] ) ? 'members' : 'events',
			'offset' => 0,
		);
	} elseif ( 'members' === $step && empty( $job['match_members'] ) ) {
		// A job queued before `match_members` was its own choice can still be
		// paused here; it goes on to the events without them.
		$job['cursor'] = array(
			'step'   => 'events',
			'offset' => 0,
		);
	} elseif ( 'members' === $step ) {
		$counts = import_members( $site_id, array_slice( $data['members'], $offset, MEMBERS_PER_BATCH ) );

		if ( is_wp_error( $counts ) ) {
			fail_job( $job, $counts );
			return;
		}

		$job['results']['member_counts'] = add_counts( $job['results']['member_counts'], $counts );
		$offset                         += MEMBERS_PER_BATCH;
		$job['cursor']                   = $offset < count( $data['members'] )
			? array(
				'step'   => 'members',
				'offset' => $offset,
			)
			: array(
				'step'   => 'events',
				'offset' => 0,
			);
	} elseif ( 'events' === $step ) {
		$results = import_events( $site_id, array_slice( $data['events'], $offset, EVENTS_PER_BATCH ), $job['match_people'] );

		if ( is_wp_error( $results ) ) {
			fail_job( $job, $results );
			return;
		}

		$job['results']['events'] = array_merge(
			$job['results']['events'],
			array_map( __NAMESPACE__ . '\summarize_event_row', $results )
		);
		$offset                  += EVENTS_PER_BATCH;
		$job['cursor']['offset']  = $offset;

		if ( $offset >= count( $data['events'] ) ) {
			$job['status']   = 'done';
			$job['finished'] = time();

			// The fetched data was only needed to write the import.
			unset( $job['data'] );

			Logger\log(
				'job_finished',
				array(
					'id'     => $job['id'],
					'events' => array_count_values( wp_list_pluck( $job['results']['events'], 'status' ) ),
				)
			);
		}
	}

	$job['attempts'] = 0;

	save_job( $job );
}

/**
 * Mark a job as failed, with the reason to show.
 *
 * @param array    $job   Import job.
 * @param WP_Error $error What went wrong.
 */
function fail_job( array $job, WP_Error $error ): void {
	$job['status'] = 'failed';
	$job['error']  = $error->get_error_message();

	unset( $job['data'] );

	save_job( $job );

	Logger\log(
		'job_failed',
		array(
			'id'    => $job['id'],
			'error' => $job['error'],
		)
	);
}

/**
 * Add one set of outcome counts to another.
 *
 * @param array $totals Running totals.
 * @param array $counts Counts to add.
 * @return array
 */
function add_counts( array $totals, array $counts ): array {
	foreach ( $counts as $key => $count ) {
		$totals[ $key ] = ( $totals[ $key ] ?? 0 ) + $count;
	}

	return $totals;
}

/**
 * Keep only what the screen shows of an event row, so the job stays small.
 *
 * @param array $row From `plan_events()` or `import_events()`.
 * @return array
 */
function summarize_event_row( array $row ): array {
	$event = $row['event'];

	return array(
		'event'   => array(
			'title'      => $event['title'],
			'start'      => $event['start'],
			'meetup_url' => $event['meetup_url'],
			'is_online'  => $event['is_online'],
			'is_past'    => $event['is_past'],
			'venue'      => $event['venue'] ? array( 'name' => $event['venue']['name'] ) : null,
		),
		'status'  => $row['status'],
		'message' => $row['message'] ?? '',
		'rsvps'   => $row['rsvps'] ?? null,
	);
}

/**
 * How many of an import's batches are done, out of how many.
 *
 * Counted in batches rather than people and events, since that's what each
 * request moves on by. Every step runs at least one batch, even with nothing
 * in it.
 *
 * @param array $job Import job.
 * @return int[] `[ done, total ]`, or `[ 0, 0 ]` for a job that isn't importing.
 */
function batch_progress( array $job ): array {
	if ( 'importing' !== ( $job['status'] ?? '' ) ) {
		return array( 0, 0 );
	}

	$people  = ! empty( $job['match_people'] );
	$members = ! empty( $job['match_members'] ) ? max( 1, (int) ceil( count( $job['data']['members'] ?? array() ) / MEMBERS_PER_BATCH ) ) : 0;
	$events  = max( 1, (int) ceil( count( $job['data']['events'] ?? array() ) / EVENTS_PER_BATCH ) );
	$step    = $job['cursor']['step'];
	$before  = array(
		'group'      => 0,
		'organizers' => 1,
		'members'    => 2,
		'events'     => $people ? 2 + $members : 1,
	);
	$per     = array(
		'members' => MEMBERS_PER_BATCH,
		'events'  => EVENTS_PER_BATCH,
	);
	$done    = $before[ $step ] + ( isset( $per[ $step ] ) ? intdiv( (int) $job['cursor']['offset'], $per[ $step ] ) : 0 );

	return array( $done, $before['events'] + $events );
}

/**
 * What the import screen gets back from one request.
 *
 * @param array $job Import job, as stored after the request's batch.
 * @param bool  $ran Whether a batch ran, rather than waiting on the lock.
 * @return array {
 *     @type string $status      The job's status, or '' if it's gone.
 *     @type bool   $running     Whether to send another request.
 *     @type string $message     The status line to show.
 *     @type string $step        The import step the job is on, if importing.
 *     @type int    $done        Batches done, for the progress bar.
 *     @type int    $total       Batches in all; 0 while it isn't known yet.
 *     @type int    $retry_after Seconds to wait before the next request.
 * }
 */
function describe_step( array $job, bool $ran ): array {
	list( $done, $total ) = batch_progress( $job );

	return array(
		'status'      => $job['status'] ?? '',
		'running'     => is_job_running( $job ),
		'message'     => $ran || ! $job ? describe_job_status( $job ) : __( 'Waiting for another import to finish.', 'wordcamporg' ),
		'step'        => $job['cursor']['step'] ?? '',
		'done'        => $done,
		'total'       => $total,
		'retry_after' => $ran ? 0 : BUSY_RETRY_SECONDS,
	);
}

/**
 * The import screen, or one site's import on it.
 *
 * @param int $site_id Group site, or 0 for the list of every import.
 */
function screen_url( int $site_id = 0 ): string {
	$url = network_admin_url( 'admin.php?page=' . PAGE_SLUG );

	return $site_id ? add_query_arg( 'site_id', $site_id, $url ) : $url;
}

/**
 * Send the browser back to the import screen, with a notice to show.
 *
 * @param string $notice  Notice key, see `render_notice()`.
 * @param int    $site_id Group site whose import to show, or 0 for the list.
 */
function redirect_with_notice( string $notice, int $site_id = 0 ): void {
	wp_safe_redirect( add_query_arg( 'notice', $notice, screen_url( $site_id ) ) );
	exit;
}

/**
 * Handle the Preview button: queue a job for the group.
 */
function handle_start(): void {
	if ( ! current_user_can( 'manage_sites' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to import events on this network.', 'wordcamporg' ), 403 );
	}

	check_admin_referer( START_ACTION );

	$site_id = isset( $_POST['site_id'] ) ? absint( wp_unslash( $_POST['site_id'] ) ) : 0;
	$job     = start_job(
		parse_group_urlname( isset( $_POST['meetup_group'] ) ? sanitize_text_field( wp_unslash( $_POST['meetup_group'] ) ) : '' ),
		$site_id,
		! empty( $_POST['include_past'] ),
		! empty( $_POST['match_people'] ),
		! empty( $_POST['match_members'] )
	);

	redirect_with_notice( is_wp_error( $job ) ? $job->get_error_code() : 'queued', is_wp_error( $job ) ? 0 : $site_id );
}

/**
 * Handle the Import button: start writing the previewed job.
 */
function handle_confirm(): void {
	if ( ! current_user_can( 'manage_sites' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to import events on this network.', 'wordcamporg' ), 403 );
	}

	check_admin_referer( CONFIRM_ACTION );

	$site_id = isset( $_POST['site_id'] ) ? absint( wp_unslash( $_POST['site_id'] ) ) : 0;
	$job     = confirm_job( $site_id, isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '' );

	redirect_with_notice( is_wp_error( $job ) ? $job->get_error_code() : 'confirmed', $site_id );
}

/**
 * Handle the Cancel button.
 */
function handle_cancel(): void {
	if ( ! current_user_can( 'manage_sites' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to import events on this network.', 'wordcamporg' ), 403 );
	}

	check_admin_referer( CANCEL_ACTION );

	$site_id = isset( $_POST['site_id'] ) ? absint( wp_unslash( $_POST['site_id'] ) ) : 0;

	cancel_job( $site_id );

	redirect_with_notice( 'cancelled' );
}

/**
 * Handle one request from the import screen: run the next batch, and say how it went.
 *
 * The screen sends the next request when this one answers, so the response
 * only goes out once the batch has finished and its cursor is saved.
 */
function handle_step(): void {
	if ( ! current_user_can( 'manage_sites' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to import events on this network.', 'wordcamporg' ), 403 );
	}

	check_ajax_referer( STEP_ACTION );

	$site_id = isset( $_POST['site_id'] ) ? absint( wp_unslash( $_POST['site_id'] ) ) : 0;

	// A batch that has started finishes, and saves where it got to, even if
	// the screen is closed or loses its connection meanwhile.
	ignore_user_abort( true );

	wp_send_json_success( run_step( $site_id ) );
}

/**
 * Show the notice a handler redirected with, if any.
 */
function render_notice(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
	$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';

	$notices = array(
		'queued'             => array( 'success', __( 'Preview started.', 'wordcamporg' ) ),
		'confirmed'          => array( 'success', __( 'Import started.', 'wordcamporg' ) ),
		'cancelled'          => array( 'success', __( 'Import cancelled. Anything it already wrote stays, and a later import of the group picks up from there.', 'wordcamporg' ) ),
		'import_running'     => array( 'error', __( 'A Meetup import into that site is already in progress. Let it finish, or cancel it, first.', 'wordcamporg' ) ),
		'job_not_ready'      => array( 'error', __( 'That preview is no longer current. Please preview again.', 'wordcamporg' ) ),
		'invalid_group'      => array( 'error', __( 'Please enter a Meetup group URL or name.', 'wordcamporg' ) ),
		'invalid_group_site' => array( 'error', __( 'Please choose an active group site to import into.', 'wordcamporg' ) ),
	);

	if ( isset( $notices[ $notice ] ) ) {
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $notices[ $notice ][0] ),
			esc_html( $notices[ $notice ][1] )
		);
	}
}

/**
 * Render the import screen: the form and every site's import, or one site's import.
 */
function render_admin_page() {
	if ( ! current_user_can( 'manage_sites' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to import events on this network.', 'wordcamporg' ) );
	}

	sweep_jobs();

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
	$site_id = isset( $_GET['site_id'] ) ? absint( wp_unslash( $_GET['site_id'] ) ) : 0;
	$job     = $site_id ? get_job( $site_id ) : array();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Import from Meetup', 'wordcamporg' ); ?></h1>

		<?php if ( ! is_meetup_connected() ) : ?>
			<div class="notice notice-warning"><p>
				<?php
				printf(
					/* translators: %s: URL of the Meetup connection settings screen. */
					wp_kses_post( __( 'Meetup is not connected on this network. <a href="%s">Connect it</a> before importing.', 'wordcamporg' ) ),
					esc_url( \WordCamp\Meetup_OAuth\get_page_url() )
				);
				?>
			</p></div>
		<?php endif; ?>

		<?php render_notice(); ?>

		<p>
			<?php esc_html_e( 'Copies a Meetup group into a group site: its location, About text, and photo where the site has none yet, and its upcoming events and venues, published. Optionally also its past events, and the people in it who have WordPress.org accounts. No emails are sent. An import runs from its page, a batch at a time, so keep that page open until it finishes: closing it pauses the import, and opening it again carries on. A preview nobody imports, or an import left paused, expires after a day. Running an import again adds new events and leaves the ones already on the site alone.', 'wordcamporg' ); ?>
		</p>

		<?php if ( $site_id ) : ?>
			<p><a href="<?php echo esc_url( screen_url() ); ?>"><?php esc_html_e( 'All imports', 'wordcamporg' ); ?></a></p>

			<?php if ( ! is_job_active( $job ) ) : ?>
				<?php render_start_form( $job ); ?>
			<?php endif; ?>

			<?php if ( $job ) : ?>
				<?php render_job( $job ); ?>
			<?php endif; ?>
		<?php else : ?>
			<?php render_start_form( array() ); ?>
			<?php render_job_list( get_jobs() ); ?>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * The table of every site's import, newest first, each linking to its details.
 *
 * @param array[] $jobs From `get_jobs()`.
 */
function render_job_list( array $jobs ): void {
	if ( ! $jobs ) {
		return;
	}

	usort(
		$jobs,
		static function ( $a, $b ) {
			return ( $b['created'] ?? 0 ) <=> ( $a['created'] ?? 0 );
		}
	);
	?>
	<h2><?php esc_html_e( 'Imports', 'wordcamporg' ); ?></h2>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Group site', 'wordcamporg' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Meetup group', 'wordcamporg' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Started by', 'wordcamporg' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'wordcamporg' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $jobs as $job ) : ?>
				<?php $user = get_userdata( (int) ( $job['user_id'] ?? 0 ) ); ?>
				<tr>
					<td>
						<a href="<?php echo esc_url( screen_url( (int) $job['site_id'] ) ); ?>">
							<?php
							/* translators: %d: Site ID. */
							echo esc_html( get_blog_option( $job['site_id'], 'blogname' ) ?: sprintf( __( 'Site %d', 'wordcamporg' ), $job['site_id'] ) );
							?>
						</a>
					</td>
					<td><?php echo esc_html( $job['urlname'] ); ?></td>
					<td><?php echo esc_html( $user ? $user->display_name : '' ); ?></td>
					<td><?php echo esc_html( describe_job_status( $job ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/**
 * The form that starts an import with a preview.
 *
 * @param array $job The last job, if any, to prefill from.
 */
function render_start_form( array $job ): void {
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( START_ACTION ); ?>" />
		<?php wp_nonce_field( START_ACTION ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="meetup_group"><?php esc_html_e( 'Meetup group', 'wordcamporg' ); ?></label></th>
				<td>
					<input name="meetup_group" type="text" id="meetup_group" class="regular-text" required="required" value="<?php echo esc_attr( $job['urlname'] ?? '' ); ?>" placeholder="https://www.meetup.com/wordpress-group/" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="site_id"><?php esc_html_e( 'Import into', 'wordcamporg' ); ?></label></th>
				<td>
					<select name="site_id" id="site_id" required="required">
						<option value=""><?php esc_html_e( 'Select a group site', 'wordcamporg' ); ?></option>
						<?php foreach ( \WordCamp\Groups\Archive\get_group_sites() as $group ) : ?>
							<option value="<?php echo esc_attr( $group->blog_id ); ?>" <?php selected( (int) $group->blog_id, (int) ( $job['site_id'] ?? 0 ) ); ?>>
								<?php echo esc_html( get_blog_option( $group->blog_id, 'blogname' ) ?: $group->domain . $group->path ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Also import', 'wordcamporg' ); ?></th>
				<td>
					<fieldset>
						<label>
							<input name="include_past" type="checkbox" value="1" <?php checked( ! empty( $job['include_past'] ) ); ?> />
							<?php esc_html_e( 'Past events, published as the group\'s history, with Meetup\'s RSVP and attendance counts', 'wordcamporg' ); ?>
						</label>
						<br />
						<label>
							<input name="match_people" type="checkbox" value="1" <?php checked( ! empty( $job['match_people'] ) ); ?> />
							<?php esc_html_e( 'Match people to WordPress.org accounts by email: Meetup organizers get the matching Organizer role, hosts are credited on their events, and RSVPs are kept. Nobody gets a new account.', 'wordcamporg' ); ?>
						</label>
						<br />
						<label>
							<input name="match_members" type="checkbox" value="1" <?php checked( ! empty( $job['match_members'] ) ); ?> />
							<?php esc_html_e( 'Also add the group\'s members who match an account, with event emails off. Needs "Match people".', 'wordcamporg' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Only tick this once the group\'s members were told on Meetup that they\'ll be added, and that they can leave the group anytime. Someone who leaves isn\'t added back by a later import.', 'wordcamporg' ); ?>
						</p>
					</fieldset>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Preview', 'wordcamporg' ), 'secondary' ); ?>
	</form>
	<?php
}

/**
 * Show a job: waiting on Meetup, its preview, its progress, or how it went.
 *
 * @param array $job Import job.
 */
function render_job( array $job ): void {
	$status     = $job['status'];
	$site_name  = get_blog_option( $job['site_id'], 'blogname' );
	$is_preview = 'ready' === $status || 'fetching' === $status;
	$rows       = $is_preview ? ( $job['plan']['events'] ?? array() ) : ( $job['results']['events'] ?? array() );
	$section    = $is_preview ? ( $job['plan'] ?? array() ) : ( $job['results'] ?? array() );
	?>
	<h2>
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: Meetup group urlname, 2: Group site name. */
				__( '%1$s into %2$s', 'wordcamporg' ),
				$job['urlname'],
				$site_name
			)
		);
		?>
	</h2>

	<p><strong id="wporg-meetup-import-status"><?php echo esc_html( describe_job_status( $job ) ); ?></strong></p>

	<?php if ( is_job_running( $job ) ) : ?>
		<?php render_step_loop( $job ); ?>
	<?php endif; ?>

	<?php if ( 'ready' === $status ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px;">
			<input type="hidden" name="action" value="<?php echo esc_attr( CONFIRM_ACTION ); ?>" />
			<input type="hidden" name="site_id" value="<?php echo esc_attr( $job['site_id'] ); ?>" />
			<input type="hidden" name="job_id" value="<?php echo esc_attr( $job['id'] ); ?>" />
			<?php wp_nonce_field( CONFIRM_ACTION ); ?>
			<?php submit_button( __( 'Import', 'wordcamporg' ), 'primary', 'submit', false ); ?>
		</form>
	<?php endif; ?>

	<?php if ( is_job_active( $job ) ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
			<input type="hidden" name="action" value="<?php echo esc_attr( CANCEL_ACTION ); ?>" />
			<input type="hidden" name="site_id" value="<?php echo esc_attr( $job['site_id'] ); ?>" />
			<?php wp_nonce_field( CANCEL_ACTION ); ?>
			<?php submit_button( __( 'Cancel', 'wordcamporg' ), 'secondary', 'submit', false ); ?>
		</form>
	<?php endif; ?>

	<?php if ( 'fetching' === $status || 'expired' === $status ) : ?>
		<?php return; ?>
	<?php endif; ?>

	<?php if ( ! empty( $section['group_results'] ) ) : ?>
		<h3><?php esc_html_e( 'Group', 'wordcamporg' ); ?></h3>
		<ul class="ul-disc">
			<?php foreach ( describe_group_results( $section['group_results'], $is_preview ) as $line ) : ?>
				<li><?php echo esc_html( $line ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( ! empty( $job['match_people'] ) && ( $is_preview || ! empty( $section['member_counts'] ) || ! empty( $section['organizer_counts'] ) ) ) : ?>
		<h3><?php esc_html_e( 'People', 'wordcamporg' ); ?></h3>
		<ul class="ul-disc">
			<li><?php echo esc_html( describe_organizer_counts( (array) ( $section['organizer_counts'] ?? array() ), $is_preview ) ); ?></li>
			<?php if ( ! empty( $job['match_members'] ) ) : ?>
				<li><?php echo esc_html( describe_member_counts( array_merge( array_fill_keys( array( 'join', 'already_member', 'previously_imported', 'unmatched', 'no_email' ), 0 ), (array) ( $section['member_counts'] ?? array() ) ), $is_preview ) ); ?></li>
			<?php endif; ?>
			<li>
				<?php
				echo esc_html(
					$is_preview
						? __( 'RSVPs are fetched per event, so they are matched during the import rather than previewed.', 'wordcamporg' )
						: describe_rsvp_counts( sum_rsvp_counts( $rows ) )
				);
				?>
			</li>
		</ul>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Events', 'wordcamporg' ); ?></h3>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Date', 'wordcamporg' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Event', 'wordcamporg' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Venue', 'wordcamporg' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Action', 'wordcamporg' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="4"><?php echo esc_html( $is_preview ? __( 'This group has no events to import on Meetup.', 'wordcamporg' ) : __( 'No events imported yet.', 'wordcamporg' ) ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><?php echo esc_html( $row['event']['start'] ); ?></td>
					<td><a href="<?php echo esc_url( $row['event']['meetup_url'] ); ?>"><?php echo esc_html( $row['event']['title'] ); ?></a></td>
					<td>
						<?php
						$venue_parts = array_filter( array( $row['event']['venue']['name'] ?? '', $row['event']['is_online'] ? __( 'Online', 'wordcamporg' ) : '' ) );
						echo esc_html( implode( ' + ', $venue_parts ) );
						?>
					</td>
					<td><?php echo esc_html( describe_status( $row['status'], $row['event']['is_past'], $row['message'] ?? '' ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/**
 * The progress bar for a running job, and the script that runs its batches.
 *
 * The script sends one request at a time to `handle_step()`, and the next as
 * soon as it answers; when the lock is busy it waits `retry_after` seconds,
 * and when the request fails it backs off and tries again, which redoes the
 * batch. A refused request (signed out, not allowed) stops it with a prompt
 * to reload. Once the job stops running (ready, done, failed, or gone) it reloads
 * the page, which shows the preview or the result. A reload, or opening the
 * page again later, starts it over from the job's saved cursor.
 *
 * @param array $job Import job, `fetching` or `importing`.
 */
function render_step_loop( array $job ): void {
	list( $done, $total ) = batch_progress( $job );
	?>
	<div
		id="wporg-meetup-import-loop"
		data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
		data-action="<?php echo esc_attr( STEP_ACTION ); ?>"
		data-nonce="<?php echo esc_attr( wp_create_nonce( STEP_ACTION ) ); ?>"
		data-site-id="<?php echo esc_attr( $job['site_id'] ); ?>"
		data-step="<?php echo esc_attr( $job['cursor']['step'] ?? '' ); ?>"
		data-retry-message="<?php esc_attr_e( 'Could not reach the server. Trying again shortly.', 'wordcamporg' ); ?>"
		data-stopped-message="<?php esc_attr_e( 'This page can no longer run the import, perhaps because you were signed out. Reload it to carry on.', 'wordcamporg' ); ?>"
	>
		<?php if ( $total ) : ?>
			<progress style="width:100%;max-width:400px;" aria-labelledby="wporg-meetup-import-status" max="<?php echo esc_attr( $total ); ?>" value="<?php echo esc_attr( $done ); ?>"></progress>
		<?php else : ?>
			<progress style="width:100%;max-width:400px;" aria-labelledby="wporg-meetup-import-status"></progress>
		<?php endif; ?>
		<p class="description"><?php esc_html_e( 'Keep this page open: the import only runs while it is. If you close it, the import pauses; open this page again within a day to carry on.', 'wordcamporg' ); ?></p>
		<p class="screen-reader-text" role="status" id="wporg-meetup-import-announce"></p>
	</div>
	<?php
	wp_print_inline_script_tag(
		<<<'JS'
( () => {
	const loop = document.getElementById( 'wporg-meetup-import-loop' );
	const status = document.getElementById( 'wporg-meetup-import-status' );
	const announce = document.getElementById( 'wporg-meetup-import-announce' );
	const bar = loop.querySelector( 'progress' );
	let step = loop.dataset.step;
	let failures = 0;
	let running = true;

	// Leaving mid-batch is safe, but the import pauses until the page is open again.
	window.addEventListener( 'beforeunload', ( event ) => {
		if ( running ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );

	const stop = ( message ) => {
		running = false;
		status.textContent = message;
		announce.textContent = message;
	};

	// The server renders the preview or the result, without the notice the page opened with.
	const reload = () => {
		running = false;
		const url = new URL( window.location.href );
		url.searchParams.delete( 'notice' );
		window.location.replace( url );
	};

	const retry = () => {
		failures++;
		status.textContent = loop.dataset.retryMessage;
		setTimeout( next, Math.min( 60, 5 * 2 ** ( failures - 1 ) ) * 1000 );
	};

	async function next() {
		let response;
		let body;

		try {
			response = await fetch( loop.dataset.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: new URLSearchParams( {
					action: loop.dataset.action,
					_ajax_nonce: loop.dataset.nonce,
					site_id: loop.dataset.siteId,
				} ),
			} );
			body = await response.json();
		} catch ( error ) {
			// A dropped connection, or an error page instead of JSON: retried below.
		}

		// Refused rather than failed, so asking again won't help: signed out
		// (admin-ajax answers 400), or not allowed or an expired nonce (403).
		if ( response && response.status >= 400 && response.status < 500 ) {
			stop( loop.dataset.stoppedMessage );
			return;
		}

		if ( ! body || ! body.success ) {
			retry();
			return;
		}

		const data = body.data;
		failures = 0;

		if ( ! data.running ) {
			reload();
			return;
		}

		status.textContent = data.message;

		if ( data.total ) {
			bar.max = data.total;
			bar.value = data.done;
		}

		if ( data.step !== step ) {
			step = data.step;
			announce.textContent = data.message;
		}

		setTimeout( next, data.retry_after * 1000 );
	}

	next();
} )();
JS
	);
}

/**
 * One line on where a job is.
 *
 * @param array $job Import job.
 */
function describe_job_status( array $job ): string {
	switch ( $job['status'] ?? '' ) {
		case 'fetching':
			return __( 'Fetching the group from Meetup. The preview shows here when it\'s ready.', 'wordcamporg' );

		case 'ready':
			return __( 'Preview ready. Nothing has been written yet. Check it, then click Import within a day.', 'wordcamporg' );

		case 'expired':
			return isset( $job['cursor'] )
				? __( 'Import stopped partway: its page wasn\'t open for a day. What it wrote stays; preview again to finish the rest.', 'wordcamporg' )
				: __( 'Preview expired: nobody clicked Import within a day. Nothing was written; preview again to import.', 'wordcamporg' );

		case 'importing':
			$step  = $job['cursor']['step'];
			$total = 'members' === $step ? count( $job['data']['members'] ?? array() ) : count( $job['data']['events'] ?? array() );
			$done  = min( $job['cursor']['offset'], $total );
			$label = array(
				'group'      => __( 'group details', 'wordcamporg' ),
				'organizers' => __( 'organizers', 'wordcamporg' ),
				'members'    => __( 'members', 'wordcamporg' ),
				'events'     => __( 'events', 'wordcamporg' ),
			);

			return in_array( $step, array( 'members', 'events' ), true )
				? sprintf(
					/* translators: 1: What's being imported, 2: How many are done, 3: How many in total. */
					__( 'Importing %1$s: %2$d of %3$d done.', 'wordcamporg' ),
					$label[ $step ],
					$done,
					$total
				)
				: sprintf(
					/* translators: %s: What's being imported. */
					__( 'Importing %s.', 'wordcamporg' ),
					$label[ $step ]
				);

		case 'done':
			$counts = array_count_values( wp_list_pluck( $job['results']['events'] ?? array(), 'status' ) );

			return sprintf(
				/* translators: 1: Created count, 2: Updated count, 3: Skipped count, 4: Failed count. */
				__( 'Import finished: %1$d events created, %2$d updated, %3$d skipped, %4$d failed.', 'wordcamporg' ),
				$counts['created'] ?? 0,
				( $counts['updated'] ?? 0 ) + ( $counts['photo_added'] ?? 0 ),
				( $counts['skipped'] ?? 0 ) + ( $counts['kept'] ?? 0 ) + ( $counts['in_series'] ?? 0 ),
				$counts['failed'] ?? 0
			);

		case 'failed':
			return sprintf(
				/* translators: %s: Error message. */
				__( 'Import failed: %s', 'wordcamporg' ),
				$job['error'] ?? ''
			);
	}

	return '';
}

/**
 * Human label for a planned or completed action.
 *
 * @param string $status  One of the statuses `plan_events()`/`import_events()` return.
 * @param bool   $is_past Whether the event has already happened on Meetup.
 * @param string $message Failure detail, if any.
 */
function describe_status( string $status, bool $is_past = false, string $message = '' ): string {
	$upcoming_create  = publishes_upcoming() ? __( 'Will be published', 'wordcamporg' ) : __( 'Will be created as a draft', 'wordcamporg' );
	$upcoming_created = publishes_upcoming() ? __( 'Published', 'wordcamporg' ) : __( 'Created as a draft', 'wordcamporg' );

	$labels = array(
		'create'      => $is_past ? __( 'Will be published as a past event', 'wordcamporg' ) : $upcoming_create,
		'update'      => __( 'Draft will be refreshed', 'wordcamporg' ),
		'in_series'   => __( 'Skipped: one of the dates of a recurring event', 'wordcamporg' ),
		'skip'        => __( 'Skipped: already published or trashed', 'wordcamporg' ),
		'photo'       => __( 'Already published: Meetup\'s photo will be added', 'wordcamporg' ),
		'photo_added' => __( 'Already published: Meetup\'s photo added', 'wordcamporg' ),
		'keep'        => __( 'Skipped: edited on the site since it was imported', 'wordcamporg' ),
		'kept'        => __( 'Skipped: edited on the site since it was imported', 'wordcamporg' ),
		'created'     => $is_past ? __( 'Published as a past event', 'wordcamporg' ) : $upcoming_created,
		'updated'     => __( 'Draft refreshed', 'wordcamporg' ),
		'skipped'     => __( 'Skipped: already published or trashed', 'wordcamporg' ),
		'failed'      => __( 'Failed', 'wordcamporg' ),
	);

	$label = $labels[ $status ] ?? $status;

	return $message ? "$label: $message" : $label;
}

/**
 * One-line summary of what happened, or would happen, to the group's members.
 *
 * @param array $counts    From `plan_members()` or `import_members()`.
 * @param bool  $is_preview Whether this is a preview.
 */
function describe_member_counts( array $counts, bool $is_preview ): string {
	return sprintf(
		/* translators: 1: Total members, 2: Members joining, 3: Already members, 4: Considered by an earlier import, 5: No matching account, 6: No email shared. */
		$is_preview
			? __( '%1$d Meetup members: %2$d will join the group, %3$d are already members, %4$d were handled by an earlier import, %5$d have no matching WordPress.org account, %6$d have not shared an email.', 'wordcamporg' )
			: __( '%1$d Meetup members: %2$d joined the group, %3$d were already members, %4$d were handled by an earlier import, %5$d have no matching WordPress.org account, %6$d have not shared an email.', 'wordcamporg' ),
		array_sum( $counts ),
		$counts['join'],
		$counts['already_member'],
		$counts['previously_imported'],
		$counts['unmatched'],
		$counts['no_email']
	);
}

/**
 * One line per group detail, saying what happened (or would happen) to it.
 *
 * @param array $results    From `plan_group()` or `import_group()`: detail => outcome.
 * @param bool  $is_preview Whether this is a preview.
 * @return string[]
 */
function describe_group_results( array $results, bool $is_preview ): array {
	$labels = array(
		'location' => array(
			'set'     => $is_preview ? __( 'Location: will be set from Meetup.', 'wordcamporg' ) : __( 'Location: set from Meetup.', 'wordcamporg' ),
			'kept'    => __( 'Location: already set on the site, left as is.', 'wordcamporg' ),
			'missing' => __( 'Location: Meetup has no usable city and country.', 'wordcamporg' ),
		),
		'about'    => array(
			'set'     => $is_preview ? __( 'About page: the example text will be replaced with Meetup\'s group description.', 'wordcamporg' ) : __( 'About page: example text replaced with Meetup\'s group description.', 'wordcamporg' ),
			'kept'    => __( 'About page: already edited, or missing, left as is.', 'wordcamporg' ),
			'missing' => __( 'About page: Meetup has no group description.', 'wordcamporg' ),
		),
		'icon'     => array(
			'set'     => $is_preview ? __( 'Site icon: will be set from Meetup\'s group photo.', 'wordcamporg' ) : __( 'Site icon: set from Meetup\'s group photo.', 'wordcamporg' ),
			'kept'    => __( 'Site icon: already set, left as is.', 'wordcamporg' ),
			'missing' => __( 'Site icon: Meetup has no group photo.', 'wordcamporg' ),
			'failed'  => __( 'Site icon: the group photo could not be downloaded.', 'wordcamporg' ),
		),
	);

	$lines = array();

	foreach ( $labels as $detail => $outcomes ) {
		if ( isset( $results[ $detail ], $outcomes[ $results[ $detail ] ] ) ) {
			$lines[] = $outcomes[ $results[ $detail ] ];
		}
	}

	if ( 'failed' === ( $results['icon'] ?? '' ) && ! empty( $results['icon_error'] ) ) {
		$lines[ count( $lines ) - 1 ] = sprintf(
			/* translators: %s: Error message. */
			__( 'Site icon: the group photo could not be imported (%s).', 'wordcamporg' ),
			$results['icon_error']
		);
	}

	return $lines;
}

/**
 * One-line summary of what happened, or would happen, to the group's Meetup organizers.
 *
 * @param array $counts     From `plan_organizers()` or `import_organizers()`.
 * @param bool  $is_preview Whether this is a preview.
 */
function describe_organizer_counts( array $counts, bool $is_preview ): string {
	$counts = array_merge( array_fill_keys( array( 'added', 'promoted', 'kept', 'left', 'unmatched', 'no_email' ), 0 ), $counts );

	return sprintf(
		/* translators: 1: Total organizers, 2: Added to the group, 3: Given a higher role, 4: Already in that role or higher, 5: Left the group since an earlier import, 6: No matching account, 7: No email shared. */
		$is_preview
			? __( '%1$d Meetup organizers: %2$d will join with their organizer role, %3$d will be promoted, %4$d already have that role or higher, %5$d left the group since an earlier import, %6$d have no matching WordPress.org account, %7$d have not shared an email.', 'wordcamporg' )
			: __( '%1$d Meetup organizers: %2$d joined with their organizer role, %3$d were promoted, %4$d already had that role or higher, %5$d left the group since an earlier import, %6$d have no matching WordPress.org account, %7$d have not shared an email.', 'wordcamporg' ),
		array_sum( $counts ),
		$counts['added'],
		$counts['promoted'],
		$counts['kept'],
		$counts['left'],
		$counts['unmatched'],
		$counts['no_email']
	);
}

/**
 * One-line summary of the RSVPs an import carried over.
 *
 * @param array $counts From `sum_rsvp_counts()`.
 */
function describe_rsvp_counts( array $counts ): string {
	return sprintf(
		/* translators: 1: RSVPs saved, 2: Of which checked in, 3: No matching account, 4: Status with no equivalent, e.g. "maybe", or waitlisted for a past event, 5: Could not be fetched or saved. */
		__( 'RSVPs: %1$d kept (%2$d checked in as attended), %3$d had no matching WordPress.org account, %4$d were left out (a status with no equivalent here, or waitlisted for a past event), %5$d failed.', 'wordcamporg' ),
		$counts['saved'],
		$counts['checked_in'],
		$counts['unmatched'],
		$counts['skipped'],
		$counts['failed']
	);
}

/**
 * Whether the network has a Meetup connection to import with.
 *
 * The token lives in a network option managed by Network Settings > Meetup
 * (`wcorg-meetup-oauth.php`); it's read directly rather than through the
 * client so that rendering the form doesn't make an API call.
 */
function is_meetup_connected(): bool {
	return \WordCamp\Meetup_OAuth\has_credentials()
		&& ! empty( get_site_option( \WordCamp\Meetup_OAuth\TOKEN_SITE_OPTION ) );
}

/**
 * Turn what an admin pasted into a Meetup group urlname.
 *
 * Accepts a bare urlname or any meetup.com URL under the group, e.g. an event
 * page, including localized URLs (such as /pl-PL/group-name/ or /de-DE/group-name/).
 *
 * @param string $input Group URL or urlname.
 */
function parse_group_urlname( string $input ): string {
	$input = trim( $input );

	$page_names = array( 'events', 'members', 'photos', 'discussions', 'about' );

	if ( preg_match( '#^(?:https?://)?(?:www\.)?meetup\.com(?:/.*)?$#i', $input ) ) {
		$path     = preg_replace( '#^(?:https?://)?(?:www\.)?meetup\.com/?#i', '', $input );
		$path     = preg_replace( '#[?\#].*$#', '', $path );
		$segments = array_values( array_filter( explode( '/', trim( $path, '/' ) ) ) );

		if ( ! empty( $segments ) ) {
			if ( preg_match( '/^[a-z]{2,3}-[a-z]{2,4}$/i', $segments[0] ) ) {
				array_shift( $segments );
			} elseif (
				preg_match( '/^[a-z]{2}$/i', $segments[0] ) &&
				isset( $segments[1] ) &&
				! in_array( strtolower( $segments[1] ), $page_names, true )
			) {
				array_shift( $segments );
			}
		}

		if ( empty( $segments ) || in_array( strtolower( $segments[0] ), $page_names, true ) ) {
			return '';
		}

		$input = $segments[0];
	}

	$input = trim( $input, '/' );

	if ( in_array( strtolower( $input ), $page_names, true ) ) {
		return '';
	}

	return preg_match( '/^[A-Za-z0-9_-]+$/', $input ) ? $input : '';
}

/**
 * A Meetup client, if the network is connected.
 *
 * @return Meetup_Client|WP_Error
 */
function get_meetup_client() {
	if ( ! is_meetup_connected() ) {
		return new WP_Error( 'meetup_not_connected', __( 'Meetup is not connected on this network.', 'wordcamporg' ) );
	}

	$client = new Meetup_Client();

	if ( $client->error->has_errors() ) {
		return $client->error;
	}

	return $client;
}

/**
 * Fetch a group's events from the Meetup API.
 *
 * @param string $urlname      Meetup group urlname.
 * @param bool   $include_past Whether to include events that have already happened.
 * @return array|WP_Error Raw event nodes, as `Meetup_Client::get_group_events()` returns them.
 */
function fetch_group_events( string $urlname, bool $include_past = false ) {
	/**
	 * Short-circuit the Meetup events request, e.g. with fixture data.
	 *
	 * The Meetup client can't be constructed without the network's Meetup
	 * credentials, which tests and local environments don't have.
	 *
	 * @param array|WP_Error|null $events       Raw event nodes, or null to fetch from Meetup.
	 * @param string              $urlname      Meetup group urlname.
	 * @param bool                $include_past Whether past events were asked for.
	 */
	$events = apply_filters( 'wcorg_meetup_import_pre_fetch', null, $urlname, $include_past );

	if ( null === $events ) {
		$client = get_meetup_client();

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$events = $client->get_group_events(
			$urlname,
			array(
				'status' => $include_past ? 'upcoming,past' : 'upcoming',
				// `status` itself comes back rewritten by the client's back-compat
				// layer, which reports past events as "upcoming" too
				// (`Meetup_Client::apply_backcompat_fields()`), so ask for the
				// raw value under another name as well.
				'fields' => array(
					'meetupStatus: status',
					'howToFindUs',
					'venues { address postalCode }',
					'featuredEventPhoto { id highResUrl }',
					'eventHosts { member { id email } }',
					'series { id endDate monthlyRecurrence { monthlyWeekOfMonth monthlyDayOfWeek } weeklyRecurrence { weeklyInterval weeklyDaysOfWeek } }',
				),
			)
		);
	}

	if ( is_wp_error( $events ) ) {
		return $events;
	}

	if ( ! is_array( $events ) || ! array_is_list( $events ) ) {
		return new WP_Error( 'meetup_group_not_found', __( 'Meetup did not return that group. Check the group URL.', 'wordcamporg' ) );
	}

	if ( ! $include_past ) {
		$events = array_values(
			array_filter(
				$events,
				static function ( $event ) {
					return ! is_past_status( $event );
				}
			)
		);
	}

	Logger\log(
		'fetched_events',
		array(
			'urlname' => $urlname,
			'count'   => count( $events ),
		)
	);

	return $events;
}

/**
 * Fetch a group's members from the Meetup API.
 *
 * Meetup only shares a member's email with the Pro network when the member
 * allows it, so expect some members without one.
 *
 * @param string $urlname Meetup group urlname.
 * @return array|WP_Error Raw member nodes, as `Meetup_Client::get_group_members()` returns them.
 */
function fetch_group_members( string $urlname ) {
	/**
	 * Short-circuit the Meetup members request, e.g. with fixture data.
	 *
	 * @param array|WP_Error|null $members Raw member nodes (`id`, `email`), or null to fetch from Meetup.
	 * @param string              $urlname Meetup group urlname.
	 */
	$members = apply_filters( 'wcorg_meetup_import_pre_fetch_members', null, $urlname );

	if ( null === $members ) {
		$client = get_meetup_client();

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$members = $client->get_group_members( $urlname );
	}

	if ( is_wp_error( $members ) ) {
		return $members;
	}

	if ( ! is_array( $members ) || ! array_is_list( $members ) ) {
		return new WP_Error( 'meetup_group_not_found', __( 'Meetup did not return that group. Check the group URL.', 'wordcamporg' ) );
	}

	Logger\log(
		'fetched_members',
		array(
			'urlname' => $urlname,
			'count'   => count( $members ),
		)
	);

	return $members;
}

/**
 * Keep only what member matching needs: the Meetup ID and the email.
 *
 * @param array $raw Member node from the Meetup API.
 * @return array
 */
function normalize_member( array $raw ): array {
	return array(
		'meetup_id' => (string) ( $raw['id'] ?? '' ),
		'email'     => strtolower( (string) sanitize_email( (string) ( $raw['email'] ?? '' ) ) ),
	);
}

/**
 * Fetch a group's own details from the Meetup API.
 *
 * @param string $urlname Meetup group urlname.
 * @return array|WP_Error The `groupByUrlname` node.
 */
function fetch_group( string $urlname ) {
	/**
	 * Short-circuit the Meetup group request, e.g. with fixture data.
	 *
	 * @param array|WP_Error|null $group   The `groupByUrlname` node, or null to fetch from Meetup.
	 * @param string              $urlname Meetup group urlname.
	 */
	$group = apply_filters( 'wcorg_meetup_import_pre_fetch_group', null, $urlname );

	if ( null === $group ) {
		$client = get_meetup_client();

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$result = $client->send_paginated_request(
			'query ( $urlname: String! ) {
				groupByUrlname( urlname: $urlname ) {
					id name urlname description city country foundedDate
					keyGroupPhoto { id highResUrl }
				}
			}',
			array( 'urlname' => $urlname )
		);

		$group = is_wp_error( $result ) ? $result : ( $result['groupByUrlname'] ?? null );
	}

	if ( is_wp_error( $group ) ) {
		return $group;
	}

	if ( ! is_array( $group ) || empty( $group['id'] ) ) {
		return new WP_Error( 'meetup_group_not_found', __( 'Meetup did not return that group. Check the group URL.', 'wordcamporg' ) );
	}

	return $group;
}

/**
 * Fetch a group's organizers from the Meetup API, with their roles.
 *
 * Includes the account the connection belongs to, which `run_fetch()` leaves
 * out (see `without_connection_account()`).
 *
 * @param string $urlname Meetup group urlname.
 * @return array|WP_Error Raw edges: `{ metadata: { role }, node: { id, email } }`.
 */
function fetch_group_organizers( string $urlname ) {
	/**
	 * Short-circuit the Meetup organizers request, e.g. with fixture data.
	 *
	 * @param array|WP_Error|null $organizers Raw edges, or null to fetch from Meetup.
	 * @param string              $urlname    Meetup group urlname.
	 */
	$organizers = apply_filters( 'wcorg_meetup_import_pre_fetch_organizers', null, $urlname );

	if ( null === $organizers ) {
		$client = get_meetup_client();

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$result = $client->send_paginated_request(
			'query ( $urlname: String!, $cursor: String ) {
				groupByUrlname( urlname: $urlname ) {
					memberships ( first: 100, after: $cursor, filter: { status: LEADER } ) {
						' . $client->pagination . '
						edges { metadata { role } node { id email } }
					}
				}
			}',
			array(
				'urlname' => $urlname,
				'cursor'  => null,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$organizers = $result['groupByUrlname']['memberships']['edges'] ?? array();
	}

	return is_array( $organizers ) || is_wp_error( $organizers ) ? $organizers : array();
}

/**
 * Fetch the Meetup member ID of the account the connection belongs to.
 *
 * @return string|WP_Error Member ID, or '' if Meetup didn't say.
 */
function fetch_connection_member_id() {
	/**
	 * Short-circuit the Meetup request for the connection's own account, e.g. with fixture data.
	 *
	 * @param string|WP_Error|null $member_id Meetup member ID, or null to ask Meetup.
	 */
	$member_id = apply_filters( 'wcorg_meetup_import_pre_fetch_connection_member', null );

	if ( null === $member_id ) {
		$client = get_meetup_client();

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$result = $client->send_paginated_request( 'query { self { id } }' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$member_id = $result['self']['id'] ?? '';
	}

	return is_wp_error( $member_id ) ? $member_id : (string) $member_id;
}

/**
 * Leave the account the Meetup connection belongs to out of everyone an
 * import matches by email: members, organizers, and event hosts.
 *
 * On a Pro network that account is an organizer, and so a member, of every
 * group, and it can host events. Matching it by email would put whoever owns
 * that address in every imported group, as an Organizer at that.
 *
 * @param array  $raw       Fetched `members`, `organizers` and `events`, as Meetup returns them.
 * @param string $member_id The account's Meetup member ID, or '' if unknown.
 * @return array The same, without that account.
 */
function without_connection_account( array $raw, string $member_id ): array {
	if ( '' === $member_id ) {
		return $raw;
	}

	$is_other = static function ( $id ) use ( $member_id ): bool {
		return (string) $id !== $member_id;
	};

	$raw['members'] = array_values(
		array_filter(
			$raw['members'],
			static function ( $member ) use ( $is_other ) {
				return $is_other( $member['id'] ?? '' );
			}
		)
	);

	$raw['organizers'] = array_values(
		array_filter(
			$raw['organizers'],
			static function ( $edge ) use ( $is_other ) {
				return $is_other( $edge['node']['id'] ?? '' );
			}
		)
	);

	$raw['events'] = array_map(
		static function ( $event ) use ( $is_other ) {
			if ( isset( $event['eventHosts'] ) ) {
				$event['eventHosts'] = array_values(
					array_filter(
						(array) $event['eventHosts'],
						static function ( $host ) use ( $is_other ) {
							return $is_other( $host['member']['id'] ?? '' );
						}
					)
				);
			}

			return $event;
		},
		$raw['events']
	);

	return $raw;
}

/**
 * Fetch one event's RSVPs from the Meetup API.
 *
 * @param string $meetup_event_id Meetup event ID.
 * @return array|WP_Error Raw RSVP nodes: `{ status, guestsCount, member: { id, email } }`.
 */
function fetch_event_rsvps( string $meetup_event_id ) {
	/**
	 * Short-circuit a Meetup RSVPs request, e.g. with fixture data.
	 *
	 * @param array|WP_Error|null $rsvps           Raw RSVP nodes, or null to fetch from Meetup.
	 * @param string              $meetup_event_id Meetup event ID.
	 */
	$rsvps = apply_filters( 'wcorg_meetup_import_pre_fetch_rsvps', null, $meetup_event_id );

	if ( null === $rsvps ) {
		$client = get_meetup_client();

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$result = $client->send_paginated_request(
			'query ( $eventId: ID!, $cursor: String ) {
				event( id: $eventId ) {
					rsvps ( first: 100, after: $cursor ) {
						' . $client->pagination . '
						edges { node { status guestsCount member { id email } } }
					}
				}
			}',
			array(
				'eventId' => $meetup_event_id,
				'cursor'  => null,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$rsvps = array_column( $result['event']['rsvps']['edges'] ?? array(), 'node' );
	}

	return is_array( $rsvps ) || is_wp_error( $rsvps ) ? $rsvps : array();
}

/**
 * Map a Meetup group node to the details the importer writes.
 *
 * @param array $raw The `groupByUrlname` node.
 * @return array
 */
function normalize_group( array $raw ): array {
	$founded = '';

	if ( ! empty( $raw['foundedDate'] ) ) {
		try {
			$founded = ( new DateTimeImmutable( (string) $raw['foundedDate'] ) )->format( 'Y-m-d' );
		} catch ( Exception $e ) {
			$founded = '';
		}
	}

	return array(
		'meetup_id'    => (string) ( $raw['id'] ?? '' ),
		'urlname'      => (string) ( $raw['urlname'] ?? '' ),
		'description'  => '' !== trim( (string) ( $raw['description'] ?? '' ) ) ? wp_kses_post( wpautop( (string) $raw['description'] ) ) : '',
		'city'         => trim( (string) ( $raw['city'] ?? '' ) ),
		'country_code' => strtoupper( trim( (string) ( $raw['country'] ?? '' ) ) ),
		'founded'      => $founded,
		'photo'        => normalize_photo( $raw['keyGroupPhoto'] ?? null ),
	);
}

/**
 * Keep what organizer matching needs: the Meetup ID, the email, and the site role.
 *
 * @param array $raw Edge from `fetch_group_organizers()`.
 * @return array `role` is null for a Meetup role with no organizer equivalent.
 */
function normalize_organizer( array $raw ): array {
	return array(
		'meetup_id' => (string) ( $raw['node']['id'] ?? '' ),
		'email'     => strtolower( (string) sanitize_email( (string) ( $raw['node']['email'] ?? '' ) ) ),
		'role'      => MEETUP_ORGANIZER_ROLES[ strtoupper( (string) ( $raw['metadata']['role'] ?? '' ) ) ] ?? null,
	);
}

/**
 * Map a Meetup RSVP to the GatherPress RSVP it becomes.
 *
 * @param array $raw RSVP node from `fetch_event_rsvps()`.
 * @return array `status` is null for a Meetup status with no equivalent.
 */
function normalize_rsvp( array $raw ): array {
	$meetup_status = strtoupper( (string) ( $raw['status'] ?? '' ) );
	$mapped        = RSVP_STATUSES[ $meetup_status ] ?? array( null, false );

	return array(
		'email'      => strtolower( (string) sanitize_email( (string) ( $raw['member']['email'] ?? '' ) ) ),
		'status'     => $mapped[0],
		'checked_in' => $mapped[1],
		'waitlisted' => 'WAITLIST' === $meetup_status,
		'guests'     => max( 0, (int) ( $raw['guestsCount'] ?? 0 ) ),
	);
}

/**
 * Map a Meetup event node to the fields the importer writes.
 *
 * Times are converted to the group's own timezone, which is what GatherPress
 * stores and displays.
 *
 * @param array $raw Event node from the Meetup API.
 * @return array
 */
function normalize_event( array $raw ): array {
	$timezone = (string) ( $raw['group']['timezone'] ?? '' );

	try {
		$zone = new DateTimeZone( '' !== $timezone ? $timezone : wp_timezone_string() );
	} catch ( Exception $e ) {
		$zone = wp_timezone();
	}

	$start = new DateTimeImmutable( (string) $raw['dateTime'] );
	$end   = ! empty( $raw['endTime'] )
		? new DateTimeImmutable( (string) $raw['endTime'] )
		: $start->modify( '+' . (int) ( $raw['duration'] ?? 7200 ) . ' seconds' );

	$event_type = strtoupper( (string) ( $raw['eventType'] ?? '' ) );
	$is_online  = in_array( $event_type, array( 'ONLINE', 'HYBRID' ), true );

	$venue = null;
	foreach ( $raw['venues'] ?? array() as $candidate ) {
		// Meetup attaches a placeholder "Online event" venue to online events.
		if ( empty( $candidate['id'] ) || ONLINE_VENUE_ID === (int) $candidate['id'] || empty( $candidate['name'] ) ) {
			continue;
		}

		$locality = trim( implode( ' ', array_filter( array( $candidate['state'] ?? '', $candidate['postalCode'] ?? '' ) ) ) );
		$venue    = array(
			'meetup_id' => (string) $candidate['id'],
			'name'      => (string) $candidate['name'],
			'address'   => implode(
				', ',
				array_filter(
					array(
						$candidate['address'] ?? '',
						$candidate['city'] ?? '',
						$locality,
						strtoupper( (string) ( $candidate['country'] ?? '' ) ),
					)
				)
			),
		);
		break;
	}

	$description = wpautop( (string) ( $raw['description'] ?? '' ) );

	if ( ! empty( $raw['howToFindUs'] ) ) {
		$description .= wpautop(
			sprintf(
				/* translators: %s: Directions to the venue, as written on Meetup. */
				__( 'How to find us: %s', 'wordcamporg' ),
				$raw['howToFindUs']
			)
		);
	}

	return array(
		'meetup_id'   => (string) $raw['id'],
		'meetup_url'  => (string) ( $raw['eventUrl'] ?? '' ),
		'title'       => (string) ( $raw['title'] ?? '' ),
		'description' => to_paragraph_blocks( wp_kses_post( $description ) ),
		'timezone'    => $zone->getName(),
		'start'       => $start->setTimezone( $zone )->format( 'Y-m-d H:i:s' ),
		'end'         => $end->setTimezone( $zone )->format( 'Y-m-d H:i:s' ),
		'is_online'   => $is_online,
		'venue'       => $venue,
		'is_past'     => is_past_status( $raw ),
		'rsvp_yes'    => (int) ( $raw['rsvps']['yesCount'] ?? 0 ),
		'attended'    => (int) ( $raw['rsvps']['attendedCount'] ?? 0 ),
		'photo'       => normalize_photo( $raw['featuredEventPhoto'] ?? null ),
		'hosts'       => array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $host ) {
							return strtolower( (string) sanitize_email( (string) ( $host['member']['email'] ?? '' ) ) );
						},
						(array) ( $raw['eventHosts'] ?? array() )
					)
				)
			)
		),
		'series'      => normalize_series( $raw['series'] ?? null ),
	);
}

/**
 * The repeating event a Meetup event is one date of, if any.
 *
 * Meetup hands back its own rule: weekly, every N weeks on one or more days,
 * or monthly on the Nth (5 meaning last) weekday. It doesn't say how many
 * months apart a monthly series' dates are; `fold_series()` reads that off
 * the dates.
 *
 * @param mixed $series `series` from the Meetup API.
 * @return array|null `{ id, end_date, weekly: { interval, days }|null, monthly: { week, day }|null }`,
 *                    or null for an event that isn't part of a series.
 */
function normalize_series( $series ): ?array {
	if ( ! is_array( $series ) || empty( $series['id'] ) ) {
		return null;
	}

	$week     = array( 'MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU' );
	$day_code = static function ( $day ) use ( $week ): string {
		$code = substr( strtoupper( (string) $day ), 0, 2 );

		return in_array( $code, $week, true ) ? $code : '';
	};

	$weekly  = null;
	$monthly = null;

	if ( is_array( $series['weeklyRecurrence'] ?? null ) ) {
		// In week order: Meetup's varies from one response to the next.
		$days = array_values( array_intersect( $week, array_map( $day_code, (array) ( $series['weeklyRecurrence']['weeklyDaysOfWeek'] ?? array() ) ) ) );

		if ( $days ) {
			$weekly = array(
				'interval' => max( 1, (int) ( $series['weeklyRecurrence']['weeklyInterval'] ?? 1 ) ),
				'days'     => $days,
			);
		}
	} elseif ( is_array( $series['monthlyRecurrence'] ?? null ) ) {
		$week = (int) ( $series['monthlyRecurrence']['monthlyWeekOfMonth'] ?? 0 );
		$day  = $day_code( $series['monthlyRecurrence']['monthlyDayOfWeek'] ?? '' );

		if ( $week >= 1 && $week <= 5 && '' !== $day ) {
			$monthly = compact( 'week', 'day' );
		}
	}

	$end_date = (string) ( $series['endDate'] ?? '' );

	return array(
		'id'       => (string) $series['id'],
		'end_date' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_date ) ? $end_date : '',
		'weekly'   => $weekly,
		'monthly'  => $monthly,
	);
}

/**
 * Whether a Meetup event node is one that has already happened.
 *
 * Reads Meetup's own status from `meetupStatus` when it's there -- see
 * `fetch_group_events()` for why `status` can't be trusted on its own.
 *
 * @param array $raw Event node from the Meetup API.
 */
function is_past_status( array $raw ): bool {
	return 'PAST' === strtoupper( (string) ( $raw['meetupStatus'] ?? $raw['status'] ?? '' ) );
}

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument native properties.
/**
 * Recursively convert a DOM list element (ul or ol) to Gutenberg list block markup.
 *
 * Each list item can contain inner blocks, supporting nested unordered and ordered
 * lists to arbitrary depth without breaking block validation.
 *
 * @param DOMElement  $node The ul or ol element to convert.
 * @param DOMDocument $doc  The owning document for serializing child nodes.
 * @return string Gutenberg block markup for the list and its items.
 */
function convert_list_node_to_block( DOMElement $node, DOMDocument $doc ): string {
	$ordered = 'ol' === strtolower( $node->nodeName );
	$tag     = $ordered ? 'ol' : 'ul';
	$attrs   = $ordered ? ' {"ordered":true}' : '';
	$items   = array();

	foreach ( $node->childNodes as $child ) {
		if ( ! ( $child instanceof DOMElement ) || 'li' !== strtolower( $child->nodeName ) ) {
			continue;
		}

		$li_content = '';
		foreach ( $child->childNodes as $li_child ) {
			if ( $li_child instanceof DOMElement && in_array( strtolower( $li_child->nodeName ), array( 'ul', 'ol' ), true ) ) {
				$li_content .= convert_list_node_to_block( $li_child, $doc );
			} else {
				$li_content .= $doc->saveHTML( $li_child );
			}
		}

		$li_content = trim( $li_content );
		$items[]    = "<!-- wp:list-item -->\n<li>" . $li_content . "</li>\n<!-- /wp:list-item -->";
	}

	$inner = implode( "\n", $items );

	return "<!-- wp:list{$attrs} -->\n<{$tag} class=\"wp-block-list\">{$inner}</{$tag}>\n<!-- /wp:list -->";
}

/**
 * Convert HTML unordered and ordered lists into Gutenberg list blocks.
 *
 * Uses DOMDocument to properly handle nested lists that regex cannot parse reliably.
 *
 * @param string $html HTML content.
 * @return string HTML content with lists converted to Gutenberg blocks.
 */
function convert_lists_to_blocks( string $html ): string {
	if ( false === stripos( $html, '<ul' ) && false === stripos( $html, '<ol' ) ) {
		return $html;
	}

	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
	libxml_clear_errors();

	if ( ! $loaded ) {
		return $html;
	}

	$xpath     = new DOMXPath( $dom );
	$top_lists = $xpath->query( '//ul[not(ancestor::li)] | //ol[not(ancestor::li)]' );

	if ( ! $top_lists || 0 === $top_lists->length ) {
		return $html;
	}

	$replacements = array();
	$idx          = 0;

	foreach ( $top_lists as $list ) {
		$token                  = "___WP_LIST_BLOCK_{$idx}___";
		$replacements[ $token ] = convert_list_node_to_block( $list, $dom );
		$placeholder            = $dom->createTextNode( $token );
		$list->parentNode->replaceChild( $placeholder, $list );
		++$idx;
	}

	$container = $dom->getElementsByTagName( 'div' )->item( 0 );
	if ( ! $container ) {
		return $html;
	}

	$inner_html = '';
	foreach ( $container->childNodes as $child ) {
		$inner_html .= $dom->saveHTML( $child );
	}

	return strtr( $inner_html, $replacements );
}
// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

/**
 * Turn HTML content into serialized Gutenberg blocks.
 *
 * The event editor only loads content made of supported blocks
 * (`Frontend\Defaults\extract_description_blocks()` drops anything else), so
 * plain HTML outside block comments would open as an empty description, and
 * saving would erase it. Converts paragraphs, headings, lists, quotes, separators,
 * and code into their respective block markup, encapsulating any freeform text
 * into paragraph blocks without wrapping block-level elements.
 *
 * @param string $html HTML content, as `wpautop()` produces.
 */
function to_paragraph_blocks( string $html ): string {
	$html = trim( $html );

	if ( '' === $html ) {
		return '';
	}

	$html = preg_replace( '#<p(?:\s+[^>]*)?>(.*?)</p>#is', "<!-- wp:paragraph -->\n<p>$1</p>\n<!-- /wp:paragraph -->", $html );

	$html = preg_replace_callback(
		'#<h([1-6])(?:\s+[^>]*)?>(.*?)</h[1-6]>#is',
		static function ( $matches ) {
			$level   = (int) $matches[1];
			$attrs   = 2 === $level ? '' : ' {"level":' . $level . '}';
			$content = trim( $matches[2] );
			return "<!-- wp:heading{$attrs} -->\n<h{$level} class=\"wp-block-heading\">{$content}</h{$level}>\n<!-- /wp:heading -->";
		},
		$html
	);

	$html = convert_lists_to_blocks( $html );

	$html = preg_replace(
		'#<hr(?:\s+[^>]*)?/?>#is',
		"<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\" />\n<!-- /wp:separator -->",
		$html
	);

	$html = preg_replace( '#<blockquote(?:\s+[^>]*)?>(.*?)</blockquote>#is', "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">$1</blockquote>\n<!-- /wp:quote -->", $html );

	$html = preg_replace_callback(
		'#<pre(?:\s+[^>]*)?>(.*?)</pre>#is',
		static function ( $matches ) {
			$content = trim( $matches[1] );
			if ( ! preg_match( '#^<code(?:\s+[^>]*)?>(.*)</code>$#is', $content, $code_match ) ) {
				$content = '<code>' . $content . '</code>';
			} else {
				$content = '<code>' . trim( $code_match[1] ) . '</code>';
			}
			return "<!-- wp:code -->\n<pre class=\"wp-block-code\">{$content}</pre>\n<!-- /wp:code -->";
		},
		$html
	);

	$blocks     = parse_blocks( $html );
	$normalized = array();

	foreach ( $blocks as $block ) {
		if ( null !== $block['blockName'] ) {
			$normalized[] = $block;
		} elseif ( '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
			$content = trim( (string) $block['innerHTML'] );
			if ( preg_match( '#^<(?:table|div|section|article|aside|figure|blockquote|header|footer|nav)\b#i', $content ) ) {
				$normalized[] = $block;
			} elseif ( preg_match( '#^<p\b#i', $content ) ) {
				$normalized[] = array(
					'blockName'    => 'core/paragraph',
					'attrs'        => array(),
					'innerBlocks'  => array(),
					'innerHTML'    => $content,
					'innerContent' => array( $content ),
				);
			} else {
				$normalized[] = array(
					'blockName'    => 'core/paragraph',
					'attrs'        => array(),
					'innerBlocks'  => array(),
					'innerHTML'    => '<p>' . $content . '</p>',
					'innerContent' => array( '<p>' . $content . '</p>' ),
				);
			}
		}
	}

	return trim( serialize_blocks( $normalized ) );
}

/**
 * Keep an event's featured photo only if it's an image Meetup hosts.
 *
 * The URL is something this server will download, so it has to point at
 * Meetup's own image host over HTTPS, not anywhere a response could name.
 *
 * @param mixed $photo `featuredEventPhoto` from the Meetup API.
 * @return array|null `{ meetup_id, url }`, or null for no usable photo.
 */
function normalize_photo( $photo ): ?array {
	if ( ! is_array( $photo ) || empty( $photo['id'] ) || empty( $photo['highResUrl'] ) ) {
		return null;
	}

	$url  = (string) $photo['highResUrl'];
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );

	if (
		'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ||
		( 'meetupstatic.com' !== $host && ! str_ends_with( $host, '.meetupstatic.com' ) )
	) {
		return null;
	}

	return array(
		'meetup_id' => (string) $photo['id'],
		'url'       => esc_url_raw( $url ),
	);
}

/**
 * Check that a site is a live group site that can take an import.
 *
 * @param int $site_id Target site ID.
 * @return true|WP_Error
 */
function validate_target_site( int $site_id ) {
	$site = get_site( $site_id );

	if (
		! $site ||
		GROUPS_NETWORK_ID !== (int) $site->network_id ||
		GROUPS_ROOT_BLOG_ID === (int) $site->blog_id ||
		$site->deleted ||
		$site->archived ||
		$site->spam
	) {
		return new WP_Error( 'invalid_group_site', __( 'Please choose an active group site to import into.', 'wordcamporg' ) );
	}

	return true;
}

/**
 * Work out what an import would do, without writing anything.
 *
 * @param int   $site_id Target group site.
 * @param array $events  Normalized events.
 * @return array[] One `{ event, status }` per event, status `create`, `update`, or `skip`.
 */
function plan_events( int $site_id, array $events ): array {
	return with_site(
		$site_id,
		function () use ( $events ) {
			$plan = array();

			foreach ( $events as $event ) {
				$existing = find_existing_event( $event );

				if ( ! $existing && find_covering_series( $event ) ) {
					$plan[] = array(
						'event'  => $event,
						'status' => 'in_series',
					);
					continue;
				}

				// A past event an interrupted run left half-done is, as far as
				// anyone is concerned, still to be created.
				if ( ! $existing || get_post_meta( $existing->ID, PENDING_PUBLISH_META, true ) ) {
					$status = 'create';
				} elseif ( 'draft' === $existing->post_status && is_edited_since_import( $existing ) ) {
					$status = 'keep';
				} elseif ( 'draft' === $existing->post_status ) {
					$status = 'update';
				} elseif ( needs_photo( $existing, $event ) ) {
					$status = 'photo';
				} else {
					$status = 'skip';
				}

				$message = empty( $event['recurrence'] ) ? '' : describe_recurrence( $event );
				$plan[]  = compact( 'event', 'status', 'message' );
			}

			return $plan;
		}
	);
}

/**
 * Create or refresh draft events on a group site.
 *
 * Callers MUST verify the `manage_sites` capability before calling this -- it
 * performs no capability check of its own, matching `create_group_site()`.
 *
 * @param int   $site_id      Target group site.
 * @param array $events       Normalized events, from `normalize_event()`.
 * @param bool  $match_people Whether to credit hosts and carry RSVPs over, matching people by email.
 * @return array[]|WP_Error One `{ event, status, post_id, message, rsvps }` per event,
 *                          status `created`, `updated`, `skipped`, or `failed`.
 */
function import_events( int $site_id, array $events, bool $match_people = false ) {
	$is_valid = validate_target_site( $site_id );

	if ( is_wp_error( $is_valid ) ) {
		return $is_valid;
	}

	if ( ! class_exists( Event::class ) ) {
		return new WP_Error( 'gatherpress_missing', __( 'GatherPress is not loaded.', 'wordcamporg' ) );
	}

	$results = with_site(
		$site_id,
		function () use ( $events, $match_people ) {
			/*
			 * The recurring-events tables are per-site and created lazily, from
			 * hooks that don't fire for a `switch_to_blog()`. See the same call
			 * in `network-export.php`.
			 */
			if ( class_exists( '\WordPressdotorg\GatherPress_Recurring_Events\Database' ) ) {
				\WordPressdotorg\GatherPress_Recurring_Events\Database::maybe_install();
			}

			return array_map(
				static function ( $event ) use ( $match_people ) {
					// An event can mean a photo download and several pages of
					// RSVPs, so a batch can run long. A batch renews its lock
					// before each one, and stops if another request has taken
					// the lock over, rather than both writing the same events.
					if ( '' !== held_lock() && ! extend_lock() ) {
						throw new \RuntimeException( 'Lost the Meetup import lock to another run.' );
					}

					return import_event( $event, $match_people );
				},
				$events
			);
		}
	);

	Logger\log(
		'finished',
		array(
			'site_id' => $site_id,
			'counts'  => array_count_values( wp_list_pluck( $results, 'status' ) ),
		)
	);

	return $results;
}

/**
 * Create or refresh one event on the current site.
 *
 * Writes the same pieces the frontend's draft save does
 * (`Frontend\REST\save_draft()`), so an imported draft is indistinguishable
 * from one an organizer started by hand. No `wporg_groups_frontend_event_draft_saved`
 * action fires, and events are published with their notification already
 * marked handled, so no emails go out.
 *
 * @param array $event        Normalized event.
 * @param bool  $match_people Whether to credit hosts and carry RSVPs over.
 * @return array
 */
function import_event( array $event, bool $match_people = false ): array {
	$existing = find_existing_event( $event );

	// A date of a Meetup series this import already made a recurring event of.
	if ( ! $existing ) {
		$series = find_covering_series( $event );

		if ( $series ) {
			return array(
				'event'   => $event,
				'status'  => 'in_series',
				'post_id' => $series->ID,
			);
		}
	}

	if ( $existing && 'draft' !== $existing->post_status ) {
		if ( needs_photo( $existing, $event ) ) {
			return add_missing_photo( $existing, $event );
		}

		return array(
			'event'   => $event,
			'status'  => 'skipped',
			'post_id' => $existing->ID,
		);
	}

	if ( $existing && ! get_post_meta( $existing->ID, PENDING_PUBLISH_META, true ) && is_edited_since_import( $existing ) ) {
		return array(
			'event'   => $event,
			'status'  => 'kept',
			'post_id' => $existing->ID,
		);
	}

	// Every new event ends up published: past ones as the group's history,
	// upcoming ones ready for members to RSVP to. It's created as a draft
	// marked to publish, and only published once every other piece is in
	// place, so an import that dies partway leaves a marked draft that the
	// next run finishes -- not a published event missing its date or venue.
	// A draft an earlier import left unmarked stays the organizer's to publish.
	$publish = $existing ? (bool) get_post_meta( $existing->ID, PENDING_PUBLISH_META, true ) : ( $event['is_past'] || publishes_upcoming() );

	$post_args = array(
		'post_type'    => Event::POST_TYPE,
		'post_status'  => 'draft',
		// `sanitize_text_field()` is not enough on its own here. See `wcorg_sanitize_plain_text()`.
		'post_title'   => wcorg_sanitize_plain_text( $event['title'] ),
		'post_content' => $event['description'],
	);

	// Set explicitly, or the event would belong to the network admin running
	// the import, who isn't in the group, and no Event Organizer could edit
	// it. Existing events keep theirs, unless an earlier import left it unset.
	if ( ! $existing || ! (int) $existing->post_author ) {
		$post_args['post_author'] = event_author( $event, $match_people );
	}

	if ( $existing ) {
		update_post_meta( $existing->ID, REFRESHING_META, 1 );

		$post_args['ID'] = $existing->ID;
		$post_id         = wp_update_post( wp_slash( $post_args ), true );
	} else {
		// The Meetup ID goes in with the post itself, so there's no moment
		// where the event exists but a rerun couldn't find it and would
		// create it again.
		$post_args['meta_input'] = array(
			EVENT_ID_META  => $event['meetup_id'],
			EVENT_URL_META => esc_url_raw( $event['meetup_url'] ),
		);

		// Marked as a series from the start, so a rerun after a crash finds it.
		if ( ! empty( $event['recurrence'] ) ) {
			$post_args['meta_input'][ SERIES_ID_META ] = $event['series']['id'];
		}

		if ( $publish ) {
			// First publish emails every member of the group. This marks that
			// notification as already handled, so there's nothing to send.
			$post_args['meta_input'][ PENDING_PUBLISH_META ]                = 1;
			$post_args['meta_input'][ PUBLISH_NOTIFICATION_SCHEDULED_META ] = 1;
		}

		$post_id = wp_insert_post( wp_slash( $post_args ), true );
	}

	if ( is_wp_error( $post_id ) || ! $post_id ) {
		return array(
			'event'   => $event,
			'status'  => 'failed',
			'post_id' => 0,
			'message' => is_wp_error( $post_id ) ? $post_id->get_error_message() : '',
		);
	}

	$post_id = (int) $post_id;

	// Meetup has no event language. A new event gets the one the form would
	// prefill; a refreshed draft keeps its own.
	if ( ! $existing ) {
		Event_Language\set_event_language( $post_id, default_event_language() );
	}

	update_post_meta( $post_id, EVENT_URL_META, esc_url_raw( $event['meetup_url'] ) );

	( new Event( $post_id ) )->save_datetimes(
		array(
			'post_id'        => $post_id,
			'datetime_start' => $event['start'],
			'datetime_end'   => $event['end'],
			'timezone'       => storable_timezone( $event['timezone'] ),
		)
	);

	if ( ! empty( $event['recurrence'] ) ) {
		save_recurrence( $post_id, $event );
	}

	// Meetup doesn't hand out the meeting link, so the organizer adds it while
	// reviewing -- and a refresh keeps one they've added.
	sync_event_venue(
		$post_id,
		find_or_create_venue( $event['venue'] ),
		$event['is_online'],
		$existing ? (string) get_post_meta( $post_id, 'gatherpress_online_event_link', true ) : ''
	);

	// Only fill an empty slot: a refreshed draft keeps whatever image the
	// organizer has picked since.
	$photo_error = '';

	if ( $event['photo'] && ! has_post_thumbnail( $post_id ) ) {
		$attachment_id = find_or_sideload_photo( $event['photo'], $post_id );

		if ( is_wp_error( $attachment_id ) ) {
			$photo_error = sprintf(
				/* translators: %s: Error message. */
				__( 'its photo could not be imported (%s)', 'wordcamporg' ),
				$attachment_id->get_error_message()
			);
		} else {
			set_post_thumbnail( $post_id, $attachment_id );
		}
	}

	// Meetup's own counts, kept for history and reporting. There are no
	// individual RSVP records behind them. A recurring event's would only be
	// its first date's, so it has none.
	if ( empty( $event['recurrence'] ) ) {
		update_post_meta( $post_id, RSVP_YES_META, $event['rsvp_yes'] );
		update_post_meta( $post_id, ATTENDED_META, $event['attended'] );
	}

	// People last, while the event is still a draft: RSVP confirmations only
	// go out for published events (see `import_rsvps()`).
	$rsvps = null;

	if ( $match_people ) {
		import_hosts( $post_id, $event['hosts'] );
	}

	// RSVPs to a recurring event belong to one of its dates, and those only
	// exist once it's published, so a series' upcoming RSVPs stay on Meetup.
	if ( $match_people && empty( $event['recurrence'] ) ) {
		$rsvps = import_rsvps( $post_id, $event['meetup_id'], $event['is_past'] );

		if ( is_wp_error( $rsvps ) ) {
			// Once published, a past event is never touched again, so its
			// RSVPs could never come across later. It stays a marked draft,
			// for the next run to try again.
			if ( $publish ) {
				return array(
					'event'   => $event,
					'status'  => 'failed',
					'post_id' => $post_id,
					'message' => sprintf(
						/* translators: %s: Error message. */
						__( 'Its RSVPs could not be fetched from Meetup (%s). Import again to finish it.', 'wordcamporg' ),
						$rsvps->get_error_message()
					),
				);
			}

			// A draft has its RSVPs fetched again whenever it's refreshed.
			$rsvps = array( 'failed' => 1 );
		}
	}

	if ( $publish ) {
		$publish_args = array(
			'ID'          => $post_id,
			'post_status' => 'publish',
		);

		// A past event is dated when it happened, not when it was imported, so
		// feeds and archives don't present years of history as new. An upcoming
		// one keeps today's date: dated in the future, it would be scheduled
		// rather than published.
		if ( $event['is_past'] ) {
			$happened_gmt = ( new DateTimeImmutable( $event['start'], new DateTimeZone( $event['timezone'] ) ) )
				->setTimezone( new DateTimeZone( 'UTC' ) )
				->format( 'Y-m-d H:i:s' );

			$publish_args['post_date']     = get_date_from_gmt( $happened_gmt );
			$publish_args['post_date_gmt'] = $happened_gmt;
			$publish_args['edit_date']     = true;
		}

		$published = wp_update_post( $publish_args, true );

		if ( is_wp_error( $published ) ) {
			return array(
				'event'   => $event,
				'status'  => 'failed',
				'post_id' => $post_id,
				'message' => $published->get_error_message(),
			);
		}

		delete_post_meta( $post_id, PENDING_PUBLISH_META );
	}

	clean_post_cache( $post_id );
	update_post_meta( $post_id, IMPORTED_MODIFIED_META, get_post_field( 'post_modified_gmt', $post_id ) );
	delete_post_meta( $post_id, REFRESHING_META );

	return array(
		'event'   => $event,
		'status'  => $existing ? 'updated' : 'created',
		'post_id' => $post_id,
		'message' => implode( '; ', array_filter( array( empty( $event['recurrence'] ) ? '' : describe_recurrence( $event ), $photo_error ) ) ),
		'rsvps'   => $rsvps,
	);
}

/**
 * The imported event an event row maps to: by its Meetup ID, or for a
 * recurring event, by its Meetup series.
 *
 * @param array $event Normalized event, on the current site.
 */
function find_existing_event( array $event ): ?\WP_Post {
	$existing = find_imported_post( Event::POST_TYPE, EVENT_ID_META, $event['meetup_id'] );

	if ( ! $existing && ! empty( $event['recurrence'] ) ) {
		$existing = find_series_post( $event['series']['id'] );
	}

	return $existing;
}

/**
 * The recurring event imported from a Meetup series.
 *
 * @param string $series_id Meetup series ID.
 */
function find_series_post( string $series_id ): ?\WP_Post {
	if ( '' === $series_id ) {
		return null;
	}

	return find_imported_post( Event::POST_TYPE, SERIES_ID_META, $series_id );
}

/**
 * The recurring event an unfolded event row is already one of the dates of.
 *
 * A date from before the recurring event starts isn't one of its dates, so a
 * past date of the series is still imported on its own, as history.
 *
 * @param array $event Normalized event, on the current site.
 */
function find_covering_series( array $event ): ?\WP_Post {
	if ( ! empty( $event['recurrence'] ) ) {
		return null;
	}

	$series = find_series_post( $event['series']['id'] ?? '' );

	if ( ! $series ) {
		return null;
	}

	$series_start = ( new Event( $series->ID ) )->get_datetime()['datetime_start_gmt'] ?? '';
	$event_start  = ( new DateTimeImmutable( $event['start'], new DateTimeZone( $event['timezone'] ) ) )->setTimezone( new DateTimeZone( 'UTC' ) );

	if ( '' === $series_start || $event_start < new DateTimeImmutable( $series_start, new DateTimeZone( 'UTC' ) ) ) {
		return null;
	}

	return $series;
}

/**
 * Fold the upcoming dates of each Meetup series into one recurring event.
 *
 * Meetup lists every date of a repeating event as its own event, each
 * carrying the same `series`. The upcoming ones become one event row, the
 * first date's, carrying the recurrence rule (`series_rule()`) and every
 * Meetup ID it stands for.
 *
 * Anything that doesn't fit stays a one-off: past events (they're history,
 * each with its own RSVPs and photo), a series with fewer than two upcoming
 * dates, one whose dates aren't exactly what its rule gives (a date moved,
 * cancelled, or edited on its own), and one with a date already imported as
 * a one-off, so a rerun never leaves both.
 *
 * @param int   $site_id Target group site.
 * @param array $events  Normalized events.
 * @return array Events, with each series' dates replaced by one row.
 */
function fold_series( int $site_id, array $events ): array {
	if ( ! class_exists( Rule::class ) ) {
		return $events;
	}

	$series = array();

	foreach ( $events as $index => $event ) {
		if ( $event['is_past'] || empty( $event['series']['id'] ) ) {
			continue;
		}

		$series[ $event['series']['id'] ][ $index ] = new DateTimeImmutable( $event['start'], new DateTimeZone( $event['timezone'] ) );
	}

	$folded = array();
	$drop   = array();

	foreach ( $series as $starts ) {
		if ( count( $starts ) < 2 ) {
			continue;
		}

		asort( $starts );

		$indexes = array_keys( $starts );
		$first   = $events[ $indexes[0] ];
		$rule    = series_rule( $first['series'], array_values( $starts ) );

		if ( ! $rule || ! series_fits( $events, $indexes, $rule ) ) {
			continue;
		}

		$ids = array_map( static fn( $index ) => $events[ $index ]['meetup_id'], $indexes );

		$already_one_off = with_site(
			$site_id,
			static function () use ( $ids ) {
				foreach ( $ids as $meetup_id ) {
					$post = find_imported_post( Event::POST_TYPE, EVENT_ID_META, $meetup_id );

					if ( $post && ! get_post_meta( $post->ID, SERIES_ID_META, true ) ) {
						return true;
					}
				}

				return false;
			}
		);

		if ( $already_one_off ) {
			continue;
		}

		$folded[ $indexes[0] ] = array_merge(
			$first,
			array(
				'recurrence' => $rule,
				'series_ids' => $ids,
			)
		);

		foreach ( array_slice( $indexes, 1 ) as $index ) {
			$drop[ $index ] = true;
		}
	}

	$result = array();

	foreach ( $events as $index => $event ) {
		if ( isset( $drop[ $index ] ) ) {
			continue;
		}

		$result[] = $folded[ $index ] ?? $event;
	}

	return $result;
}

/**
 * A Meetup series' rule, as the recurring-events plugin stores it.
 *
 * Meetup's own rule maps across, except that a monthly series doesn't say how
 * many months apart its dates are ("Every 3 months on the 4th Sunday" comes
 * back as just the 4th Sunday), so that's read off the first two dates.
 *
 * @param array               $series From `normalize_series()`.
 * @param DateTimeImmutable[] $starts Sorted local start times, at least two.
 * @return array|null The rule, or null for one the plugin can't express.
 */
function series_rule( array $series, array $starts ): ?array {
	$first = $starts[0];
	$rule  = array(
		'frequency'       => '',
		'interval'        => 1,
		'weekdays'        => array(),
		'monthly_mode'    => 'weekday',
		'monthly_day'     => (int) $first->format( 'j' ),
		'monthly_order'   => 'first',
		'monthly_weekday' => strtoupper( substr( $first->format( 'D' ), 0, 2 ) ),
		'end_type'        => '' === $series['end_date'] ? 'never' : 'until',
		'until'           => $series['end_date'],
		'count'           => 12,
	);

	if ( $series['weekly'] ) {
		$rule['frequency'] = 'weekly';
		$rule['interval']  = $series['weekly']['interval'];
		$rule['weekdays']  = $series['weekly']['days'];

		return $rule;
	}

	if ( $series['monthly'] ) {
		$orders = array(
			1 => 'first',
			2 => 'second',
			3 => 'third',
			4 => 'fourth',
			5 => 'last',
		);
		$months = ( (int) $starts[1]->format( 'Y' ) - (int) $first->format( 'Y' ) ) * 12 + (int) $starts[1]->format( 'n' ) - (int) $first->format( 'n' );

		$rule['frequency']       = 'monthly';
		$rule['interval']        = max( 1, $months );
		$rule['monthly_order']   = $orders[ $series['monthly']['week'] ];
		$rule['monthly_weekday'] = $series['monthly']['day'];

		return $rule;
	}

	return null;
}

/**
 * Whether one recurring event can stand in for a series' upcoming dates.
 *
 * Its dates, expanded by the recurring-events plugin itself from the first
 * through the last, have to be exactly Meetup's, and every date has to share
 * the first one's title, length, and venue, since the recurring event has
 * only the one.
 *
 * @param array $events  Normalized events.
 * @param int[] $indexes The series' upcoming dates, as indexes into `$events`, sorted.
 * @param array $rule    From `series_rule()`.
 */
function series_fits( array $events, array $indexes, array $rule ): bool {
	$shape = static function ( array $event ): string {
		$start = new DateTimeImmutable( $event['start'], new DateTimeZone( $event['timezone'] ) );
		$end   = new DateTimeImmutable( $event['end'], new DateTimeZone( $event['timezone'] ) );

		return implode(
			'|',
			array(
				$event['title'],
				$event['timezone'],
				$end->getTimestamp() - $start->getTimestamp(),
				$event['is_online'] ? 'online' : '',
				$event['venue']['meetup_id'] ?? '',
			)
		);
	};

	$want   = array();
	$expect = $shape( $events[ $indexes[0] ] );

	foreach ( $indexes as $index ) {
		if ( $shape( $events[ $index ] ) !== $expect ) {
			return false;
		}

		$want[] = ( new DateTimeImmutable( $events[ $index ]['start'], new DateTimeZone( $events[ $index ]['timezone'] ) ) )->format( 'Y-m-d H:i' );
	}

	$first = new DateTimeImmutable( $events[ $indexes[0] ]['start'], new DateTimeZone( $events[ $indexes[0] ]['timezone'] ) );
	$last  = new DateTimeImmutable( $events[ end( $indexes ) ]['start'], new DateTimeZone( $events[ end( $indexes ) ]['timezone'] ) );
	$got   = array_map(
		static fn( DateTimeImmutable $start ) => $start->format( 'Y-m-d H:i' ),
		Rule::expand( $first, $rule, $last, 1 )
	);

	return $got === $want;
}

/**
 * Save a recurring event's rule, and the Meetup series it stands for.
 *
 * The meta the event form saves (`persist_recurring_event_rule()`). Nothing is
 * projected: the event is a draft, and publishing it projects its dates.
 *
 * @param int   $post_id Event, on the current site.
 * @param array $event   Normalized event, with `recurrence` and `series`.
 */
function save_recurrence( int $post_id, array $event ): void {
	foreach ( $event['recurrence'] as $field => $value ) {
		update_post_meta( $post_id, Rule::META_PREFIX . $field, $value );
	}

	update_post_meta( $post_id, SERIES_ID_META, $event['series']['id'] );
}

/**
 * Say how a recurring event repeats, and how many Meetup events it replaces.
 *
 * @param array $event Normalized event, with `recurrence` and `series_ids`.
 */
function describe_recurrence( array $event ): string {
	global $wp_locale;

	$rule = $event['recurrence'];
	$zone = new DateTimeZone( $event['timezone'] );
	$name = static function ( string $code ) use ( $wp_locale ): string {
		// `get_weekday()` counts from Sunday.
		return $wp_locale->get_weekday( ( array_search( $code, array( 'MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU' ), true ) + 1 ) % 7 );
	};

	if ( 'weekly' === $rule['frequency'] ) {
		$days    = wp_sprintf_l( '%l', array_map( $name, $rule['weekdays'] ) );
		$repeats = 1 === $rule['interval']
			/* translators: %s: Weekday names, e.g. "Monday and Wednesday". */
			? sprintf( __( 'every %s', 'wordcamporg' ), $days )
			/* translators: 1: Number of weeks, 2: Weekday names, e.g. "Monday and Wednesday". */
			: sprintf( __( 'every %1$d weeks on %2$s', 'wordcamporg' ), $rule['interval'], $days );
	} else {
		$orders  = array(
			'first'  => __( 'first', 'wordcamporg' ),
			'second' => __( 'second', 'wordcamporg' ),
			'third'  => __( 'third', 'wordcamporg' ),
			'fourth' => __( 'fourth', 'wordcamporg' ),
			'last'   => __( 'last', 'wordcamporg' ),
		);
		$day     = $name( $rule['monthly_weekday'] );
		$repeats = 1 === $rule['interval']
			/* translators: 1: Ordinal, e.g. "second", 2: Weekday name. */
			? sprintf( __( 'monthly on the %1$s %2$s', 'wordcamporg' ), $orders[ $rule['monthly_order'] ], $day )
			/* translators: 1: Number of months, 2: Ordinal, e.g. "second", 3: Weekday name. */
			: sprintf( __( 'every %1$d months on the %2$s %3$s', 'wordcamporg' ), $rule['interval'], $orders[ $rule['monthly_order'] ], $day );
	}

	if ( 'until' === $rule['end_type'] ) {
		$repeats = sprintf(
			/* translators: 1: How it repeats, e.g. "monthly on the second Wednesday", 2: Last date. */
			__( '%1$s until %2$s', 'wordcamporg' ),
			$repeats,
			wp_date( get_option( 'date_format' ) ?: __( 'F j, Y' ), ( new DateTimeImmutable( $rule['until'] . ' 12:00', $zone ) )->getTimestamp(), $zone )
		);
	}

	return sprintf(
		/* translators: 1: How it repeats, e.g. "monthly on the second Wednesday", 2: Number of Meetup events. */
		_n( 'recurring %1$s, in place of %2$d Meetup event', 'recurring %1$s, in place of %2$d Meetup events', count( $event['series_ids'] ), 'wordcamporg' ),
		$repeats,
		count( $event['series_ids'] )
	);
}

/**
 * Who an imported event should belong to, on the current site.
 *
 * Its first host with a matching account, when people are being matched --
 * added to the group if need be, as `import_hosts()` would -- so an Event
 * Organizer hosting it can edit it. Otherwise the site's lead organizer.
 *
 * @param array $event        Normalized event.
 * @param bool  $match_people Whether people are being matched by email.
 * @return int User ID, or 0 if there's nobody suitable.
 */
function event_author( array $event, bool $match_people ): int {
	if ( $match_people ) {
		foreach ( $event['hosts'] as $email ) {
			$user = get_user_by( 'email', $email );

			if ( $user && ensure_member( get_current_blog_id(), (int) $user->ID ) ) {
				return (int) $user->ID;
			}
		}
	}

	$admins = get_users(
		array(
			'blog_id' => get_current_blog_id(),
			'role'    => 'administrator',
			'orderby' => 'ID',
			'number'  => 1,
			'fields'  => 'ID',
		)
	);

	return $admins ? (int) $admins[0] : 0;
}

/**
 * The language a new event on the current site starts with.
 *
 * What the event form prefills for a new event (`Defaults\get_default_event_data()`):
 * the language of the group's last published event, else the site's own.
 * The site's comes from its `WPLANG` rather than `Event_Language\get_default()`,
 * because `get_locale()` is fixed for the request: in an import request, which
 * runs on the network's root site and switches to the group's, it would give
 * the root site's language.
 *
 * @return string Language subtag, or '' for none.
 */
function default_event_language(): string {
	$previous = Event_Language\get_event_language( Defaults\get_most_recent_event_id() );

	if ( '' !== $previous ) {
		return $previous;
	}

	$locale = (string) get_option( 'WPLANG' );

	return Event_Language\sanitize_code( strtok( '' !== $locale ? $locale : 'en_US', '_-' ) );
}

/**
 * Whether an imported event has been edited on the site since it was imported.
 *
 * Events imported before this was recorded count as untouched, and so does a
 * refresh that didn't finish (see `REFRESHING_META`).
 *
 * @param \WP_Post $post Imported event.
 */
function is_edited_since_import( \WP_Post $post ): bool {
	if ( get_post_meta( $post->ID, REFRESHING_META, true ) ) {
		return false;
	}

	$imported = (string) get_post_meta( $post->ID, IMPORTED_MODIFIED_META, true );

	return '' !== $imported && $imported !== $post->post_modified_gmt;
}

/**
 * Whether an import publishes the upcoming events it creates.
 *
 * Organizers asked for imported meetups to be live straight away, ready to
 * RSVP to, rather than drafts to publish one by one. Like past events, they're
 * published with the new-event notification marked as handled, so nobody is
 * emailed about meetups that already exist on Meetup.
 */
function publishes_upcoming(): bool {
	/**
	 * Filters whether the Meetup import publishes upcoming events, or leaves
	 * them as drafts for the organizer to review.
	 *
	 * @param bool $publish Whether to publish them. Default true.
	 */
	return (bool) apply_filters( 'wcorg_meetup_import_publish_upcoming', true );
}

/**
 * Whether a published event should still get its Meetup photo.
 *
 * Published events are otherwise left alone, so one that was imported
 * without its photo (the download failed, say) would never get it. Only
 * an empty slot is filled, and only on an event nobody has edited since
 * the import, so a photo an organizer removed stays removed.
 *
 * @param \WP_Post $post  Existing imported event, on the current site.
 * @param array    $event Normalized event.
 */
function needs_photo( \WP_Post $post, array $event ): bool {
	return 'publish' === $post->post_status
		&& ! empty( $event['photo'] )
		&& ! has_post_thumbnail( $post )
		&& ! is_edited_since_import( $post );
}

/**
 * Give a published event the Meetup photo it was imported without.
 *
 * @param \WP_Post $post  Existing imported event, on the current site.
 * @param array    $event Normalized event.
 * @return array An `import_events()` row: `photo_added`, or `skipped` with why the photo failed.
 */
function add_missing_photo( \WP_Post $post, array $event ): array {
	$attachment_id = find_or_sideload_photo( $event['photo'], $post->ID );

	if ( is_wp_error( $attachment_id ) ) {
		return array(
			'event'   => $event,
			'status'  => 'skipped',
			'post_id' => $post->ID,
			'message' => sprintf(
				/* translators: %s: Error message. */
				__( 'its photo could not be imported (%s)', 'wordcamporg' ),
				$attachment_id->get_error_message()
			),
		);
	}

	// Post meta only, so the event's modified date, and with it
	// `is_edited_since_import()`, is unchanged.
	set_post_thumbnail( $post->ID, $attachment_id );

	return array(
		'event'   => $event,
		'status'  => 'photo_added',
		'post_id' => $post->ID,
	);
}

/**
 * Work out what an import would do with the group's own details.
 *
 * @param int   $site_id Target group site.
 * @param array $group   Normalized group, from `normalize_group()`.
 * @return string[] Detail => outcome (`set`, `kept`, `missing`).
 */
function plan_group( int $site_id, array $group ): array {
	return with_site(
		$site_id,
		static function () use ( $group ) {
			return apply_group_details( $group, false );
		}
	);
}

/**
 * Bring the group's own details over, wherever the site doesn't have them yet.
 *
 * Callers MUST verify the `manage_sites` capability before calling this, as
 * with `import_events()`.
 *
 * @param int   $site_id Target group site.
 * @param array $group   Normalized group, from `normalize_group()`.
 * @return string[]|WP_Error Detail => outcome (`set`, `kept`, `missing`, `failed`).
 */
function import_group( int $site_id, array $group ) {
	$is_valid = validate_target_site( $site_id );

	if ( is_wp_error( $is_valid ) ) {
		return $is_valid;
	}

	$results = with_site(
		$site_id,
		static function () use ( $group ) {
			return apply_group_details( $group, true );
		}
	);

	Logger\log( 'group_imported', compact( 'site_id', 'results' ) );

	return $results;
}

/**
 * Decide, and optionally write, each group detail on the current site.
 *
 * One function for both the preview and the import, so they can't disagree.
 * Every detail only fills a gap: anything the organizer has already set is
 * theirs and stays.
 *
 * - Location: the same site meta the group settings tab writes, validated the
 *   same way.
 * - About page: only the draft seeded by Add Group Site, and only while it's
 *   untouched: any revision means someone (an organizer, or an earlier
 *   import) has saved it since. Its dates can't tell: saving an unpublished
 *   draft resets its `post_date` too.
 * - Site icon: Meetup's key group photo, downloaded like an event photo.
 *
 * @param array $group Normalized group.
 * @param bool  $write Whether to write, or only report.
 * @return string[]
 */
function apply_group_details( array $group, bool $write ): array {
	$results = array();

	// Location.
	if ( null !== Group_Location\get_location() ) {
		$results['location'] = 'kept';
	} else {
		$location = Group_Location\normalize_location(
			array(
				'type'        => Group_Location\TYPE_PHYSICAL,
				'city'        => $group['city'],
				'countryCode' => $group['country_code'],
			)
		);

		if ( is_wp_error( $location ) ) {
			$results['location'] = 'missing';
		} else {
			if ( $write ) {
				Group_Location\save_location( $location );
			}

			$results['location'] = 'set';
		}
	}

	// About page.
	$about = get_page_by_path( 'about', OBJECT, 'page' );

	if ( '' === $group['description'] ) {
		$results['about'] = 'missing';
	} elseif ( ! $about || 'draft' !== $about->post_status || wp_get_post_revisions( $about->ID ) ) {
		$results['about'] = 'kept';
	} else {
		if ( $write ) {
			wp_update_post(
				wp_slash(
					array(
						'ID'           => $about->ID,
						'post_content' => to_paragraph_blocks( $group['description'] ),
					)
				)
			);
		}

		$results['about'] = 'set';
	}

	// Site icon.
	if ( get_option( 'site_icon' ) ) {
		$results['icon'] = 'kept';
	} elseif ( ! $group['photo'] ) {
		$results['icon'] = 'missing';
	} elseif ( ! $write ) {
		$results['icon'] = 'set';
	} else {
		$attachment_id = find_or_sideload_photo( $group['photo'], 0 );

		if ( is_wp_error( $attachment_id ) ) {
			$results['icon']       = 'failed';
			$results['icon_error'] = $attachment_id->get_error_message();
		} else {
			update_option( 'site_icon', $attachment_id );

			$results['icon'] = 'set';
		}
	}

	// Where the site came from, for the group's history.
	if ( $write ) {
		if ( '' !== $group['urlname'] ) {
			update_option( GROUP_URLNAME_OPTION, $group['urlname'] );
		}

		if ( '' !== $group['founded'] ) {
			update_option( FOUNDED_OPTION, $group['founded'] );
		}
	}

	return $results;
}

/**
 * Add a user to a group site, opted out of its event emails.
 *
 * The one way an import puts someone in a group, whether as a member, an
 * organizer, or a host. The opt-out goes first: GatherPress opts users in by
 * default, nobody should start getting mail because their address turned up
 * in a Meetup export, and if the run died right after adding them, the next
 * run would see an existing member and leave them be.
 *
 * Someone who is already in the group is left exactly as they are.
 *
 * @param int    $site_id Group site.
 * @param int    $user_id User to add.
 * @param string $role    Site role to add them with.
 * @return bool Whether they're in the group now.
 */
function ensure_member( int $site_id, int $user_id, string $role = 'subscriber' ): bool {
	if ( is_user_member_of_blog( $user_id, $site_id ) ) {
		remember_imported_user( $site_id, $user_id );
		return true;
	}

	if ( has_left_group( $site_id, $user_id ) ) {
		return false;
	}

	update_user_meta( $user_id, get_scoped_opt_in_meta_key( $site_id ), '0' );

	if ( is_wp_error( add_user_to_blog( $site_id, $user_id, $role ) ) ) {
		return false;
	}

	remember_imported_user( $site_id, $user_id );

	return true;
}

/**
 * Note that an import has added a user to the group, or found them in it.
 *
 * @param int $site_id Group site.
 * @param int $user_id User.
 */
function remember_imported_user( int $site_id, int $user_id ): void {
	$user_ids = array_map( 'intval', (array) get_blog_option( $site_id, IMPORTED_USERS_OPTION, array() ) );

	if ( ! in_array( $user_id, $user_ids, true ) ) {
		$user_ids[] = $user_id;
		update_blog_option( $site_id, IMPORTED_USERS_OPTION, $user_ids );
	}
}

/**
 * Whether a user an import once put in the group has since left it.
 *
 * @param int $site_id Group site.
 * @param int $user_id User.
 */
function has_left_group( int $site_id, int $user_id ): bool {
	return in_array( $user_id, array_map( 'intval', (array) get_blog_option( $site_id, IMPORTED_USERS_OPTION, array() ) ), true )
		&& ! is_user_member_of_blog( $user_id, $site_id );
}

/**
 * The highest-ranking role a user has on a site.
 *
 * @param int $user_id User.
 * @param int $site_id Site.
 * @return int Rank from `ROLE_RANK`, or -1 for none.
 */
function role_rank( int $user_id, int $site_id ): int {
	$roles = ( new WP_User( $user_id, '', $site_id ) )->roles;
	$ranks = array_map(
		static function ( $role ) {
			return ROLE_RANK[ $role ] ?? 0;
		},
		$roles
	);

	return $ranks ? max( $ranks ) : -1;
}

/**
 * Sort a group's Meetup organizers by what an import does with each one.
 *
 * @param int   $site_id    Target group site.
 * @param array $organizers Normalized organizers, from `normalize_organizer()`.
 * @return array[] One `{ organizer, outcome, user_id }` per organizer with a mapped role.
 */
function classify_organizers( int $site_id, array $organizers ): array {
	$classified = array();
	$handled    = array_map( 'intval', (array) get_blog_option( $site_id, IMPORTED_ORGANIZERS_OPTION, array() ) );

	foreach ( $organizers as $organizer ) {
		if ( ! $organizer['role'] ) {
			continue;
		}

		$user    = '' !== $organizer['email'] ? get_user_by( 'email', $organizer['email'] ) : false;
		$user_id = $user ? (int) $user->ID : 0;

		if ( '' === $organizer['email'] ) {
			$outcome = 'no_email';
		} elseif ( ! $user_id ) {
			$outcome = 'unmatched';
		} elseif ( has_left_group( $site_id, $user_id ) ) {
			$outcome = 'left';
		} elseif ( in_array( $user_id, $handled, true ) ) {
			// Handled by an earlier import; their role is the group's now.
			$outcome = 'kept';
		} elseif ( ! is_user_member_of_blog( $user_id, $site_id ) ) {
			$outcome = 'added';
		} elseif ( role_rank( $user_id, $site_id ) < ROLE_RANK[ $organizer['role'] ] ) {
			$outcome = 'promoted';
		} else {
			$outcome = 'kept';
		}

		$classified[] = compact( 'organizer', 'outcome', 'user_id' );
	}

	return $classified;
}

/**
 * Count what an import would do with a group's organizers, without writing anything.
 *
 * @param int   $site_id    Target group site.
 * @param array $organizers Normalized organizers.
 * @return int[] Counts keyed by outcome.
 */
function plan_organizers( int $site_id, array $organizers ): array {
	return array_count_values( wp_list_pluck( classify_organizers( $site_id, $organizers ), 'outcome' ) );
}

/**
 * Give a group's Meetup organizers the matching Organizer role on its site.
 *
 * Matched by email to existing WordPress.org accounts only. Only ever
 * promotes: someone already in that role or higher keeps theirs, and nobody
 * is made an administrator (see `MEETUP_ORGANIZER_ROLES`). And only once:
 * an organizer an earlier import handled is left alone, so a group that
 * demotes one doesn't see them promoted back.
 *
 * Callers MUST verify the `manage_sites` capability before calling this, as
 * with `import_events()`.
 *
 * @param int   $site_id    Target group site.
 * @param array $organizers Normalized organizers.
 * @return int[] Counts keyed by outcome.
 */
function import_organizers( int $site_id, array $organizers ): array {
	$classified = classify_organizers( $site_id, $organizers );

	foreach ( $classified as $index => $entry ) {
		if ( 'added' === $entry['outcome'] ) {
			if ( ! ensure_member( $site_id, $entry['user_id'], $entry['organizer']['role'] ) ) {
				$classified[ $index ]['outcome'] = 'failed';
			}
		} elseif ( 'promoted' === $entry['outcome'] ) {
			( new WP_User( $entry['user_id'], '', $site_id ) )->set_role( $entry['organizer']['role'] );
		}
	}

	$handled = array_map( 'intval', (array) get_blog_option( $site_id, IMPORTED_ORGANIZERS_OPTION, array() ) );

	foreach ( $classified as $entry ) {
		if ( in_array( $entry['outcome'], array( 'added', 'promoted', 'kept' ), true ) ) {
			$handled[] = $entry['user_id'];
		}
	}

	update_blog_option( $site_id, IMPORTED_ORGANIZERS_OPTION, array_values( array_unique( $handled ) ) );

	$counts = array_count_values( wp_list_pluck( $classified, 'outcome' ) );

	Logger\log( 'organizers_imported', compact( 'site_id', 'counts' ) );

	return $counts;
}

/**
 * Credit an event's Meetup hosts as its hosts here.
 *
 * Hosts are matched to existing WordPress.org accounts by email and added to
 * the group if they aren't in it, since a host has to be a member. Only fills
 * an event with no hosts yet, so a refreshed draft keeps the organizer's
 * choice. Runs on the event's own site.
 *
 * @param int   $post_id Event post ID.
 * @param array $emails  Normalized host emails.
 */
function import_hosts( int $post_id, array $emails ): void {
	if ( ! $emails || get_post_meta( $post_id, Event_Hosts\META_KEY, true ) ) {
		return;
	}

	$user_ids = array();

	foreach ( $emails as $email ) {
		$user = get_user_by( 'email', $email );

		if ( $user && ensure_member( get_current_blog_id(), (int) $user->ID ) ) {
			$user_ids[] = (int) $user->ID;
		}
	}

	Event_Hosts\set_event_hosts( $post_id, $user_ids );
}

/**
 * Carry an event's Meetup RSVPs over as real RSVPs, for matched people only.
 *
 * Saved through GatherPress's own RSVP API, so its rules apply as for any
 * RSVP (attendance limits, the waiting list). RSVPing doesn't add anyone to
 * the group. An "attended" RSVP is also checked in, the same record the
 * check-in screen keeps, so reporting counts it as attendance.
 *
 * No confirmation email goes out: those are only sent when the event is
 * published and the person whose RSVP it is made the change
 * (`Frontend\RSVP_Confirmation\send_confirmation()`), and this runs as the
 * admin, on an event that's still a draft. Runs on the event's own site.
 *
 * @param int    $post_id         Event post ID.
 * @param string $meetup_event_id Meetup event ID.
 * @param bool   $is_past         Whether the event has already happened, so
 *                                its waiting list is left out (see `RSVP_STATUSES`).
 * @return int[]|WP_Error Counts: `saved`, `checked_in`, `unmatched`, `skipped`,
 *                        `failed`; or the error if Meetup's RSVPs couldn't be fetched.
 */
function import_rsvps( int $post_id, string $meetup_event_id, bool $is_past = false ) {
	$counts = array_fill_keys( array( 'saved', 'checked_in', 'unmatched', 'skipped', 'failed' ), 0 );
	$raw    = fetch_event_rsvps( $meetup_event_id );
	$rsvp   = new Rsvp( $post_id );

	if ( is_wp_error( $raw ) ) {
		return $raw;
	}

	foreach ( array_map( __NAMESPACE__ . '\normalize_rsvp', $raw ) as $entry ) {
		if ( ! $entry['status'] || ( $is_past && $entry['waitlisted'] ) ) {
			++$counts['skipped'];
			continue;
		}

		$user = '' !== $entry['email'] ? get_user_by( 'email', $entry['email'] ) : false;

		if ( ! $user ) {
			++$counts['unmatched'];
			continue;
		}

		try {
			$saved = $rsvp->save( (int) $user->ID, $entry['status'], 0, $entry['guests'] );
		} catch ( \Throwable $e ) {
			// One RSVP GatherPress chokes on shouldn't take the rest of the import down with it.
			Logger\log(
				'rsvp_failed',
				array(
					'post_id' => $post_id,
					'error'   => $e->getMessage(),
				)
			);
			$saved = array();
		}

		if ( empty( $saved['comment_id'] ) ) {
			++$counts['failed'];
			continue;
		}

		++$counts['saved'];

		if ( $entry['checked_in'] ) {
			$comment = get_comment( (int) $saved['comment_id'] );

			if ( $comment ) {
				Check_In\set_checked_in( $comment, true );
				++$counts['checked_in'];
			}
		}
	}

	return $counts;
}

/**
 * Add up the RSVP counts across an import's events.
 *
 * @param array[] $results From `import_events()`.
 * @return int[]
 */
function sum_rsvp_counts( array $results ): array {
	$totals = array_fill_keys( array( 'saved', 'checked_in', 'unmatched', 'skipped', 'failed' ), 0 );

	foreach ( $results as $result ) {
		foreach ( $result['rsvps'] ?? array() as $key => $count ) {
			$totals[ $key ] += $count;
		}
	}

	return $totals;
}

/**
 * Sort a group's Meetup members by what an import does with each one.
 *
 * In order: a member an earlier import already considered is left alone (so
 * someone who has since left the group isn't added back); one without a
 * shared email, or whose email matches no WordPress.org account, is only
 * counted; one who is already in the group (or will be, see `plan_members()`)
 * stays as they are; the rest join.
 *
 * @param int   $site_id Target group site.
 * @param array $members Normalized members, from `normalize_member()`.
 * @param int[] $joining Users the import adds before it gets to the members.
 * @return array[] One `{ member, outcome, user_id }` per member.
 */
function classify_members( int $site_id, array $members, array $joining = array() ): array {
	$seen       = array_flip( (array) get_blog_option( $site_id, MEMBERS_OPTION, array() ) );
	$classified = array();

	foreach ( $members as $member ) {
		$user    = '' !== $member['email'] ? get_user_by( 'email', $member['email'] ) : false;
		$user_id = $user ? (int) $user->ID : 0;

		if ( isset( $seen[ $member['meetup_id'] ] ) || ( $user_id && has_left_group( $site_id, $user_id ) ) ) {
			$outcome = 'previously_imported';
		} elseif ( '' === $member['email'] ) {
			$outcome = 'no_email';
		} elseif ( ! $user_id ) {
			$outcome = 'unmatched';
		} elseif ( is_user_member_of_blog( $user_id, $site_id ) || in_array( $user_id, $joining, true ) ) {
			$outcome = 'already_member';
		} else {
			$outcome = 'join';
		}

		$classified[] = compact( 'member', 'outcome', 'user_id' );
	}

	return $classified;
}

/**
 * Count what an import would do with a group's members, without writing anything.
 *
 * Meetup lists organizers as members too, and organizers are imported first:
 * one the import adds is already in the group by the time members are
 * imported, so they're counted here as the import will count them.
 *
 * @param int   $site_id    Target group site.
 * @param array $members    Normalized members.
 * @param array $organizers Normalized organizers, from `normalize_organizer()`.
 * @return int[] Counts keyed by outcome.
 */
function plan_members( int $site_id, array $members, array $organizers = array() ): array {
	$joining = wp_list_pluck( wp_list_filter( classify_organizers( $site_id, $organizers ), array( 'outcome' => 'added' ) ), 'user_id' );

	return count_member_outcomes( classify_members( $site_id, $members, array_values( $joining ) ) );
}

/**
 * Add a group's Meetup members who already have WordPress.org accounts.
 *
 * Callers MUST verify the `manage_sites` capability before calling this, as
 * with `import_events()`.
 *
 * A match joins as a Member, the role the frontend's own join button grants
 * (`Members_Controller::join_group()`), and is opted out of this group's
 * event emails: GatherPress opts users in by default, and nobody should start
 * getting mail because their address turned up in a Meetup export. They can
 * opt in from the group like anyone else.
 *
 * @param int   $site_id Target group site.
 * @param array $members Normalized members.
 * @return int[]|WP_Error Counts keyed by outcome.
 */
function import_members( int $site_id, array $members ) {
	$is_valid = validate_target_site( $site_id );

	if ( is_wp_error( $is_valid ) ) {
		return $is_valid;
	}

	$classified = classify_members( $site_id, $members );
	$seen       = (array) get_blog_option( $site_id, MEMBERS_OPTION, array() );

	foreach ( $classified as $index => $entry ) {
		if ( 'join' === $entry['outcome'] && ! ensure_member( $site_id, $entry['user_id'] ) ) {
			$classified[ $index ]['outcome'] = 'failed';
			continue;
		}

		if ( 'already_member' === $entry['outcome'] ) {
			remember_imported_user( $site_id, $entry['user_id'] );
		}

		if ( in_array( $entry['outcome'], array( 'join', 'already_member' ), true ) ) {
			$seen[] = $entry['member']['meetup_id'];
		}
	}

	update_blog_option( $site_id, MEMBERS_OPTION, array_values( array_unique( $seen ) ) );

	$counts = count_member_outcomes( $classified );

	Logger\log( 'members_imported', compact( 'site_id', 'counts' ) );

	return $counts;
}

/**
 * Tally classified members by outcome, with every outcome present.
 *
 * @param array[] $classified From `classify_members()`.
 * @return int[]
 */
function count_member_outcomes( array $classified ): array {
	return array_merge(
		array_fill_keys( array( 'join', 'already_member', 'previously_imported', 'unmatched', 'no_email', 'failed' ), 0 ),
		array_count_values( wp_list_pluck( $classified, 'outcome' ) )
	);
}

/**
 * Find the attachment a Meetup photo was imported as, downloading it the first time.
 *
 * A recurring meetup usually reuses one cover photo across its events, so
 * keying on Meetup's photo ID downloads it once per group site rather than
 * once per event. A failed download leaves the event without an image; it
 * doesn't fail the event, but the reason is returned so the import screen
 * can show it.
 *
 * @param array $photo   Normalized photo, from `normalize_photo()`.
 * @param int   $post_id Event the photo is first attached to.
 * @return int|WP_Error Attachment ID, or why it couldn't be saved.
 */
function find_or_sideload_photo( array $photo, int $post_id ) {
	$existing = find_imported_post( 'attachment', PHOTO_ID_META, $photo['meetup_id'] );

	if ( $existing ) {
		return $existing->ID;
	}

	/**
	 * Short-circuit downloading a Meetup photo, e.g. in tests.
	 *
	 * @param int|WP_Error|null $attachment_id Attachment ID, or null to download it.
	 * @param string            $url           Photo URL on Meetup's image host.
	 * @param int               $post_id       Event the photo is attached to.
	 */
	$attachment_id = apply_filters( 'wcorg_meetup_import_pre_sideload_photo', null, $photo['url'], $post_id );

	if ( null === $attachment_id ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		add_filter( 'upload_dir', __NAMESPACE__ . '\switched_upload_dir' );

		try {
			$attachment_id = media_sideload_image( $photo['url'], $post_id, null, 'id' );
		} finally {
			remove_filter( 'upload_dir', __NAMESPACE__ . '\switched_upload_dir' );
		}
	}

	if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
		$error = is_wp_error( $attachment_id )
			? $attachment_id
			: new WP_Error( 'photo_failed', __( 'WordPress did not save the photo.', 'wordcamporg' ) );

		Logger\log(
			'photo_failed',
			array(
				'url'   => $photo['url'],
				'error' => $error->get_error_message(),
			)
		);

		return $error;
	}

	update_post_meta( (int) $attachment_id, PHOTO_ID_META, $photo['meetup_id'] );

	return (int) $attachment_id;
}

/**
 * Point uploads at the group site's own `blogs.dir` folder while switched to it.
 *
 * With ms-files rewriting, core only uses `BLOGUPLOADDIR` for the site the
 * request started on. Switched to another site, `_wp_upload_dir()` falls back
 * to that site's relative `upload_path` joined to `ABSPATH`, which is where
 * WordPress itself lives (`mu/`), not `WP_CONTENT_DIR`. An import runs on the
 * network's root site, switched to the group's, so photos would be written
 * under `mu/wp-content/blogs.dir`, which production can't create, and get
 * URLs that don't resolve.
 *
 * @param array $uploads From `wp_upload_dir()`.
 * @return array
 */
function switched_upload_dir( array $uploads ): array {
	if ( ! ms_is_switched() || ! get_site_option( 'ms_files_rewriting' ) ) {
		return $uploads;
	}

	$basedir = WP_CONTENT_DIR . '/blogs.dir/' . get_current_blog_id() . '/files';
	$baseurl = trailingslashit( get_option( 'siteurl' ) ) . 'files';

	return array_merge(
		$uploads,
		array(
			'path'    => $basedir . $uploads['subdir'],
			'url'     => $baseurl . $uploads['subdir'],
			'basedir' => $basedir,
			'baseurl' => $baseurl,
		)
	);
}

/**
 * Find the venue a Meetup venue was imported as, creating it the first time.
 *
 * An existing venue is never updated: once it's on the site, the organizer
 * owns it. New venues are created the way `Frontend\REST\resolve_venue_id()`
 * creates them, with the address in `gatherpress_address` so GatherPress
 * geocodes it.
 *
 * @param array|null $venue Normalized venue, or null for none.
 * @return int Venue post ID, or 0.
 */
function find_or_create_venue( ?array $venue ): int {
	if ( ! $venue ) {
		return 0;
	}

	$existing = find_imported_post( Venue::POST_TYPE, VENUE_ID_META, $venue['meetup_id'] );

	if ( $existing ) {
		return $existing->ID;
	}

	$venue_id = wp_insert_post(
		array(
			'post_type'   => Venue::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => wcorg_sanitize_plain_text( $venue['name'] ),
			// With the post, so a rerun can always find it (see `import_event()`).
			'meta_input'  => array( VENUE_ID_META => $venue['meetup_id'] ),
		),
		true
	);

	if ( is_wp_error( $venue_id ) || ! $venue_id ) {
		return 0;
	}

	if ( '' !== $venue['address'] ) {
		update_post_meta( $venue_id, 'gatherpress_address', $venue['address'] );
	}

	return (int) $venue_id;
}

/**
 * Find a post previously imported from Meetup, in any status.
 *
 * @param string $post_type Post type.
 * @param string $meta_key  Meta key holding the Meetup ID.
 * @param string $meetup_id Meetup ID.
 * @return \WP_Post|null
 */
function find_imported_post( string $post_type, string $meta_key, string $meetup_id ): ?\WP_Post {
	$posts = get_posts(
		array(
			'post_type'        => $post_type,
			'post_status'      => 'any,trash',
			'posts_per_page'   => 1,
			'meta_key'         => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one lookup per imported event.
			'meta_value'       => $meetup_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'suppress_filters' => true,
			'no_found_rows'    => true,
		)
	);

	return $posts[0] ?? null;
}

/**
 * The options table that holds the import lock.
 *
 * Always the Groups network's root site, whichever blog the request is on, so
 * there's one lock for the whole network.
 */
function lock_table(): string {
	global $wpdb;

	return $wpdb->get_blog_prefix( GROUPS_ROOT_BLOG_ID ) . 'options';
}

/**
 * Take the import lock, if nobody else holds it.
 *
 * The same approach as core's `WP_Upgrader::create_lock()`: inserting a row
 * with a unique name is atomic, so only one request can win it. A lock older
 * than `LOCK_TIMEOUT` is assumed abandoned and taken over -- with a
 * compare-and-swap on its old value, so two requests can't both take over the
 * same stale lock.
 *
 * The stored value is the expiry plus a random token, so each holder's value
 * is its own and `release_lock()` can tell whether the lock is still theirs.
 *
 * @return bool Whether this request now holds the lock.
 */
function acquire_lock(): bool {
	global $wpdb;

	$table   = lock_table();
	$expires = ( time() + LOCK_TIMEOUT ) . ':' . wp_generate_password( 12, false );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the lock has to bypass the options cache to be atomic.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- `$table` is built from the blog prefix, not input.
	$inserted = $wpdb->query(
		$wpdb->prepare(
			"INSERT IGNORE INTO `$table` ( `option_name`, `option_value`, `autoload` ) VALUES ( %s, %s, 'off' )",
			LOCK_OPTION,
			$expires
		)
	);

	if ( $inserted ) {
		held_lock( $expires );
		return true;
	}

	$current = $wpdb->get_var( $wpdb->prepare( "SELECT `option_value` FROM `$table` WHERE `option_name` = %s", LOCK_OPTION ) );

	if ( null === $current || (int) $current > time() ) {
		return false;
	}

	$taken_over = $wpdb->query(
		$wpdb->prepare(
			"UPDATE `$table` SET `option_value` = %s WHERE `option_name` = %s AND `option_value` = %s",
			$expires,
			LOCK_OPTION,
			$current
		)
	);
	// phpcs:enable

	if ( 1 !== $taken_over ) {
		return false;
	}

	held_lock( $expires );
	return true;
}

/**
 * Push the expiry of the lock this request holds back to a full `LOCK_TIMEOUT`.
 *
 * A compare-and-swap on the held value, as in `acquire_lock()`: if the lock
 * expired and another run took it over, this one doesn't get it back.
 *
 * @return bool Whether this request still holds the lock.
 */
function extend_lock(): bool {
	global $wpdb;

	if ( '' === held_lock() ) {
		return false;
	}

	$table   = lock_table();
	$expires = ( time() + LOCK_TIMEOUT ) . ':' . wp_generate_password( 12, false );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the lock has to bypass the options cache to be atomic.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- `$table` is built from the blog prefix, not input.
	$extended = $wpdb->query(
		$wpdb->prepare(
			"UPDATE `$table` SET `option_value` = %s WHERE `option_name` = %s AND `option_value` = %s",
			$expires,
			LOCK_OPTION,
			held_lock()
		)
	);
	// phpcs:enable

	held_lock( 1 === $extended ? $expires : '' );

	return 1 === $extended;
}

/**
 * The lock value this request holds, if any.
 *
 * @param string|null $value New value to remember; '' to forget it; null to read.
 */
function held_lock( ?string $value = null ): string {
	static $held = '';

	if ( null !== $value ) {
		$held = $value;
	}

	return $held;
}

/**
 * Give the import lock back, if this request still holds it.
 *
 * Only the row with this request's own value is deleted: if the lock expired
 * and another import took it over, that one keeps it. Safe to call more than
 * once, which matters because it runs both when the batch finishes and at
 * shutdown (see `process_batch()`).
 */
function release_lock(): void {
	global $wpdb;

	if ( '' === held_lock() ) {
		return;
	}

	$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		lock_table(),
		array(
			'option_name'  => LOCK_OPTION,
			'option_value' => held_lock(),
		)
	);

	held_lock( '' );
}

/**
 * Run a callback on a site and always switch back.
 *
 * The switch sits inside the try for the same reason as in
 * `network-export.php`: a throwing `switch_blog` listener would otherwise
 * leave the request on the wrong blog.
 *
 * @param int      $site_id  Site to run on.
 * @param callable $callback What to run there.
 * @return mixed The callback's return value.
 */
function with_site( int $site_id, callable $callback ) {
	try {
		switch_to_blog( $site_id );

		return $callback();
	} finally {
		restore_current_blog();
	}
}
