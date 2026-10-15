<?php

/**
 * A one-time migration; delete this file and its bootstrap.php require once it has been run.
 *
 * Renames legacy Campus Connect organizer usermeta keys (`wc_wcb_organizer_{site}_*`) to
 * `wc_campus_connect_organizer_{site}_*` so they no longer share a prefix with WordCamp organizers.
 * Usermeta is network-global, so one invocation covers every Campus Connect site.
 *
 * Prefix strings come from `WordCamp_Participation_Notifier` so they cannot drift from the permanent
 * badge-tracking code. Everything else in this file goes when the migration does.
 */

namespace WordCamp\Participation_Notifier\Migrate_Campus_Connect_Organizer_Meta;

use WP_CLI;
use WP_CLI_Command;
use WordCamp_Participation_Notifier;

defined( 'WPINC' ) || die();

// phpcs:disable Universal.Files.SeparateFunctionsFromOO -- the command and the work it does are one unit here, so that removing the migration is removing one file.

/**
 * Site IDs for Central wordcamp posts marked as Campus Connect.
 *
 * Builds Central's table names from WORDCAMP_ROOT_BLOG_ID rather than switching, so WP-CLI can run this
 * from any site on the network.
 *
 * @return int[]
 */
function get_campus_connect_site_ids() {
	global $wpdb;

	if ( ! defined( 'WORDCAMP_ROOT_BLOG_ID' ) ) {
		return array();
	}

	$prefix = $wpdb->get_blog_prefix( WORDCAMP_ROOT_BLOG_ID );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix from get_blog_prefix().
	$site_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT pm_site.meta_value
			FROM {$prefix}postmeta pm_subtype
			INNER JOIN {$prefix}postmeta pm_site ON ( pm_subtype.post_id = pm_site.post_id AND pm_site.meta_key = '_site_id' )
			INNER JOIN {$prefix}posts AS posts ON ( posts.ID = pm_subtype.post_id AND posts.post_type = 'wordcamp' )
			WHERE pm_subtype.meta_key = 'event_subtype' AND pm_subtype.meta_value = %s",
			'campusconnect'
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$site_ids = array_filter( array_map( 'absint', $site_ids ) );

	return array_values( array_unique( $site_ids ) );
}

/**
 * Rename `wc_wcb_organizer_{site_id}_*` usermeta keys to the Campus Connect prefix.
 *
 * @param int  $site_id Event site ID encoded in the usermeta key.
 * @param bool $dry_run When true, report matches without writing.
 *
 * @return array{renamed: int, deleted_duplicate: int, keys: string[]} Counts and the keys that would change.
 */
function migrate_organizer_meta_for_site( $site_id, $dry_run = false ) {
	global $wpdb;

	$site_id    = (int) $site_id;
	$old_prefix = WordCamp_Participation_Notifier::WORDCAMP_ORGANIZER_META_PREFIX . $site_id . '_';
	$new_prefix = WordCamp_Participation_Notifier::CAMPUS_CONNECT_ORGANIZER_META_PREFIX . $site_id . '_';
	$result     = array(
		'renamed'           => 0,
		'deleted_duplicate' => 0,
		'keys'              => array(),
	);

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT umeta_id, user_id, meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( $old_prefix ) . '%'
		)
	);

	if ( empty( $rows ) ) {
		return $result;
	}

	foreach ( $rows as $row ) {
		$new_key          = $new_prefix . substr( $row->meta_key, strlen( $old_prefix ) );
		$result['keys'][] = $row->meta_key . ' -> ' . $new_key;

		if ( metadata_exists( 'user', (int) $row->user_id, $new_key ) ) {
			++$result['deleted_duplicate'];

			if ( ! $dry_run ) {
				delete_metadata_by_mid( 'user', (int) $row->umeta_id );
			}

			continue;
		}

		++$result['renamed'];

		if ( $dry_run ) {
			continue;
		}

		$wpdb->update(
			$wpdb->usermeta,
			array( 'meta_key' => $new_key ),
			array( 'umeta_id' => (int) $row->umeta_id ),
			array( '%s' ),
			array( '%d' )
		);

		clean_user_cache( (int) $row->user_id );
	}

	return $result;
}

/**
 * Migrate every Campus Connect site's legacy organizer usermeta keys.
 *
 * @param bool $dry_run When true, report matches without writing.
 *
 * @return array{sites: int, renamed: int, deleted_duplicate: int, details: array[]}
 */
function migrate_all_campus_connect_organizer_meta( $dry_run = false ) {
	$summary = array(
		'sites'             => 0,
		'renamed'           => 0,
		'deleted_duplicate' => 0,
		'details'           => array(),
	);

	foreach ( get_campus_connect_site_ids() as $site_id ) {
		$site_result = migrate_organizer_meta_for_site( $site_id, $dry_run );

		if ( ! $site_result['renamed'] && ! $site_result['deleted_duplicate'] ) {
			continue;
		}

		++$summary['sites'];
		$summary['renamed']           += $site_result['renamed'];
		$summary['deleted_duplicate'] += $site_result['deleted_duplicate'];
		$summary['details'][ $site_id ] = $site_result;
	}

	return $summary;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * WordCamp.org: one-time Campus Connect organizer usermeta migration.
 */
class Command extends WP_CLI_Command {
	/**
	 * Rename legacy Campus Connect organizer usermeta keys network-wide.
	 *
	 * Finds Central `wordcamp` posts with `event_subtype=campusconnect`, then renames matching
	 * `wc_wcb_organizer_{site_id}_*` usermeta rows to `wc_campus_connect_organizer_{site_id}_*`.
	 * Safe to re-run: already-migrated keys are skipped; duplicate new keys drop the legacy row.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change without writing.
	 *
	 * ## EXAMPLES
	 *
	 *     # Preview the migration.
	 *     wp wc-cc-organizer-meta migrate --dry-run
	 *
	 *     # Apply it.
	 *     wp wc-cc-organizer-meta migrate
	 *
	 * @subcommand migrate
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function migrate( $args, $assoc_args ) {
		$dry_run = isset( $assoc_args['dry-run'] );
		$summary = migrate_all_campus_connect_organizer_meta( $dry_run );

		foreach ( $summary['details'] as $site_id => $site_result ) {
			WP_CLI::log(
				sprintf(
					'Site %d: %d renamed, %d duplicate legacy rows %s.',
					$site_id,
					$site_result['renamed'],
					$site_result['deleted_duplicate'],
					$dry_run ? 'would be deleted' : 'deleted'
				)
			);

			foreach ( $site_result['keys'] as $key_change ) {
				WP_CLI::log( '  ' . $key_change );
			}
		}

		$message = sprintf(
			'%s %d site(s): %d key(s) renamed, %d duplicate legacy row(s) removed.',
			$dry_run ? 'Would migrate' : 'Migrated',
			$summary['sites'],
			$summary['renamed'],
			$summary['deleted_duplicate']
		);

		if ( $dry_run ) {
			WP_CLI::success( $message . ' (dry run)' );
			return;
		}

		WP_CLI::success( $message );
	}
}

WP_CLI::add_command( 'wc-cc-organizer-meta', __NAMESPACE__ . '\Command' );
