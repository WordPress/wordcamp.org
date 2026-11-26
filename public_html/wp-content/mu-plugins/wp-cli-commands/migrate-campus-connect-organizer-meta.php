<?php

/**
 * A one-time migration; delete this file and its bootstrap.php require once it has been run.
 *
 * 1. Renames legacy Campus Connect organizer usermeta keys (`wc_wcb_organizer_{site}_*`) to
 *    `wc_campus_connect_organizer_{site}_*` so they no longer share a prefix with WordCamp organizers.
 * 2. Ensures each Campus Connect organizer has the `campus-connect-organizer` Profiles association, and
 *    removes `wordcamp-organizer` when they have no remaining WordCamp organizer usermeta (they were
 *    incorrectly given that badge before association selection was subtype-aware).
 *
 * Usermeta is network-global, so one invocation covers every Campus Connect site.
 */

namespace WordCamp\Participation_Notifier\Migrate_Campus_Connect_Organizer_Meta;

use WP_CLI;
use WP_CLI_Command;
use WordCamp_Participation_Notifier;
use WordPressdotorg\Profiles;

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
 * @return array{renamed: int, deleted_duplicate: int, keys: string[], user_ids: int[]}
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
		'user_ids'          => array(),
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
		$user_id            = (int) $row->user_id;
		$result['user_ids'][] = $user_id;

		$new_key          = $new_prefix . substr( $row->meta_key, strlen( $old_prefix ) );
		$result['keys'][] = $row->meta_key . ' -> ' . $new_key;

		if ( metadata_exists( 'user', $user_id, $new_key ) ) {
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

		clean_user_cache( $user_id );
	}

	$result['user_ids'] = array_values( array_unique( $result['user_ids'] ) );

	return $result;
}

/**
 * User IDs that have Campus Connect organizer usermeta (new prefix and/or legacy keys for CC sites).
 *
 * @param int[] $cc_site_ids Campus Connect site IDs.
 *
 * @return int[]
 */
function get_campus_connect_organizer_user_ids( array $cc_site_ids ) {
	global $wpdb;

	$user_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( WordCamp_Participation_Notifier::CAMPUS_CONNECT_ORGANIZER_META_PREFIX ) . '%'
		)
	);

	foreach ( $cc_site_ids as $site_id ) {
		$legacy_users = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
				$wpdb->esc_like( WordCamp_Participation_Notifier::WORDCAMP_ORGANIZER_META_PREFIX . (int) $site_id . '_' ) . '%'
			)
		);
		$user_ids     = array_merge( $user_ids, $legacy_users );
	}

	$user_ids = array_filter( array_map( 'absint', $user_ids ) );

	return array_values( array_unique( $user_ids ) );
}

/**
 * Whether a usermeta key is a legacy WordCamp-prefix organizer key for a Campus Connect site.
 *
 * @param string $meta_key    Full usermeta key.
 * @param int[]  $cc_site_ids Campus Connect site IDs.
 *
 * @return bool
 */
function is_legacy_campus_connect_organizer_key( $meta_key, array $cc_site_ids ) {
	$prefix = WordCamp_Participation_Notifier::WORDCAMP_ORGANIZER_META_PREFIX;

	if ( ! str_starts_with( $meta_key, $prefix ) ) {
		return false;
	}

	$rest    = substr( $meta_key, strlen( $prefix ) );
	$site_id = (int) strstr( $rest, '_', true );

	return $site_id && in_array( $site_id, $cc_site_ids, true );
}

/**
 * Whether the user should hold the Campus Connect organizer Profiles association.
 *
 * @param int   $user_id                    User ID.
 * @param int[] $cc_site_ids                Campus Connect site IDs.
 * @param bool  $include_legacy_cc_as_cc    When true (dry-run before rename), treat legacy CC-site keys as CC entitlement.
 *
 * @return bool
 */
