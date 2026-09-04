<?php

/**
 * A one-time migration, to be deleted once it has been run across the network.
 *
 * `sponsor-agreements.php` handles agreements from the moment they're attached. Files attached before it
 * existed keep the status they already had, because writing the same meta value back fires no hook. This
 * gives them that status, and then has no further purpose.
 *
 * Self-contained on purpose: everything here goes when the file does, apart from the two lines that load it
 * in `bootstrap.php`. `make_agreement_private()` outlives it, in `sponsor-agreements.php`.
 */

namespace WordCamp\Sponsor_Agreements\Backfill;

use WP_CLI, WP_CLI_Command;

use function WP_CLI\Utils\format_items;

use function WordCamp\Sponsor_Agreements\make_agreement_private;

defined( 'WPINC' ) || die();

/**
 * Recorded on every attachment this migration gives the `private` status.
 *
 * `AGREEMENT_MARKER_META_KEY` says an attachment is an agreement; it doesn't say which ones were uploaded
 * before `obscure_sponsor_file_names()` existed and so still carry the name they were given. Nothing else
 * distinguishes them once the status is set, and this is the only moment the answer is known.
 */
const BACKFILLED_META_KEY = '_wcorg_sponsor_agreement_backfilled';

// phpcs:disable Universal.Files.SeparateFunctionsFromOO -- the command and the work it does are one unit here, so that removing the migration is removing one file.


/**
 * The agreement attachments on one site that are still `inherit`.
 *
 * Found through the sponsor rather than through the marker meta, because the files this is for were
 * attached before anything marked them.
 *
 * Takes a blog ID and builds the table names itself rather than switching, so that `scan()` can ask this of
 * every site on the network from a single process. `get_blog_prefix()` is string handling -- it loads no
 * site, runs no query, and fires no `switch_blog`.
 *
 * @param int|null $blog_id Defaults to the current site.
 *
 * @return int[]
 */
function get_public_agreement_ids( $blog_id = null ) {
	global $wpdb;

	$prefix            = $wpdb->get_blog_prefix( $blog_id );
	$meta_keys         = \WordCamp\Sponsor_Agreements\get_agreement_meta_keys();
	$post_types        = \WordCamp\Sponsor_Agreements\get_sponsor_post_types();
	$key_placeholders  = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );
	$type_placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

	// The placeholders are generated runs of `%s`, and the prefix comes from `get_blog_prefix()`.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$agreement_ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT DISTINCT agreement.ID
		FROM {$prefix}posts AS agreement
		INNER JOIN {$prefix}postmeta AS sponsor_meta
			ON CAST( sponsor_meta.meta_value AS UNSIGNED ) = agreement.ID
		INNER JOIN {$prefix}posts AS sponsor
			ON sponsor.ID = sponsor_meta.post_id
		WHERE sponsor_meta.meta_key IN ( $key_placeholders )
		AND sponsor.post_type IN ( $type_placeholders )
		AND agreement.post_type = 'attachment'
		AND agreement.post_status = 'inherit'",
		array_merge( $meta_keys, $post_types )
	) );
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

	return array_map( 'absint', $agreement_ids );
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * WordCamp.org: bring existing sponsorship agreements into line with `sponsor-agreements.php`.
 */
class Command extends WP_CLI_Command {
	/**
	 * The columns of the `backfill` report.
	 *
	 * Named in one place so that the rows, the rendered report and the empty-set report can't drift apart.
	 */
	const REPORT_COLUMNS = array( 'Attachment', 'File', 'Private' );

	/**
	 * Report which sites have agreements left to migrate.
	 *
	 * Reads the network's tables directly, one site at a time, so it never loads a site, never calls
	 * `switch_to_blog()`, and holds nothing between rows. That matters on the production network, where
	 * enumerating sites the ordinary way runs the process out of memory -- and it means `backfill` has to
	 * be run only on the handful of sites that turn out to need it, rather than on all of them.
	 *
	 * Prints one `<url>` per site with work outstanding, so the output can be piped straight into a loop.
	 *
	 * ## OPTIONS
	 *
	 * [--verbose]
	 * : Also report the number of agreements found on each site, on STDERR.
	 *
	 * ## EXAMPLES
	 *
	 *     # See which sites need attention.
	 *     wp wc-sponsor-agreements scan --verbose
	 *
	 *     # Migrate only those sites, one process each.
	 *     wp wc-sponsor-agreements scan | while read -r site; do
	 *         wp --url="$site" wc-sponsor-agreements backfill
	 *     done
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function scan( $args, $assoc_args ) {
		global $wpdb;

		$verbose  = isset( $assoc_args['verbose'] );
		$sites    = $wpdb->get_results( "SELECT blog_id, domain, path FROM {$wpdb->blogs} ORDER BY blog_id" );
		$found    = 0;
		$unusable = array();

		// A site whose tables have gone is a broken row, not a reason to stop part-way through a network.
		$was_suppressing = $wpdb->suppress_errors( true );

		foreach ( $sites as $site ) {
			$agreement_ids = get_public_agreement_ids( $site->blog_id );

			/*
			 * `get_col()` answers an empty array whether the site has nothing or the query failed, so a
			 * site whose tables are missing would otherwise be counted as clean and never reach the pipe.
			 */
			if ( $wpdb->last_error ) {
				$unusable[]       = $site->domain . $site->path;
				$wpdb->last_error = '';

				continue;
			}

