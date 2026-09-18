<?php

/**
 * One-time migration, deleted once it has run across the network.
 *
 * Gives the payment files that predate the upload hooks in `privacy.php` the marker and the `private` status
 * those hooks now write. Database only; nothing here touches a file on disk. The two meta keys it writes live
 * in `privacy.php` because they outlive it.
 */

namespace WordCamp\Budgets\Files\Backfill;

use WP_CLI, WP_CLI_Command;

use function WP_CLI\Utils\format_items;

use function WordCamp\Budgets\Privacy\get_budget_request_post_types;
use function WordCamp\Budgets\Privacy\mark_budget_file;

use const WordCamp\Budgets\Privacy\BUDGET_FILE_BACKFILLED_META_KEY;
use const WordCamp\Budgets\Privacy\BUDGET_FILE_DELETE_META_KEY;
use const WordCamp\Budgets\Privacy\BUDGET_FILE_META_KEY;

defined( 'WPINC' ) || die();

// phpcs:disable Universal.Files.SeparateFunctionsFromOO -- the command and the work it does are one unit here, so that removing the migration is removing one file.

/**
 * The shape `wp_unique_filename()` gives a payment file: name, hyphen, 16 random characters. Bracketed dot
 * because MySQL eats the backslash in `\.` before the regexp sees it.
 */
const FILE_SUFFIX_PATTERN = '-([A-Za-z0-9]{16})[.][A-Za-z0-9]+$';

/**
 * When the suffix started being applied (r7745). Before that a 16-character last segment is a word.
 */
const FILE_SUFFIX_SINCE = '2018-10-18';

/**
 * The meta key naming an attachment as a sponsorship agreement, which has its own migration.
 */
const SPONSOR_AGREEMENT_META_KEY = '_wcorg_sponsor_agreement';


/**
 * The attachments on one site whose parent is a budget request, of any status (`auto-draft` included).
 *
 * Builds the table names from the blog ID instead of switching sites, so `scan()` can walk the network in one
 * process.
 *
 * @param int|null $blog_id Defaults to the current site.
 *
 * @return object[] `ID`, `file`, `post_date`, `post_status` and `marked` per attachment.
 */
function get_attached_candidates( $blog_id = null ) {
	global $wpdb;

	$prefix            = $wpdb->get_blog_prefix( $blog_id );
	$post_types        = get_budget_request_post_types();
	$type_placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the placeholders are a generated run of `%s`, and the prefix comes from `get_blog_prefix()`.
	$candidates = $wpdb->get_results( $wpdb->prepare(
		"SELECT file.ID, file.post_date, file.post_status, name.meta_value AS file, marker.meta_id AS marked
		FROM {$prefix}posts AS file
		INNER JOIN {$prefix}posts AS budget_request
			ON budget_request.ID = file.post_parent
			AND budget_request.post_type IN ( $type_placeholders )
		LEFT JOIN {$prefix}postmeta AS name
			ON name.post_id = file.ID AND name.meta_key = '_wp_attached_file'
		LEFT JOIN {$prefix}postmeta AS marker
			ON marker.post_id = file.ID AND marker.meta_key = %s
		WHERE file.post_type = 'attachment'
		ORDER BY file.ID",
		array_merge( $post_types, array( BUDGET_FILE_META_KEY ) )
	) );
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

	return $candidates;
}

/**
 * The unattached files on one site whose name says they were payment files.
 *
 * With the request gone the random suffix is the only handle. Sponsorship agreements share the shape and have
 * their own migration, so they are skipped.
 *
 * @param int|null $blog_id Defaults to the current site.
 *
 * @return object[] `ID`, `file`, `post_date`, `post_status` and `marked` per attachment.
 */
function get_unattached_candidates( $blog_id = null ) {
	global $wpdb;

	$prefix = $wpdb->get_blog_prefix( $blog_id );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the prefix comes from `get_blog_prefix()`.
	$candidates = $wpdb->get_results( $wpdb->prepare(
		"SELECT file.ID, file.post_date, file.post_status, name.meta_value AS file, marker.meta_id AS marked
		FROM {$prefix}posts AS file
		INNER JOIN {$prefix}postmeta AS name
			ON name.post_id = file.ID AND name.meta_key = '_wp_attached_file'
		LEFT JOIN {$prefix}postmeta AS agreement
			ON agreement.post_id = file.ID AND agreement.meta_key = %s
		LEFT JOIN {$prefix}postmeta AS marker
			ON marker.post_id = file.ID AND marker.meta_key = %s
		WHERE file.post_type = 'attachment'
		AND file.post_parent = 0
		AND file.post_date >= %s
		AND name.meta_value REGEXP %s
		AND agreement.meta_id IS NULL
		ORDER BY file.ID",
		SPONSOR_AGREEMENT_META_KEY,
		BUDGET_FILE_META_KEY,
		FILE_SUFFIX_SINCE,
		FILE_SUFFIX_PATTERN
	) );
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return array_values( array_filter( $candidates, fn( $candidate ) => has_random_suffix( $candidate->file ) ) );
}

