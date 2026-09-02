<?php

/**
 * A one-time migration, to be deleted once it has been run across the network.
 *
 * `sponsor-agreements.php` handles agreements from the moment they're attached. Files attached before it
 * existed keep the status and the name they already had, because writing the same meta value back fires no
 * hook. This brings those into line, and then has no further purpose.
 *
 * Self-contained on purpose: everything here goes when the file does, apart from the two lines that load it
 * in `bootstrap.php`. The pieces that outlive the migration -- `make_agreement_private()` and
 * `add_csprn_to_filename()` -- stay in `sponsor-agreements.php` and are called from here.
 */

namespace WordCamp\Sponsor_Agreements\Backfill;

use WP_CLI, WP_CLI_Command;
use cli\Table;

use function WordCamp\Sponsor_Agreements\add_csprn_to_filename;
use function WordCamp\Sponsor_Agreements\make_agreement_private;

defined( 'WPINC' ) || die();

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

/**
 * Rename an agreement that's already on disk, so that it matches what new uploads get.
 *
 * Nothing links to an agreement by URL -- both plugins hold an attachment ID and resolve it through
 * `wp_get_attachment_url()` -- so the new name reaches every reader.
 *
 * The generated sizes move with it. They sit beside the original under names derived from it.
 *
 * Only ever called for the site the process was started on, because `ms_upload_constants()` defines
 * `UPLOADS` for whichever site that was -- see `backfill()`.
 *
 * The `guid` is deliberately left alone. It's an identifier rather than the URL anything is served from,
 * and Core's rule is that it doesn't change once a post has one.
 *
 * @param int $attachment_id
 *
 * @return bool Whether the file was renamed.
 */
function rename_agreement_file( $attachment_id ) {
	$old_path = get_attached_file( $attachment_id );

	if ( ! $old_path || ! is_file( $old_path ) ) {
		return false;
	}

	$directory = dirname( $old_path );
	$old_name  = wp_basename( $old_path );
	$extension = pathinfo( $old_name, PATHINFO_EXTENSION );
	$extension = $extension ? '.' . $extension : '';
	$new_name  = wp_unique_filename( $directory, add_csprn_to_filename( $old_name, $extension ) );

	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- WP_Filesystem needs credentials this can't ask for, and a failure is reported rather than fatal.
	if ( ! @rename( $old_path, $directory . '/' . $new_name ) ) {
		return false;
	}

	$old_base = substr( $old_name, 0, strlen( $old_name ) - strlen( $extension ) );
	$new_base = substr( $new_name, 0, strlen( $new_name ) - strlen( $extension ) );
	$metadata = wp_get_attachment_metadata( $attachment_id );

	if ( is_array( $metadata ) ) {
		if ( ! empty( $metadata['file'] ) ) {
			$metadata['file'] = dirname( $metadata['file'] ) . '/' . $new_name;
		}

		foreach ( $metadata['sizes'] ?? array() as $size => $details ) {
			if ( empty( $details['file'] ) || ! str_starts_with( $details['file'], $old_base ) ) {
				continue;
			}

			$new_size_name = $new_base . substr( $details['file'], strlen( $old_base ) );

			if ( is_file( $directory . '/' . $details['file'] ) ) {
				// A size that won't move is left where it is, so the metadata keeps naming the real file.
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- as above.
				if ( ! @rename( $directory . '/' . $details['file'], $directory . '/' . $new_size_name ) ) {
					continue;
				}
			}

			$metadata['sizes'][ $size ]['file'] = $new_size_name;
		}

		wp_update_attachment_metadata( $attachment_id, $metadata );
	}

	update_attached_file( $attachment_id, $directory . '/' . $new_name );

	return true;
}


if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * WordCamp.org: bring existing sponsorship agreements into line with `sponsor-agreements.php`.
 */
class Command extends WP_CLI_Command {
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

		$verbose = isset( $assoc_args['verbose'] );
		$sites   = $wpdb->get_results( "SELECT blog_id, domain, path FROM {$wpdb->blogs} ORDER BY blog_id" );
		$found   = 0;

		// A site whose tables have gone is a broken row, not a reason to stop part-way through a network.
		$wpdb->suppress_errors( true );

		foreach ( $sites as $site ) {
			$agreement_ids = get_public_agreement_ids( $site->blog_id );

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

		$wpdb->suppress_errors( false );

		if ( $verbose ) {
			WP_CLI::warning( sprintf( '%d of %d sites have agreements to migrate.', $found, count( $sites ) ) );
		}
	}

	/**
	 * Migrate the agreements on one site.
	 *
	 * Gives them the `private` status and the file name that new agreements get as they're attached.
	 * Nothing links to an agreement by URL -- both plugins hold an attachment ID and resolve it through
	 * `wp_get_attachment_url()` -- so organizers keep the access they have.
	 *
	 * One site per run, and it has to be: `ms_upload_constants()` defines `UPLOADS` for whichever site was
	 * current at bootstrap, so a `switch_to_blog()` loop would resolve every other site's files against
	 * the wrong directory and silently rename nothing. Use `scan` to find the sites that need this.
	 *
	 * Safe to re-run: a site with nothing outstanding does one query and stops.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change, without changing it.
	 *
	 * [--skip-rename]
	 * : Set the status, but leave the files under the names they have.
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
		$skip_rename   = isset( $assoc_args['skip-rename'] );
		$agreement_ids = get_public_agreement_ids();
		$results       = array();

		foreach ( $agreement_ids as $agreement_id ) {
			$file = wp_basename( (string) get_attached_file( $agreement_id ) );

			if ( $dry_run ) {
				$migrated = true;
				$renamed  = ! $skip_rename;
			} else {
				$migrated = make_agreement_private( $agreement_id );
				$renamed  = $skip_rename ? false : rename_agreement_file( $agreement_id );
			}

			$results[] = array(
				'Attachment' => $agreement_id,
				'File'       => $file,
				'Private'    => $migrated ? 'yes' : 'no',
				'Renamed'    => $renamed ? 'yes' : 'no',
			);
		}

		if ( ! $results ) {
			WP_CLI::success( 'No sponsor agreements left to migrate.' );

			return;
		}

		WP_CLI::line();

		$table = new Table();
		$table->setHeaders( array_keys( $results[0] ) );
		$table->setRows( $results );
		$table->display();

		WP_CLI::line();

		$unfinished = wp_list_filter( $results, array( 'Renamed' => 'no' ) );

		if ( $dry_run ) {
			WP_CLI::success( sprintf( '%d agreements would be migrated. Run without --dry-run to apply.', count( $results ) ) );
		} elseif ( $unfinished && ! $skip_rename ) {
			WP_CLI::warning( sprintf(
				'%d of %d agreements kept the file name they had.',
				count( $unfinished ),
				count( $results )
			) );
		} else {
			WP_CLI::success( sprintf( '%d agreements processed.', count( $results ) ) );
		}
	}
}

WP_CLI::add_command( 'wc-sponsor-agreements', __NAMESPACE__ . '\Command' );
