<?php

/**
 * A one-time migration, to be deleted once it has been run across the groups network.
 *
 * Check-ins used to live in a `wporg_groups_checked_in` comment meta on the RSVP. They are now GatherPress's
 * own `Check_In` flag, so that the wp-admin RSVPs screen and the event page see the same thing (#2213). This
 * moves the check-ins recorded before that onto the flag, and removes the old meta.
 *
 * The time of each check-in is not carried over: the flag has no time, and nothing ever read it.
 *
 * Self-contained on purpose: everything here goes when the file does, apart from the two lines that load it
 * in `bootstrap.php`.
 */

namespace WordCamp\Groups\Check_Ins\Backfill;

use GatherPress\Core\Rsvp\Flag\Check_In;
use WP_CLI, WP_CLI_Command;

use function WP_CLI\Utils\format_items;

defined( 'WPINC' ) || die();

// phpcs:disable Universal.Files.SeparateFunctionsFromOO -- the command and the work it does are one unit here, so that removing the migration is removing one file.

const LEGACY_META_KEY = 'wporg_groups_checked_in';

/**
 * The RSVPs on one site that still carry the old check-in meta.
 *
 * Takes a blog ID and builds the table names itself rather than switching, so that `scan()` can ask this of
 * every site on the network from a single process.
 *
 * @param int|null $blog_id Defaults to the current site.
 *
 * @return int[]
 */
function get_legacy_check_in_ids( $blog_id = null ) {
	global $wpdb;

	$prefix = $wpdb->get_blog_prefix( $blog_id );

	// The prefix comes from `get_blog_prefix()`.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$comment_ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT DISTINCT comment_id FROM {$prefix}commentmeta WHERE meta_key = %s ORDER BY comment_id",
		LEGACY_META_KEY
	) );
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return array_map( 'absint', $comment_ids );
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * WordCamp.org: move Groups check-ins onto GatherPress's check-in flag.
 */
class Command extends WP_CLI_Command {
	/**
	 * Report which sites have check-ins left to migrate.
	 *
	 * Reads each site's `commentmeta` table directly, so it never loads a site or calls `switch_to_blog()`.
	 * Prints one `<url>` per site with work outstanding, so the output can be piped straight into a loop.
	 *
	 * ## OPTIONS
	 *
	 * [--verbose]
	 * : Also report the number of check-ins found on each site, on STDERR.
	 *
	 * ## EXAMPLES
	 *
	 *     # See which sites need it.
	 *     wp --url=events.wordpress.org wc-groups-check-ins scan --verbose
	 *
	 *     # Migrate only those sites, one process each.
	 *     wp --url=events.wordpress.org wc-groups-check-ins scan | while read -r site; do
	 *         wp --url="$site" wc-groups-check-ins migrate
	 *     done
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function scan( $args, $assoc_args ) {
		global $wpdb;

		$verbose  = isset( $assoc_args['verbose'] );
		$sites    = $wpdb->get_results( $wpdb->prepare(
			"SELECT blog_id, domain, path FROM {$wpdb->blogs} WHERE site_id = %d ORDER BY blog_id",
			get_current_network_id()
		) );
		$found    = 0;
		$unusable = array();

		// A site whose tables have gone is a broken row, not a reason to stop part-way through a network.
		$was_suppressing = $wpdb->suppress_errors( true );

		foreach ( $sites as $site ) {
			$comment_ids = get_legacy_check_in_ids( $site->blog_id );

			// `get_col()` answers an empty array whether the site has nothing or the query failed.
			if ( $wpdb->last_error ) {
				$unusable[]       = $site->domain . $site->path;
				$wpdb->last_error = '';

				continue;
			}

			if ( ! $comment_ids ) {
				continue;
			}

			++$found;

			WP_CLI::line( $site->domain . $site->path );

			if ( $verbose ) {
				WP_CLI::warning( sprintf( '%s%s (blog %d): %d check-ins.', $site->domain, $site->path, $site->blog_id, count( $comment_ids ) ) );
			}
		}

		$wpdb->suppress_errors( $was_suppressing );

		if ( $unusable ) {
			WP_CLI::warning( sprintf(
				'%d sites could not be read, and are not in the list above: %s.',
				count( $unusable ),
				implode( ', ', array_slice( $unusable, 0, 10 ) ) . ( count( $unusable ) > 10 ? ', ...' : '' )
			) );
		}

		if ( $verbose ) {
			WP_CLI::warning( sprintf( '%d of %d sites have check-ins to migrate.', $found, count( $sites ) ) );
		}
	}

	/**
	 * Migrate the check-ins on one site.
	 *
	 * Flags each RSVP as checked in, then removes the old meta. Adding the flag fires
	 * `gatherpress_rsvp_flag_added`, which keeps the event's record of which dates use check-in in step.
	 * An RSVP whose flag can't be stored keeps its meta, so a re-run picks it up again.
	 *
	 * Safe to re-run: a site with nothing outstanding does one query and stops.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change, without changing it.
	 *
	 * ## EXAMPLES
	 *
	 *     wp --url=events.wordpress.org/group/narnia/ wc-groups-check-ins migrate --dry-run
	 *     wp --url=events.wordpress.org/group/narnia/ wc-groups-check-ins migrate
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function migrate( $args, $assoc_args ) {
		if ( ! class_exists( Check_In::class ) ) {
			WP_CLI::error( sprintf( 'GatherPress 0.36.0 or later is not active on %s.', home_url() ) );
		}

		$dry_run     = isset( $assoc_args['dry-run'] );
		$comment_ids = get_legacy_check_in_ids();
		$results     = array();
		$unfinished  = 0;

		if ( ! $comment_ids ) {
			WP_CLI::success( sprintf( 'No check-ins left to migrate on %s.', home_url() ) );

			return;
		}

		foreach ( $comment_ids as $comment_id ) {
			$comment  = get_comment( $comment_id );
			$migrated = $dry_run || ( new Check_In( $comment_id ) )->add();

			if ( $migrated && ! $dry_run ) {
				delete_comment_meta( $comment_id, LEGACY_META_KEY );
			} elseif ( ! $migrated ) {
				++$unfinished;
			}

			$results[] = array(
				'RSVP'     => $comment_id,
				'Event'    => $comment ? (int) $comment->comment_post_ID : '',
				'Migrated' => $migrated ? 'yes' : 'no',
			);
		}

		format_items( 'table', $results, array( 'RSVP', 'Event', 'Migrated' ) );

		if ( $dry_run ) {
			WP_CLI::success( sprintf( '%d check-ins would be migrated on %s. Run without --dry-run to apply.', count( $results ), home_url() ) );
		} elseif ( $unfinished ) {
			// Usually a comment that is no longer an RSVP: `Check_In` refuses anything else.
			WP_CLI::warning( sprintf( '%d of %d check-ins on %s could not be migrated, and keep the old meta.', $unfinished, count( $results ), home_url() ) );
		} else {
			WP_CLI::success( sprintf( '%d check-ins migrated on %s.', count( $results ), home_url() ) );
		}
	}
}

WP_CLI::add_command( 'wc-groups-check-ins', __NAMESPACE__ . '\Command' );
