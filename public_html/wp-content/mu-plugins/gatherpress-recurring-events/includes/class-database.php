<?php
/**
 * Database schema and persistence.
 *
 * @package WordPressdotorg\GatherPress_Recurring_Events
 */

namespace WordPressdotorg\GatherPress_Recurring_Events;

use wpdb;

defined( 'WPINC' ) || die();

final class Database {

	const SCHEMA_VERSION = '1';
	const OPTION_NAME    = 'gpre_schema_version';

	/** Gets the per-site occurrence table name. */
	public static function occurrences_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'gatherpress_occurrences';
	}

	/** Gets the per-site occurrence-comment mapping table name. */
	public static function comments_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'gatherpress_occurrence_comments';
	}

	/** Creates or upgrades the per-site tables lazily. */
	public static function maybe_install(): void {
		if ( self::SCHEMA_VERSION === get_option( self::OPTION_NAME ) ) {
			return;
		}

		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$occurrences     = self::occurrences_table();
		$comments        = self::comments_table();

		dbDelta(
			"CREATE TABLE {$occurrences} (
				occurrence_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				series_post_id bigint(20) unsigned NOT NULL,
				recurrence_id varchar(16) NOT NULL,
				datetime_start datetime NOT NULL,
				datetime_start_gmt datetime NOT NULL,
				datetime_end datetime NOT NULL,
				datetime_end_gmt datetime NOT NULL,
				timezone varchar(64) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'scheduled',
				created_gmt datetime NOT NULL,
				updated_gmt datetime NOT NULL,
				PRIMARY KEY  (occurrence_id),
				UNIQUE KEY series_recurrence (series_post_id,recurrence_id),
				KEY start_gmt (datetime_start_gmt),
				KEY end_gmt (datetime_end_gmt),
				KEY series_status_start (series_post_id,status,datetime_start_gmt)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$comments} (
				comment_id bigint(20) unsigned NOT NULL,
				series_post_id bigint(20) unsigned NOT NULL,
				recurrence_id varchar(16) NOT NULL,
				PRIMARY KEY  (comment_id),
				KEY occurrence_comments (series_post_id,recurrence_id,comment_id)
			) {$charset_collate};"
		);

		update_option( self::OPTION_NAME, self::SCHEMA_VERSION, false );
	}

	/**
	 * Deletes extension data for a series.
	 *
	 * @param int $post_id Series post ID.
	 */
	public static function delete_series( int $post_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::comments_table(), array( 'series_post_id' => $post_id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::occurrences_table(), array( 'series_post_id' => $post_id ), array( '%d' ) );
		delete_transient( 'gpre_projected_' . $post_id );
	}

	/**
	 * Deletes an occurrence mapping for a comment.
	 *
	 * @param int $comment_id Comment ID.
	 */
	public static function delete_comment( int $comment_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::comments_table(), array( 'comment_id' => $comment_id ), array( '%d' ) );
	}

	/**
	 * Maps a comment or RSVP to an occurrence.
	 *
	 * @param int    $comment_id    Comment ID.
	 * @param int    $post_id       Series post ID.
	 * @param string $recurrence_id RFC recurrence identifier.
	 */
	public static function map_comment( int $comment_id, int $post_id, string $recurrence_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->replace(
			self::comments_table(),
			array(
				'comment_id'     => $comment_id,
				'series_post_id' => $post_id,
				'recurrence_id'  => $recurrence_id,
			),
			array( '%d', '%d', '%s' )
		);
	}
}