			if ( ! $agreement_ids ) {
				continue;
			}

			++$found;

			WP_CLI::line( $site->domain . $site->path );

			if ( $verbose ) {
				WP_CLI::warning( sprintf(
					'%s%s (blog %d): %d agreements.',
					$site->domain,
					$site->path,
					$site->blog_id,
					count( $agreement_ids )
				) );
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
			WP_CLI::warning( sprintf( '%d of %d sites have agreements to migrate.', $found, count( $sites ) ) );
		}
	}

	/**
	 * Migrate the agreements on one site.
	 *
	 * Gives them the `private` status that new agreements get as they're attached. Nothing links to an
	 * agreement by URL -- both plugins hold an attachment ID and resolve it through
	 * `wp_get_attachment_url()` -- so organizers keep the access they have.
	 *
	 * Safe to re-run: a site with nothing outstanding does one query and stops.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change, without changing it.
	 *
	 * [--format=<format>]
	 * : Render the report in this format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp --url=seattle.wordcamp.org/2023 wc-sponsor-agreements backfill --dry-run
	 *     wp --url=seattle.wordcamp.org/2023 wc-sponsor-agreements backfill
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function backfill( $args, $assoc_args ) {
		$dry_run       = isset( $assoc_args['dry-run'] );
		$format        = $assoc_args['format'] ?? 'table';
		$agreement_ids = get_public_agreement_ids();
		$results       = array();
		$unfinished    = array();

		foreach ( $agreement_ids as $agreement_id ) {
			// `get_public_agreement_ids()` only returns `inherit` rows, so a `false` here is a failed write.
			$migrated = $dry_run ? true : make_agreement_private( $agreement_id );

			if ( ! $migrated ) {
				$unfinished[] = $agreement_id;
			} elseif ( ! $dry_run ) {
				update_post_meta( $agreement_id, BACKFILLED_META_KEY, 1 );
			}

			$results[] = array(
				'Attachment' => $agreement_id,
				'File'       => wp_basename( (string) get_attached_file( $agreement_id ) ),
				'Private'    => $migrated ? 'yes' : 'no',
			);
		}

		if ( ! $results ) {
			// A machine-readable run still has to answer with something its caller can parse.
			if ( 'table' !== $format ) {
				format_items( $format, array(), self::REPORT_COLUMNS );
			}

			$this->summarize( 'success', sprintf( 'No sponsor agreements left to migrate on %s.', home_url() ), $format );

			return;
		}

		// Blank lines frame the table for a person, and corrupt every other format.
		if ( 'table' === $format ) {
			WP_CLI::line();
			WP_CLI::line( home_url() );
		}

		format_items( $format, $results, self::REPORT_COLUMNS );

		if ( 'table' === $format ) {
			WP_CLI::line();
		}

		if ( $dry_run ) {
			$message = sprintf( '%d agreements would be migrated on %s. Run without --dry-run to apply.', count( $results ), home_url() );

			$this->summarize( 'success', $message, $format );
		} elseif ( $unfinished ) {
			$message = sprintf(
				'%d of %d agreements on %s could not be given the private status.',
				count( $unfinished ),
				count( $results ),
				home_url()
			);

			$this->summarize( 'warning', $message, $format );
		} else {
			$this->summarize( 'success', sprintf( '%d agreements processed on %s.', count( $results ), home_url() ), $format );
		}
	}

	/**
	 * Report on the run as a whole, without writing over the report itself.
	 *
	 * `WP_CLI::success()` goes to STDOUT, which is where a machine-readable format has just been written,
	 * so anything piped to `jq` would find a sentence after the array. Warnings are already on STDERR.
	 *
	 * @param string $level  `success` or `warning`.
	 * @param string $message
	 * @param string $format The format the report was rendered in.
	 */
	protected function summarize( $level, $message, $format ) {
		if ( 'warning' === $level ) {
			WP_CLI::warning( $message );

			return;
		}

		if ( 'table' === $format ) {
			WP_CLI::success( $message );

			return;
		}

		// `WP_CLI::success()` would have gone through the logger, which is what `--quiet` silences.
		if ( WP_CLI::get_config( 'quiet' ) ) {
			return;
		}

		fwrite( STDERR, 'Success: ' . $message . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- writing to the process's own STDERR.
	}
}

WP_CLI::add_command( 'wc-sponsor-agreements', __NAMESPACE__ . '\Command' );
