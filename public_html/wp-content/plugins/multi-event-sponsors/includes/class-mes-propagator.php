<?php
/*
 * Add-only, network-wide propagation of Multi-Event Sponsors to existing camp sites.
 *
 * Solves the mid-cycle workflow: a new Global sponsor joins after camp sites already
 * exist, and today central has no way to place them there short of editing every
 * WordCamp post (per-camp "Push new sponsors to site" checkbox) or emailing every
 * organizing team. This runner applies the same add-only push across all camps with
 * a live site, in one action.
 *
 * By decision (2026-07-08), this NEVER updates or removes anything — it only creates
 * stubs for sponsors that are missing (matched by _mes_id), so re-running is always
 * safe. Removal stays a manual, per-site action.
 *
 * Only camps that are still active and whose event isn't over are touched: a new
 * sponsor has no business on the site of a camp that already happened.
 *
 * Must run in the context of central (where the WordCamp posts and mes posts live):
 *   wp --url=central.wordcamp.org mes propagate [--sponsor=<mes_post_id>] [--dry-run]
 */

class MES_Propagator {
	/**
	 * The MES ids that should be added: desired minus present.
	 *
	 * @param int[] $desired_mes_ids MES post IDs that should be on the site now.
	 * @param int[] $present_mes_ids MES post IDs already pushed to the site.
	 *
	 * @return int[] Normalized (absint, deduped) list of missing MES post IDs.
	 */
	public static function missing( array $desired_mes_ids, array $present_mes_ids ) {
		$desired = array_unique( array_filter( array_map( 'absint', $desired_mes_ids ) ) );
		$present = array_map( 'absint', $present_mes_ids );

		return array_values( array_diff( $desired, $present ) );
	}

	/**
	 * Push missing Multi-Event Sponsors to every camp site.
	 *
	 * @param int  $mes_id  Optional. Limit to one Multi-Event Sponsor post ID.
	 * @param bool $dry_run If true, count what would be added but write nothing.
	 *
	 * @return array { camps_scanned, camps_updated, sponsors_added, camps_skipped_no_author }
	 *               `camps_skipped_no_author` lists camps that needed a stub but have no lead organizer,
	 *               while nobody is logged in to author it instead (WP-CLI without `--user`).
	 */
	public static function run( $mes_id = 0, $dry_run = false ) {
		/** @var $multi_event_sponsors Multi_Event_Sponsors */
		global $multi_event_sponsors;

		$mes_id  = absint( $mes_id );
		$summary = array(
			'camps_scanned'           => 0,
			'camps_updated'           => 0,
			'sponsors_added'          => 0,
			'camps_skipped_no_author' => array(),
		);

		$camps = get_posts( array(
			'post_type'   => WCPT_POST_TYPE_ID,
			'post_status' => WordCamp_Loader::get_active_wordcamp_statuses(),
			'numberposts' => -1,
			'fields'      => 'ids',
			'meta_key'    => '_site_id',
		) );

		foreach ( $camps as $camp_id ) {
			$site_id = absint( get_post_meta( $camp_id, '_site_id', true ) );

			if ( ! $site_id || ! get_site( $site_id ) || self::event_is_over( $camp_id ) ) {
				continue;
			}

			++$summary['camps_scanned'];

			// The group-aware join decides who belongs on this camp (groups + legacy region),
			// and at which level, the same call site creation makes.
			$desired_sponsors = array();

			foreach ( $multi_event_sponsors->get_wordcamp_me_sponsors( $camp_id, 'sponsor_level' ) as $sponsors ) {
				foreach ( $sponsors as $sponsor ) {
					if ( $mes_id && $mes_id !== (int) $sponsor->ID ) {
						continue;
					}

					$desired_sponsors[ $sponsor->ID ] = $sponsor;
				}
			}

			if ( ! $desired_sponsors ) {
				continue;
			}

			$author_id = self::get_author_id( $camp_id );

			if ( ! $author_id && ! $dry_run ) {
				$summary['camps_skipped_no_author'][] = $camp_id;
				continue;
			}

			$added = static::push_missing_to_site( $site_id, $desired_sponsors, $author_id, $dry_run );

			if ( $added > 0 ) {
				++$summary['camps_updated'];
				$summary['sponsors_added'] += $added;
			}
		}

		return $summary;
	}

	/**
	 * Whether the camp's event has already happened.
	 *
	 * Mirrors `WordCamp_Admin::close_wordcamps_after_event()`: the event runs until 23:59 on its end
	 * date, or its start date when there's no end date. A camp with no dates yet is treated as upcoming.
	 *
	 * @param int $camp_id
	 *
	 * @return bool
	 */
	protected static function event_is_over( $camp_id ) {
		$end_date = absint( get_post_meta( $camp_id, 'End Date (YYYY-mm-dd)', true ) );

		if ( ! $end_date ) {
			$end_date = absint( get_post_meta( $camp_id, 'Start Date (YYYY-mm-dd)', true ) );
		}

		if ( ! $end_date ) {
			return false;
		}

		return strtotime( '23:59', $end_date ) <= time();
	}

	/**
	 * Who authors the stubs on a camp's site: its lead organizer, else whoever is running this.
	 *
	 * The same fallback as `WordCamp_New_Site::get_user_or_current_user()`. Under WP-CLI without
	 * `--user` the current user is 0, and the caller skips the camp rather than author stubs by nobody.
	 *
	 * @param int $camp_id
	 *
	 * @return int User ID, or 0 when there's nobody.
	 */
	protected static function get_author_id( $camp_id ) {
		$lead_organizer = get_user_by( 'login', get_post_meta( $camp_id, 'WordPress.org Username', true ) );

		return $lead_organizer ? (int) $lead_organizer->ID : get_current_user_id();
	}