function user_deserves_campus_connect_organizer( $user_id, array $cc_site_ids, $include_legacy_cc_as_cc = false ) {
	global $wpdb;

	$count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s",
			$user_id,
			$wpdb->esc_like( WordCamp_Participation_Notifier::CAMPUS_CONNECT_ORGANIZER_META_PREFIX ) . '%'
		)
	);

	if ( $count > 0 ) {
		return true;
	}

	if ( ! $include_legacy_cc_as_cc ) {
		return false;
	}

	foreach ( $cc_site_ids as $site_id ) {
		$legacy_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s",
				$user_id,
				$wpdb->esc_like( WordCamp_Participation_Notifier::WORDCAMP_ORGANIZER_META_PREFIX . (int) $site_id . '_' ) . '%'
			)
		);

		if ( $legacy_count > 0 ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether the user should keep the WordCamp organizer Profiles association.
 *
 * @param int   $user_id                 User ID.
 * @param int[] $cc_site_ids             Campus Connect site IDs.
 * @param bool  $exclude_legacy_cc_keys  When true (dry-run before rename), ignore legacy CC-site keys.
 *
 * @return bool
 */
function user_deserves_wordcamp_organizer( $user_id, array $cc_site_ids, $exclude_legacy_cc_keys = false ) {
	global $wpdb;

	$keys = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT meta_key FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s",
			$user_id,
			$wpdb->esc_like( WordCamp_Participation_Notifier::WORDCAMP_ORGANIZER_META_PREFIX ) . '%'
		)
	);

	if ( empty( $keys ) ) {
		return false;
	}

	if ( ! $exclude_legacy_cc_keys ) {
		return true;
	}

	foreach ( $keys as $meta_key ) {
		if ( ! is_legacy_campus_connect_organizer_key( $meta_key, $cc_site_ids ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Build a Profiles association payload (same shape as the participation notifier).
 *
 * @param int    $user_id     WordPress user ID.
 * @param string $command     `add` or `remove`.
 * @param string $association Association slug.
 *
 * @return array
 */
function build_association_payload( $user_id, $command, $association ) {
	return array(
		'action'        => 'wporg_handle_association',
		'source'        => 'wordcamp',
		'command'       => $command,
		'user_id'       => (int) $user_id,
		'association'   => $association,
		'wordcamp_id'   => defined( 'WORDCAMP_ROOT_BLOG_ID' ) ? (int) WORDCAMP_ROOT_BLOG_ID : get_current_blog_id(),
		'wordcamp_name' => 'Campus Connect organizer meta migration',
		'url'           => home_url( '/' ),
	);
}

/**
 * Load Profiles helpers when actually sending association requests.
 *
 * @return bool Whether Profiles\api is available.
 */
function ensure_profiles_api_loaded() {
	static $loaded = false;

	if ( $loaded ) {
		return function_exists( 'WordPressdotorg\\Profiles\\api' );
	}

	$path = WP_CONTENT_DIR . '/mu-plugins-private/wporg-mu-plugins/pub/profile-helpers.php';
	if ( ! is_readable( $path ) ) {
		return false;
	}

	require_once $path;
	$loaded = true;

	return function_exists( 'WordPressdotorg\\Profiles\\api' );
}

/**
 * Decide (and optionally apply) Profiles badge fixes for one Campus Connect organizer.
 *
 * @param int   $user_id     User ID.
 * @param int[] $cc_site_ids Campus Connect site IDs.
 * @param bool  $dry_run     When true, do not call Profiles or assume meta has been renamed yet.
 *
 * @return array{user_id: int, login: string, add_campus_connect: bool, remove_wordcamp: bool}
 */
function reconcile_profiles_badges_for_user( $user_id, array $cc_site_ids, $dry_run = false ) {
	$user_id = (int) $user_id;
	$user    = get_userdata( $user_id );

	$result = array(
		'user_id'            => $user_id,
		'login'              => $user ? $user->user_login : '',
		'add_campus_connect' => false,
		'remove_wordcamp'    => false,
	);

	// Dry-run runs before meta rename, so legacy CC-site keys still count as CC entitlement.
	$deserves_cc = user_deserves_campus_connect_organizer( $user_id, $cc_site_ids, $dry_run );
	$deserves_wc = user_deserves_wordcamp_organizer( $user_id, $cc_site_ids, $dry_run );

	if ( ! $deserves_cc ) {
		return $result;
	}

	$result['add_campus_connect'] = true;

	if ( ! $deserves_wc ) {
		$result['remove_wordcamp'] = true;
	}

	if ( $dry_run ) {
		return $result;
	}

	if ( ! ensure_profiles_api_loaded() ) {
		return $result;
	}

	Profiles\api( build_association_payload( $user_id, 'add', 'campus-connect-organizer' ) );

	if ( $result['remove_wordcamp'] ) {
		Profiles\api( build_association_payload( $user_id, 'remove', 'wordcamp-organizer' ) );
	}

	return $result;
}

/**
 * Migrate usermeta and reconcile Profiles badges for Campus Connect organizers.
 *
 * @param bool $dry_run When true, report without writing usermeta or calling Profiles.
 *
 * @return array{
 *   sites: int,
 *   renamed: int,
 *   deleted_duplicate: int,
 *   details: array<int, array>,
 *   badge_actions: array<int, array>,
 *   badges_added: int,
 *   badges_removed: int
 * }
 */
function migrate_all_campus_connect_organizer_meta( $dry_run = false ) {
	$cc_site_ids = get_campus_connect_site_ids();
	$summary     = array(
		'sites'             => 0,
		'renamed'           => 0,
		'deleted_duplicate' => 0,
		'details'           => array(),
		'badge_actions'     => array(),
		'badges_added'      => 0,
		'badges_removed'    => 0,
	);

	foreach ( $cc_site_ids as $site_id ) {
		$site_result = migrate_organizer_meta_for_site( $site_id, $dry_run );

		if ( ! $site_result['renamed'] && ! $site_result['deleted_duplicate'] ) {
			continue;
		}

		++$summary['sites'];
		$summary['renamed']           += $site_result['renamed'];
		$summary['deleted_duplicate'] += $site_result['deleted_duplicate'];
		$summary['details'][ $site_id ] = $site_result;
	}

	foreach ( get_campus_connect_organizer_user_ids( $cc_site_ids ) as $user_id ) {
		$badge_result = reconcile_profiles_badges_for_user( $user_id, $cc_site_ids, $dry_run );

		if ( ! $badge_result['add_campus_connect'] && ! $badge_result['remove_wordcamp'] ) {
			continue;
		}

		$summary['badge_actions'][] = $badge_result;

		if ( $badge_result['add_campus_connect'] ) {
			++$summary['badges_added'];
		}

		if ( $badge_result['remove_wordcamp'] ) {
			++$summary['badges_removed'];
		}
	}

	return $summary;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * WordCamp.org: one-time Campus Connect organizer usermeta + Profiles badge migration.
 */
class Command extends WP_CLI_Command {
	/**
	 * Rename legacy Campus Connect organizer usermeta keys and fix Profiles badges.
	 *
	 * Finds Central `wordcamp` posts with `event_subtype=campusconnect`, renames matching
	 * `wc_wcb_organizer_{site_id}_*` usermeta rows to `wc_campus_connect_organizer_{site_id}_*`,
	 * ensures those organizers have `campus-connect-organizer` on Profiles, and removes
	 * `wordcamp-organizer` when they have no remaining WordCamp organizer associations.
	 *
	 * Safe to re-run: already-migrated keys are skipped; Profiles add/remove are idempotent enough
	 * for a second pass.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change without writing usermeta or calling Profiles.
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

		if ( ! $dry_run && ! ensure_profiles_api_loaded() ) {
			WP_CLI::error( 'Could not load Profiles helpers; refusing to migrate without Profiles\\api().' );
		}

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

		foreach ( $summary['badge_actions'] as $badge_result ) {
			$who = $badge_result['login']
				? sprintf( 'user %d (%s)', $badge_result['user_id'], $badge_result['login'] )
				: sprintf( 'user %d', $badge_result['user_id'] );

			if ( $badge_result['add_campus_connect'] ) {
				WP_CLI::log(
					sprintf(
						'%s: %s campus-connect-organizer.',
						$who,
						$dry_run ? 'would add' : 'add'
					)
				);
			}

			if ( $badge_result['remove_wordcamp'] ) {
				WP_CLI::log(
					sprintf(
						'%s: %s wordcamp-organizer (no remaining WordCamp organizer associations).',
						$who,
						$dry_run ? 'would remove' : 'remove'
					)
				);
			}
		}

		$message = sprintf(
			'%s %d site(s): %d key(s) renamed, %d duplicate legacy row(s) removed; %s %d campus-connect-organizer, %s %d wordcamp-organizer.',
			$dry_run ? 'Would migrate' : 'Migrated',
			$summary['sites'],
			$summary['renamed'],
			$summary['deleted_duplicate'],
			$dry_run ? 'would add' : 'added',
			$summary['badges_added'],
			$dry_run ? 'would remove' : 'removed',
			$summary['badges_removed']
		);

		if ( $dry_run ) {
			WP_CLI::success( $message . ' (dry run)' );
			return;
		}

		WP_CLI::success( $message );
	}
}

WP_CLI::add_command( 'wc-cc-organizer-meta', __NAMESPACE__ . '\Command' );
