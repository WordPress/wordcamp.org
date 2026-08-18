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
 * consent basis for including them (see the issue's privacy discussion).
 *
 * Generation runs on cron, a few sites per batch, rather than in the request:
 * walking every group site's events and RSVPs synchronously would time out as
 * the network grows. The in-progress job and the finished artifact each live
 * in a network option; downloads are rendered from the artifact on demand.
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
 * Column order for the CSV download: one flat row per event, aggregate
 * counts only.
 */
const CSV_COLUMNS = array(
	'group_name',
	'group_url',
	'event_id',
	'event_title',
	'event_start_gmt',
	'event_end_gmt',
	'venue',
	'attending_count',
	'waiting_list_count',
	'not_attending_count',
);

// Priority 11: the parent "Groups" menu is registered by the archive screen
// at priority 9, and submenus registered before their parent break.
add_action( 'network_admin_menu', __NAMESPACE__ . '\add_page', 11 );
add_action( 'admin_post_' . FORM_ACTION, __NAMESPACE__ . '\handle_start_export' );
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
 * @param int[] $site_ids Group sites to export.
 * @return string The job's ID, or an empty string when a run is already in
 *                flight or the job store was locked by a concurrent update.
 */
function queue_export( array $site_ids ): string {
	$job = array(
		'id'            => wp_generate_uuid4(),
		'sites'         => array_values( $site_ids ),
		'pending_sites' => array_values( $site_ids ),
		'rows'          => array(),
		'failed_sites'  => array(),
		'author'        => get_current_user_id(),
		'created'       => time(),
	);

	$queued = with_job_lock(
		function () use ( $job ) {
			if ( get_job() ) {
				return false;
			}

			// The new run supersedes the previous artifact; leaving it up
			// while a fresher one generates invites downloading stale data.
			delete_site_option( EXPORT_OPTION );

			update_site_option( JOB_OPTION, $job );

			return true;
		}
	);

	if ( ! $queued ) {
		return '';
	}

	schedule_next_batch();

	return $job['id'];
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
 *               acquired and `$steal` is false.
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
 * Process up to `SITES_PER_BATCH` group sites of the in-flight export.
 *
 * The job is claimed off `JOB_OPTION` under the lock and committed back the
 * same way, but the per-site collection in between runs unlocked — holding
 * the lock across several sites' bulk queries would block the start form for
 * that long.
 */
function process_batch(): void {
	$job = with_job_lock(
		function () {
			$job = get_job();

			if ( empty( $job ) ) {
				return null;
			}

			// Claiming removes the job for the duration of the batch, so a
			// concurrent start can't resurrect stale progress. It also means
			// a concurrent start would begin a *new* run — which is why the
			// commit below uses `$steal` rather than giving up.
			delete_site_option( JOB_OPTION );

			return $job;
		}
	);

	if ( empty( $job ) ) {
		return;
	}

	$processed = 0;
	$crashed   = false;

	/*
	 * The job only exists in memory between the claim above and the commit
	 * below, so a `Throwable` escaping the loop must not skip the commit —
	 * the whole run's progress would vanish. Per-site failures are handled
	 * inside the loop and recorded on the job; this outer guard is only for
	 * something that couldn't be contained that way.
	 */
	try {
		while ( $processed < SITES_PER_BATCH && ! empty( $job['pending_sites'] ) ) {
			++$processed;

			$site_id = (int) array_shift( $job['pending_sites'] );

			try {
				$rows = collect_site_rows( $site_id );
			} catch ( \Throwable $site_error ) {
				$rows = new WP_Error( 'export_site_crashed', $site_error->getMessage() );
			}

			if ( is_wp_error( $rows ) ) {
				// A failed site is recorded, not silently omitted — a file
				// that quietly misses a group reads as "that group had no
				// events". Labelled by path, not blogname: reading the
				// blogname means switching into the very site that just
				// failed, which could throw again outside any restore.
				$site = get_site( $site_id );

				$job['failed_sites'][ $site_id ] = $site ? untrailingslashit( $site->path ) : "site {$site_id}";

				Logger\log(
					'groups_export_site_failed',
					array(
						'job'   => $job['id'],
						'site'  => $site_id,
						'error' => $rows->get_error_code(),
					)
				);

				continue;
			}

			$job['rows'] = array_merge( $job['rows'], $rows );
		}
	} catch ( \Throwable $error ) {
		$crashed = true;

		// Hand-built array rather than the Throwable itself; see the
		// equivalent note in `Messaging\process_batch()` about
		// `redact_keys()` mangling non-Exception Throwables.
		Logger\log(
			'groups_export_batch_crashed',
			array(
				'job'   => $job['id'],
				'error' => array(
					'class'   => get_class( $error ),
					'message' => $error->getMessage(),
					'file'    => $error->getFile(),
					'line'    => $error->getLine(),
				),
			)
		);
	}

	$finished = ! $crashed && empty( $job['pending_sites'] );

	with_job_lock(
		function () use ( $job, $finished ) {
			if ( $finished ) {
				record_artifact( $job );
			} else {
				update_site_option( JOB_OPTION, $job );
			}
		},
		// The batch's progress only exists in memory at this point, so
		// abandoning the write would redo — or lose — this run's work.
		true
	);

	if ( ! $finished ) {
		schedule_next_batch();
	}
}

/**
 * Collect one group site's events as aggregate export rows.
 *
 * Reuses the per-group collector, which resolves venues, dates, and status
 * counts with all its table lookups re-derived from the switched blog's
 * prefix. The attendee-level data it also returns is dropped here: nothing
 * identifying leaves this function.
 *
 * @param int $site_id Group site ID.
 * @return array[]|WP_Error Aggregate rows, or the collector's error.
 */
function collect_site_rows( int $site_id ) {
	// The switch sits inside the try: it pushes the switched-blog stack
	// before firing its action, so a Throwable from a `switch_blog` listener
	// would otherwise leave the process stuck on the wrong blog.
	try {
		switch_to_blog( $site_id );

		$data = \WordCamp\Groups\Frontend\Export\collect_export_data();

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$rows = array();

		foreach ( $data['events'] as $event ) {
			$rows[] = array(
				'group_name'          => $data['group']['name'],
				'group_url'           => $data['group']['url'],
				'event_id'            => (int) $event['id'],
				'event_title'         => $event['title'],
				'event_start_gmt'     => $event['start_gmt'],
				'event_end_gmt'       => $event['end_gmt'],
				'venue'               => $event['venue'],
				'attending_count'     => (int) $event['counts']['attending'],
				'waiting_list_count'  => (int) $event['counts']['waiting_list'],
				'not_attending_count' => (int) $event['counts']['not_attending'],
			);
		}

		return $rows;
	} finally {
		restore_current_blog();
	}
}

/**
 * Store the finished export, so the screen can offer it for download.
 *
 * @param array $job Completed job.
 */
function record_artifact( array $job ): void {
	update_site_option(
		EXPORT_OPTION,
		array(
			'generated_gmt' => gmdate( 'Y-m-d H:i:s' ),
			'author'        => (int) $job['author'],
			'site_count'    => count( $job['sites'] ),
			'event_count'   => count( $job['rows'] ),
			'failed_sites'  => $job['failed_sites'],
			'rows'          => $job['rows'],
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

	if ( get_job() ) {
		redirect_with_notice( 'busy' );
	}

	if ( ! queue_export( $site_ids ) ) {
		redirect_with_notice( 'queue-locked' );
	}

	redirect_with_notice( 'queued', count( $site_ids ) );
}

/**
 * Handle a download request for the last completed export.
 */
function handle_download(): void {
	if ( ! current_user_can_export() ) {
		wp_die( 'You do not have permission to download group data.', 403 );
	}

	check_admin_referer( DOWNLOAD_ACTION );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified by check_admin_referer() above.
	$format = sanitize_key( wp_unslash( $_GET['format'] ?? 'csv' ) );

	if ( ! in_array( $format, array( 'csv', 'json' ), true ) ) {
		$format = 'csv';
	}

	$payload = get_download_payload( $format );

	if ( is_wp_error( $payload ) ) {
		redirect_with_notice( 'no-export' );
	}

	nocache_headers();
	header( 'Content-Type: ' . $payload['content_type'] );
	header( 'Content-Disposition: attachment; filename="' . $payload['filename'] . '"' );
	header( 'X-Content-Type-Options: nosniff' );

	echo $payload['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- File body; CSV cells are escaped by esc_csv_cell(), JSON by wp_json_encode().
	exit;
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
		return array(
			'filename'     => $filename,
			'content_type' => 'application/json; charset=utf-8',
			'body'         => (string) wp_json_encode( build_network_json( $artifact ) ),
		);
	}

	return array(
		'filename'     => $filename,
		'content_type' => 'text/csv; charset=utf-8',
		'body'         => build_network_csv( $artifact['rows'] ),
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
 */
function build_network_csv( array $rows ): string {
	// phpcs:disable WordPress.WP.AlternativeFunctions -- php://temp is an in-memory stream for fputcsv(), not a filesystem write; WP_Filesystem doesn't apply.
	$handle = fopen( 'php://temp', 'r+' );

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

	return $csv;
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
			'id'        => $row['event_id'],
			'title'     => $row['event_title'],
			'start_gmt' => $row['event_start_gmt'],
			'end_gmt'   => $row['event_end_gmt'],
			'venue'     => $row['venue'],
			'counts'    => array(
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
		'generated_gmt' => $artifact['generated_gmt'],
		'site_count'    => (int) $artifact['site_count'],
		'event_count'   => (int) $artifact['event_count'],
		'failed_sites'  => $failed,
		'groups'        => array_values( $groups ),
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
		'queued'       => array(
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
		'busy'         => array( 'error', 'An export is already running. Wait for it to finish before starting another.' ),
		'no-groups'    => array( 'error', 'There are no group sites to export.' ),
		'queue-locked' => array( 'error', 'The export could not be started because another update was in progress. Please try again.' ),
		'no-export'    => array( 'error', 'No completed export is available to download. Start one first.' ),
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

	$author = get_userdata( (int) $artifact['author'] );
	$failed = (array) ( $artifact['failed_sites'] ?? array() );

	printf(
		'<h2>Last export</h2><p class="description">%s</p>',
		esc_html(
			sprintf(
				'%1$s event(s) across %2$s group(s), generated by %3$s on %4$s (UTC).%5$s',
				number_format_i18n( (int) $artifact['event_count'] ),
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