	/**
	 * Create stubs on one camp site for the desired sponsors that are missing.
	 *
	 * Each stub is shaped like the one site creation makes: the copied `mes` meta, the sponsor's
	 * logo sideloaded as the featured image, and the camp's sponsorship level for that sponsor as
	 * a `wcb_sponsor_level` term, created on the site if it isn't there yet.
	 *
	 * @param int       $site_id          The camp's subsite blog ID.
	 * @param WP_Post[] $desired_sponsors Desired mes posts, keyed by post ID, each with `->sponsorship_level`.
	 * @param int       $author_id        Who authors the stubs.
	 * @param bool      $dry_run          If true, count without writing.
	 *
	 * @return int Number of sponsors added (or that would be added).
	 */
	protected static function push_missing_to_site( $site_id, array $desired_sponsors, $author_id, $dry_run ) {
		// Logo URLs are read on central, before switching, where the attachments live.
		$logo_urls = array();

		foreach ( $desired_sponsors as $sponsor_id => $sponsor ) {
			$attachment_id = get_post_thumbnail_id( $sponsor_id );
			$attachment    = $attachment_id ? wp_get_attachment_image_src( $attachment_id, 'full' ) : false;

			if ( $attachment ) {
				$logo_urls[ $sponsor_id ] = $attachment[0];
			}
		}

		switch_to_blog( $site_id );

		$present = array();

		// Trashed sponsors count as present: the organizers removed them on purpose.
		$site_sponsors = get_posts( array(
			'fields'         => 'ids',
			'post_type'      => 'wcb_sponsor',
			'post_status'    => array( 'any', 'trash' ),
			'posts_per_page' => -1,
			'cache_results'  => false,
		) );

		foreach ( $site_sponsors as $site_sponsor_id ) {
			$present_mes_id = get_post_meta( $site_sponsor_id, '_mes_id', true );

			if ( $present_mes_id ) {
				$present[] = absint( $present_mes_id );
			}
		}

		$missing = self::missing( array_keys( $desired_sponsors ), $present );

		if ( $dry_run ) {
			restore_current_blog();

			return count( $missing );
		}

		$added = 0;

		foreach ( $missing as $missing_mes_id ) {
			$sponsor = $desired_sponsors[ $missing_mes_id ];

			$new_post_id = wp_insert_post( array(
				'post_type'    => 'wcb_sponsor',
				'post_status'  => 'draft',
				'post_author'  => $author_id,
				'post_title'   => $sponsor->post_title,
				'post_content' => $sponsor->post_content,
			) );

			if ( ! $new_post_id || is_wp_error( $new_post_id ) ) {
				continue;
			}

			// Same stub meta as the site-creation push (includes _mes_id).
			$stub_meta = WordCamp_New_Site::get_stub_me_sponsors_meta( $sponsor );

			foreach ( $stub_meta as $key => $value ) {
				update_post_meta( $new_post_id, $key, $value );
			}

			if ( isset( $logo_urls[ $missing_mes_id ] ) ) {
				$attachment_id = static::sideload_logo( $logo_urls[ $missing_mes_id ], $new_post_id );

				if ( $attachment_id ) {
					set_post_thumbnail( $new_post_id, $attachment_id );
				}
			}

			if ( ! empty( $sponsor->sponsorship_level ) ) {
				self::assign_level( $new_post_id, $sponsor->sponsorship_level );
			}

			++$added;
		}

		restore_current_blog();

		return $added;
	}

	/**
	 * Download the sponsor's logo from central onto the current site, as the stub's attachment.
	 *
	 * @param string $url     The logo's URL on central.
	 * @param int    $post_id The stub to attach it to.
	 *
	 * @return int Attachment ID, or 0 when the download failed.
	 */
	protected static function sideload_logo( $url, $post_id ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$new_site = $GLOBALS['wordcamp_new_site'] ?? new WordCamp_New_Site();

		// WordPress runs from `mu/`, so the switched site's upload path needs the same fix the other pushes apply.
		add_filter( 'upload_dir', array( $new_site, '_fix_wc_upload_dir' ) );
		$attachment_id = media_sideload_image( $url, $post_id, null, 'id' );
		remove_filter( 'upload_dir', array( $new_site, '_fix_wc_upload_dir' ) );

		return is_wp_error( $attachment_id ) ? 0 : (int) $attachment_id;
	}

	/**
	 * Put the stub at its sponsorship level on the current site, creating the level there if needed.
	 *
	 * The same two steps as `WordCamp_New_Site::create_sponsorship_levels()` and `create_post_stubs()`:
	 * the term is named after the level and keyed by its slug.
	 *
	 * @param int     $post_id The stub.
	 * @param WP_Post $level   The `mes_sponsorship_level` post on central.
	 */
	protected static function assign_level( $post_id, $level ) {
		if ( ! term_exists( $level->post_name, 'wcb_sponsor_level' ) ) {
			wp_insert_term( $level->post_title, 'wcb_sponsor_level', array( 'slug' => $level->post_name ) );
		}

		wp_set_object_terms( $post_id, $level->post_name, 'wcb_sponsor_level', true );
	}
}
