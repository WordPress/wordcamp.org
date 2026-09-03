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

use function WP_CLI\Utils\format_items;

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
 * Every file Core derives from the upload moves with it, under one new base name:
 *
 * - the generated sizes, which for a PDF are renderings of the first page and for an image are copies of
 *   it at up to 2048px;
 * - the full-size original, when Core scaled the upload down. `_wp_attached_file` then points at the
 *   `-scaled` copy while `original_image` holds the name the sizes are derived from, so that -- not the
 *   attached file -- is what the new base is built from.
 *
 * Only ever called for the site the process was started on, because `ms_upload_constants()` defines
 * `UPLOADS` for whichever site that was -- see `backfill()`.
 *
 * The `guid` is deliberately left alone. It's an identifier rather than the URL anything is served from,
 * and Core's rule is that it doesn't change once a post has one.
 *
 * @param int $attachment_id
 *
 * @return array {
 *     What happened on disk. The metadata always names whatever each file ended up being called.
 *
 *     @type int $renamed     Files moved.
 *     @type int $left_behind Files still under the name they had.
 * }
 */
function rename_agreement_file( $attachment_id ) {
	$outcome       = array(
		'renamed'     => 0,
		'left_behind' => 0,
	);
	$attached_path = get_attached_file( $attachment_id );

	if ( ! $attached_path || ! is_file( $attached_path ) ) {
		return $outcome;
	}

	$directory = dirname( $attached_path );
	$metadata  = wp_get_attachment_metadata( $attachment_id );
	$metadata  = is_array( $metadata ) ? $metadata : array();

	$old_base     = empty( $metadata['original_image'] ) ? wp_basename( $attached_path ) : $metadata['original_image'];
	$extension    = pathinfo( $old_base, PATHINFO_EXTENSION );
	$extension    = $extension ? '.' . $extension : '';
	$new_base     = wp_unique_filename( $directory, add_csprn_to_filename( $old_base, $extension ) );
	$old_base     = substr( $old_base, 0, strlen( $old_base ) - strlen( $extension ) );
	$new_base     = substr( $new_base, 0, strlen( $new_base ) - strlen( $extension ) );
	$already_done = array();

	/**
	 * Move one of the attachment's files, and answer to what it's now called.
	 *
	 * Two sizes can name the same file -- a theme registering a size Core already generates -- so the
	 * second time one comes round it has already been dealt with. The cache is what keeps its metadata in
	 * step with the first one's, and what keeps one file from being counted twice.
	 *
	 * @param string $name
	 *
	 * @return string
	 */
	$move = function ( $name ) use ( $directory, $old_base, $new_base, $extension, &$already_done, &$outcome ) {
		if ( isset( $already_done[ $name ] ) ) {
			return $already_done[ $name ];
		}

		$source = $directory . '/' . $name;

		/*
		 * Core names a derivative `<base>-<width>x<height>.<ext>` or `<base>-scaled.<ext>`, so the
		 * separator is what tells `photo-150x150.jpg` from `photobooth-150x150.jpg`. Without it the
		 * second would be rebased into `photo-<random>booth-150x150.jpg`, splicing the name rather than
		 * replacing what it starts with.
		 */
		$derived = $name === $old_base . $extension || str_starts_with( $name, $old_base . '-' );

		if ( ! $derived ) {
			// Not this attachment's to rename. Count it only if there's a file there to be concerned with.
			$outcome['left_behind'] += is_file( $source ) ? 1 : 0;
			$already_done[ $name ]   = $name;

			return $name;
		}

		$new_name = $new_base . substr( $name, strlen( $old_base ) );

		// A name in the metadata with no file behind it was already stale, and moves nothing either way.
		if ( ! is_file( $source ) ) {
			$already_done[ $name ] = $name;

			return $name;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- WP_Filesystem needs credentials this can't ask for, and a failure is reported rather than fatal.
		if ( ! @rename( $source, $directory . '/' . $new_name ) ) {
			++$outcome['left_behind'];
			$already_done[ $name ] = $name;

			return $name;
		}

		++$outcome['renamed'];
		$already_done[ $name ] = $new_name;

		return $new_name;
	};

	$attached_name = wp_basename( $attached_path );
	$new_attached  = $move( $attached_name );

	/*
	 * Carry on when the attached file itself wouldn't move. Everything else is named from `$old_base`
	 * rather than from it, so those can still go -- and whether they went or not is the whole of what the
	 * count is for.
	 */
	if ( ! empty( $metadata['original_image'] ) ) {
		$metadata['original_image'] = $move( $metadata['original_image'] );
	}

	foreach ( $metadata['sizes'] ?? array() as $size => $details ) {
		if ( empty( $details['file'] ) ) {
			continue;
		}

		$metadata['sizes'][ $size ]['file'] = $move( $details['file'] );
	}

	if ( ! empty( $metadata['file'] ) ) {
		$path_prefix      = str_contains( $metadata['file'], '/' ) ? dirname( $metadata['file'] ) . '/' : '';
		$metadata['file'] = $path_prefix . $new_attached;
	}

	if ( $metadata ) {
		wp_update_attachment_metadata( $attachment_id, $metadata );
	}

	if ( $new_attached !== $attached_name ) {
		update_attached_file( $attachment_id, $directory . '/' . $new_attached );
	}

	return $outcome;
}


/**
 * Say how a rename went, for the report.
 *
 * An attachment is rarely one file: a scaled photo leaves the full-size original beside it, and every
 * registered size is another copy. The count is what says whether they all moved, since the `File` column
 * can only name one of them.
 *
 * @param array $outcome     As returned by `rename_agreement_file()`.
 * @param bool  $dry_run
 * @param bool  $skip_rename
 *
 * @return string
 */
function describe_rename( $outcome, $dry_run, $skip_rename ) {
	$renamed     = $outcome['renamed'] ?? 0;
	$left_behind = $outcome['left_behind'] ?? 0;

	if ( $skip_rename ) {
		return 'skipped';
	}

	if ( $dry_run ) {
		return 'pending';
	}

	/*
	 * Nothing either way means `rename_agreement_file()` returned before it started, which it only does
	 * when `_wp_attached_file` names a file that isn't there.
	 */
	if ( ! $renamed && ! $left_behind ) {
		return 'file missing';
	}

	if ( $left_behind ) {
		return sprintf( '%d moved, %d left', $renamed, $left_behind );
	}

	return sprintf( '%d %s', $renamed, 1 === $renamed ? 'file' : 'files' );
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
	const REPORT_COLUMNS = array( 'Attachment', 'File', 'Private', 'Renamed' );

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
		$skip_rename   = isset( $assoc_args['skip-rename'] );
		$format        = $assoc_args['format'] ?? 'table';
		$agreement_ids = get_public_agreement_ids();
		$results       = array();
		$unfinished    = array();
		$untouched     = array(
			'renamed'     => 0,
			'left_behind' => 0,
		);

		foreach ( $agreement_ids as $agreement_id ) {
			if ( $dry_run ) {
				$migrated = true;
				$outcome  = $untouched;
			} else {
				$migrated = make_agreement_private( $agreement_id );
				$outcome  = $skip_rename ? $untouched : rename_agreement_file( $agreement_id );
			}

			/*
			 * Anything short of "every file moved" needs saying, and that includes the attachment whose
			 * file was already gone -- it moved nothing, but it also isn't finished.
			 */
			if ( ! $dry_run && ! $skip_rename && ( $outcome['left_behind'] || ! $outcome['renamed'] ) ) {
				$unfinished[] = $agreement_id;
			}

			$results[] = array(
				'Attachment' => $agreement_id,

				// Read after the fact, so that the column names the file as it now stands on disk.
				'File'       => wp_basename( (string) get_attached_file( $agreement_id ) ),
				'Private'    => $migrated ? 'yes' : 'no',
				'Renamed'    => describe_rename( $outcome, $dry_run, $skip_rename ),
			);
		}

		if ( ! $results ) {
			// A machine-readable run still has to answer with something its caller can parse.
			if ( 'table' !== $format ) {
				format_items( $format, array(), self::REPORT_COLUMNS );
			}

			$this->summarize( 'success', 'No sponsor agreements left to migrate.', $format );

			return;
		}

		// Blank lines frame the table for a person, and corrupt every other format.
		if ( 'table' === $format ) {
			WP_CLI::line();
		}

		format_items( $format, $results, self::REPORT_COLUMNS );

		if ( 'table' === $format ) {
			WP_CLI::line();
		}

		if ( $dry_run ) {
			$this->summarize( 'success', sprintf( '%d agreements would be migrated. Run without --dry-run to apply.', count( $results ) ), $format );
		} elseif ( $unfinished ) {
			$message = sprintf(
				'%d of %d agreements are unfinished; the Renamed column says what happened to each.',
				count( $unfinished ),
				count( $results )
			);

			$this->summarize( 'warning', $message, $format );
		} else {
			$this->summarize( 'success', sprintf( '%d agreements processed.', count( $results ) ), $format );
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
