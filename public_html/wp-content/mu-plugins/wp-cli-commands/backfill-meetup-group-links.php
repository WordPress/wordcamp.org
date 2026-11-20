<?php

defined( 'WP_CLI' ) || die();

/**
 * WordCamp.org: Link Meetup tracker records to the GatherPress groups they moved to.
 */
class WordCamp_CLI_Backfill_Meetup_Group_Links extends WP_CLI_Command {
	/**
	 * Link tracker records to group sites by their Meetup.com group name.
	 *
	 * A group site imported from Meetup.com stores the group's name; the tracker
	 * record has it at the end of its Meetup URL. Records that already have a
	 * GatherPress group URL are left alone, so it's safe to run again.
	 *
	 * Run it on WordCamp Central.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report the matches without saving them.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wc-meetup-group-links backfill --url=central.wordcamp.org --dry-run
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 */
	public function backfill( $args, $assoc_args ) {
		if ( ! class_exists( 'Meetup_Admin' ) || ! post_type_exists( 'wp_meetup' ) ) {
			WP_CLI::error( 'The Meetup tracker is not loaded here. Run this with --url=central.wordcamp.org.' );
		}

		$dry_run = ! empty( $assoc_args['dry-run'] );
		$groups  = $this->get_imported_groups();
		$linked  = array();

		foreach ( $this->get_unlinked_records() as $post_id ) {
			$path    = (string) wp_parse_url( (string) get_post_meta( $post_id, 'Meetup URL', true ), PHP_URL_PATH );
			$urlname = strtolower( basename( untrailingslashit( $path ) ) );
			$site_id = $groups[ $urlname ] ?? 0;

			if ( ! $site_id ) {
				continue;
			}

			$url = get_home_url( $site_id, '/' );

			WP_CLI::log( sprintf( '%s record %d → %s', $dry_run ? 'Would link' : 'Linked', $post_id, $url ) );

			if ( ! $dry_run ) {
				update_post_meta( $post_id, Meetup_Admin::GROUP_URL_KEY, $url );
				update_post_meta( $post_id, Meetup_Admin::GROUP_SITE_ID_KEY, $site_id );
			}

			$linked[] = $site_id;
		}

		foreach ( array_diff( $groups, $linked ) as $site_id ) {
			WP_CLI::log( sprintf( 'No unlinked tracker record matches %s', get_home_url( $site_id, '/' ) ) );
		}

		WP_CLI::success( sprintf( '%d records %s.', count( $linked ), $dry_run ? 'would be linked' : 'linked' ) );
	}

	/**
	 * Group sites imported from Meetup.com, keyed by lowercase group name.
	 *
	 * @return int[]
	 */
	protected function get_imported_groups() {
		$site_ids = get_sites(
			array(
				'network_id'   => GROUPS_NETWORK_ID,
				'site__not_in' => array( GROUPS_ROOT_BLOG_ID ),
				'archived'     => 0,
				'deleted'      => 0,
				'spam'         => 0,
				'number'       => 0,
				'fields'       => 'ids',
			)
		);

		$groups = array();

		foreach ( $site_ids as $site_id ) {
			$urlname = strtolower( (string) get_blog_option( $site_id, 'wporg_meetup_group_urlname' ) );

			if ( '' !== $urlname ) {
				$groups[ $urlname ] = (int) $site_id;
			}
		}

		return $groups;
	}

	/**
	 * Tracker records with a Meetup URL and no GatherPress group URL.
	 *
	 * Saving a record stores every field, so an unset group URL can be empty
	 * rather than missing; that's checked here instead of in the query.
	 *
	 * @return int[]
	 */
	protected function get_unlinked_records() {
		$post_ids = get_posts(
			array(
				'post_type'      => 'wp_meetup',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- A one-off command.
				'meta_key'       => 'Meetup URL',
				'meta_value'     => '',
				'meta_compare'   => '!=',
			)
		);

		return array_filter(
			$post_ids,
			static function ( $post_id ) {
				return '' === (string) get_post_meta( $post_id, Meetup_Admin::GROUP_URL_KEY, true );
			}
		);
	}
}