/**
 * Whether a file name ends in a random suffix rather than a 16-letter word such as `origineelformaat`.
 *
 * A real suffix mixes cases; requiring both drops 0.03% of them, requiring a digit too would drop 6%.
 *
 * @param string $file The value of `_wp_attached_file`.
 *
 * @return bool
 */
function has_random_suffix( $file ) {
	if ( ! preg_match( '/' . FILE_SUFFIX_PATTERN . '/', (string) $file, $matches ) ) {
		return false;
	}

	return 1 === preg_match( '/[A-Z]/', $matches[1] ) && 1 === preg_match( '/[a-z]/', $matches[1] );
}

/**
 * Give the files on the current site the marker and the status `privacy.php` would have given them. Safe to
 * re-run: a marked file keeps the marker and status it has.
 *
 * @param int[] $copy_ids IDs that `scan` matched to a file still on a request on another site. Each one gets
 *                        `BUDGET_FILE_DELETE_META_KEY` on every run it is named in, marked or not, for a later
 *                        pass to act on.
 * @param bool  $dry_run  Report what would change, without changing it.
 *
 * @return array[] One report row per file, in `Command::REPORT_COLUMNS` order.
 */
function backfill_files( $copy_ids = array(), $dry_run = false ) {
	$copy_ids = array_map( 'absint', $copy_ids );
	$rows     = array();

	$arms = array(
		'attached'   => get_attached_candidates(),
		'unattached' => get_unattached_candidates(),
	);

	foreach ( $arms as $arm => $candidates ) {
		foreach ( $candidates as $candidate ) {
			$attachment_id = (int) $candidate->ID;

			// Only an unattached file can be a copy: an attached one is the original a copy is matched to.
			$is_copy = 'unattached' === $arm && in_array( $attachment_id, $copy_ids, true );

			if ( ! $dry_run ) {
				if ( ! $candidate->marked ) {
					mark_budget_file( $attachment_id );
					update_post_meta( $attachment_id, BUDGET_FILE_BACKFILLED_META_KEY, 1 );
				}

				// Outside the branch above, so naming a file that an earlier run marked still records it.
				if ( $is_copy ) {
					update_post_meta( $attachment_id, BUDGET_FILE_DELETE_META_KEY, 1 );
				}
			}

			$rows[] = array(
				'Attachment' => $attachment_id,
				'File'       => wp_basename( (string) $candidate->file ),
				'Was'        => $candidate->post_status,
				'Marked'     => $candidate->marked ? 'skipped' : 'yes',
				'Copy'       => $is_copy ? 'yes' : 'no',
			);
		}
	}

	return $rows;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * WordCamp.org: bring existing payment request files into line with `privacy.php`.
 */
class Command extends WP_CLI_Command {
	/**
	 * The columns of the `backfill` report.
	 */
	const REPORT_COLUMNS = array( 'Attachment', 'File', 'Was', 'Marked', 'Copy' );

	/**
	 * Report which sites have payment files left to migrate. Writes nothing.
	 *
	 * Reads each site's tables by prefix, never `switch_to_blog()`, because enumerating sites the ordinary way
	 * runs the production sandbox out of memory. Prints a ready-to-run `backfill` line per site, with the
	 * `--copies` list only a network-wide view can work out: an unattached file is a copy when the same
	 * `post_date` and `_wp_attached_file` are still on a request on another site.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wc-budget-files scan
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function scan( $args, $assoc_args ) {
		global $wpdb;

		$sites    = $wpdb->get_results( "SELECT blog_id, domain, path FROM {$wpdb->blogs} WHERE deleted = 0 AND archived = 0 ORDER BY blog_id" );
		$reports  = array();
		$attached = array();
		$unusable = array();

		// A site whose tables have gone is a broken row, not a reason to stop part-way through a network.
		$was_suppressing = $wpdb->suppress_errors( true );

		foreach ( $sites as $site ) {
			$attached_candidates   = get_attached_candidates( $site->blog_id );
			$attached_error        = $wpdb->last_error;
			$unattached_candidates = get_unattached_candidates( $site->blog_id );

			// `get_results()` returns an empty array on failure too, and a broken site must not read as clean.
			if ( $attached_error || $wpdb->last_error ) {
				$unusable[]       = $site->domain . $site->path;
				$wpdb->last_error = '';

				continue;
			}

			foreach ( $attached_candidates as $candidate ) {
				$attached[ $this->file_key( $candidate ) ][] = (int) $site->blog_id;
			}

			// Only what a `backfill` would still change, so a site drops out of the report once it has been done.
			$is_unmarked = fn( $candidate ) => ! $candidate->marked;
			$unmarked    = array_filter( $attached_candidates, $is_unmarked );
			$unattached  = array_values( array_filter( $unattached_candidates, $is_unmarked ) );

			if ( ! $unmarked && ! $unattached ) {
				continue;
			}

			$reports[] = array(
				'blog_id'    => (int) $site->blog_id,
				'url'        => $site->domain . $site->path,
				'attached'   => count( $unmarked ),
				'unattached' => $unattached,
			);
		}

		$wpdb->suppress_errors( $was_suppressing );

		$this->report_scan( $reports, $attached );

		if ( $unusable ) {
			WP_CLI::warning( sprintf(
				'%d sites could not be read, and are not in the report above: %s.',
				count( $unusable ),
				implode( ', ', array_slice( $unusable, 0, 10 ) ) . ( count( $unusable ) > 10 ? ', ...' : '' )
			) );
		}

		WP_CLI::success( sprintf( '%d of %d sites have payment files to migrate.', count( $reports ), count( $sites ) ) );
	}

	/**
	 * Migrate the payment files on one site: the marker and the `private` status a file uploaded today gets.
	 *
	 * Acts on the current site only, so every run but one needs `--url`. Safe to re-run.
	 *
	 * ## OPTIONS
	 *
	 * [--copies=<ids>]
	 * : Comma separated attachment IDs, from `scan`, that are copies of a file still on a request on another
	 * site. Each is recorded for a later pass; nothing here removes a file.
	 *
	 * [--dry-run]
	 * : Report what would change, without changing it.
	 *
	 * [--yes-main-site]
	 * : Run even though this is the network's main site. Required there, because a forgotten `--url` lands
	 * on it without saying so.
	 *
	 * ## EXAMPLES
	 *
	 *     wp --url=seattle.wordcamp.org/2023 wc-budget-files backfill --dry-run
	 *     wp --url=seattle.wordcamp.org/2023 wc-budget-files backfill --copies=41,57
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function backfill( $args, $assoc_args ) {
		if ( ! function_exists( 'WordCamp\Budgets\Privacy\mark_budget_file' ) ) {
			WP_CLI::error( sprintf( 'wordcamp-payments is not active on %s.', home_url() ) );
		}

		if ( is_main_site() && ! isset( $assoc_args['yes-main-site'] ) ) {
			WP_CLI::error( sprintf(
				'%s is the network\'s main site. Pass --url to name a camp, or --yes-main-site if it really is this one.',
				home_url()
			) );
		}

		$dry_run  = isset( $assoc_args['dry-run'] );
		$copy_ids = array_filter( explode( ',', (string) ( $assoc_args['copies'] ?? '' ) ) );
		$rows     = backfill_files( $copy_ids, $dry_run );

		if ( ! $rows ) {
			WP_CLI::success( sprintf( 'No payment files left to migrate on %s.', home_url() ) );

			return;
		}

		WP_CLI::line();
		WP_CLI::line( home_url() );

		format_items( 'table', $rows, self::REPORT_COLUMNS );

		WP_CLI::line();

		$migrated = count( array_filter( $rows, fn( $row ) => 'yes' === $row['Marked'] ) );
		$copies   = count( array_filter( $rows, fn( $row ) => 'yes' === $row['Copy'] ) );

		WP_CLI::success( sprintf(
			'%s: %d of %d files %s, %d recorded as copies, %d already marked.',
			home_url(),
			$migrated,
			count( $rows ),
			$dry_run ? 'would be migrated' : 'migrated',
			$copies,
			count( $rows ) - $migrated
		) );
	}

	/**
	 * Print the scan once every site has been read, since a copy's original can sit on a later blog ID.
	 *
	 * @param array[] $reports  One entry per site with work outstanding.
	 * @param array   $attached Blog IDs per `file_key()` of every file still on a request.
	 */
	protected function report_scan( $reports, $attached ) {
		$totals = array(
			'attached'   => 0,
			'unattached' => 0,
			'copies'     => 0,
		);

		foreach ( $reports as $report ) {
			$copies = array();

			foreach ( $report['unattached'] as $candidate ) {
				$elsewhere = array_diff( $attached[ $this->file_key( $candidate ) ] ?? array(), array( $report['blog_id'] ) );

				if ( $elsewhere ) {
					$copies[] = (int) $candidate->ID;
				}
			}

			WP_CLI::line();
			WP_CLI::line( $report['url'] );
			WP_CLI::line( sprintf( '  attached %d, unattached %d', $report['attached'], count( $report['unattached'] ) ) );

			if ( $copies ) {
				WP_CLI::line( sprintf( '  copies %s', implode( ',', $copies ) ) );
			}

			WP_CLI::line( sprintf(
				'  wp --url=%s wc-budget-files backfill%s',
				$report['url'],
				$copies ? ' --copies=' . implode( ',', $copies ) : ''
			) );

			$totals['attached']   += $report['attached'];
			$totals['unattached'] += count( $report['unattached'] );
			$totals['copies']     += count( $copies );
		}

		WP_CLI::line();
		WP_CLI::line( sprintf(
			'totals: attached %d, unattached %d, of which copies %d',
			$totals['attached'],
			$totals['unattached'],
			$totals['copies']
		) );
	}

	/**
	 * What makes two rows on different sites the same file: a cloned site keeps both values, and nothing else
	 * survives the copy.
	 *
	 * @param object $candidate A row from one of the candidate queries.
	 *
	 * @return string
	 */
	protected function file_key( $candidate ) {
		return $candidate->post_date . ' ' . $candidate->file;
	}
}

WP_CLI::add_command( 'wc-budget-files', __NAMESPACE__ . '\Command' );
