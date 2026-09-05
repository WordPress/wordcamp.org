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
	 * @return array { camps_scanned, camps_updated, sponsors_added }
	 */
	public static function run( $mes_id = 0, $dry_run = false ) {
		/** @var $multi_event_sponsors Multi_Event_Sponsors */
		global $multi_event_sponsors;

		$mes_id  = absint( $mes_id );
		$summary = array(
			'camps_scanned' => 0,
			'camps_updated' => 0,
			'sponsors_added' => 0,
		);

		$camps = get_posts( array(
			'post_type'   => WCPT_POST_TYPE_ID,
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
			'meta_key'    => '_site_id',
		) );

		foreach ( $camps as $camp_id ) {
			$site_id = absint( get_post_meta( $camp_id, '_site_id', true ) );

			if ( ! $site_id || ! get_site( $site_id ) ) {
				continue;
			}

			++$summary['camps_scanned'];

			// The group-aware join decides who belongs on this camp (groups + legacy region).
			$desired_sponsors = array();

			foreach ( $multi_event_sponsors->get_wordcamp_me_sponsors( $camp_id ) as $sponsor ) {
				if ( $mes_id && $mes_id !== (int) $sponsor->ID ) {
					continue;
				}

				$desired_sponsors[ $sponsor->ID ] = $sponsor;
			}

			if ( ! $desired_sponsors ) {
				continue;
			}

			$added = self::push_missing_to_site( $camp_id, $site_id, $desired_sponsors, $dry_run );

			if ( $added > 0 ) {
				++$summary['camps_updated'];
				$summary['sponsors_added'] += $added;
			}
		}

		return $summary;
	}

	/**
	 * Create stubs on one camp site for the desired sponsors that are missing.
	 *
	 * @param int       $camp_id          WordCamp post ID on central.
	 * @param int       $site_id          The camp's subsite blog ID.
	 * @param WP_Post[] $desired_sponsors Desired mes posts, keyed by post ID.
	 * @param bool      $dry_run          If true, count without writing.
	 *
	 * @return int Number of sponsors added (or that would be added).
	 */
	protected static function push_missing_to_site( $camp_id, $site_id, array $desired_sponsors, $dry_run ) {
		$lead_organizer = get_user_by( 'login', get_post_meta( $camp_id, 'WordPress.org Username', true ) );
		$author_id      = $lead_organizer ? $lead_organizer->ID : get_current_user_id();

		switch_to_blog( $site_id );

		$present = array();

		$site_sponsors = get_posts( array(
			'fields'         => 'ids',
			'post_type'      => 'wcb_sponsor',
			'post_status'    => 'any',
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

			++$added;
		}

		restore_current_blog();

		return $added;
	}
}
