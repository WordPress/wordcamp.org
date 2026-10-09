<?php
/*
 * Seed the operational "Flagships" sponsor group.
 *
 * Makes "flagship" an explicit, targetable group instead of something only
 * inferred from URL patterns (see wporg-flagship-landing's get_flagship_events()).
 * Per decision (2026-07-08), the flagship list is seeded from the network's
 * flagship domains and stays admin-editable afterward — no formal owner.
 *
 * Run via WP-CLI on central:
 *   wp mes seed-flagships [--domains=us,europe,asia] [--dry-run]
 */

class MES_Flagships {
	const GROUP_NAME       = 'Flagships';
	const MARKER_TERM_META = '_mes_is_flagships_group';

	/**
	 * The third-level domains whose camps count as flagships (us.wordcamp.org, …).
	 *
	 * @return string[]
	 */
	public static function default_domains() {
		return apply_filters( 'mes_flagship_domains', array( 'us', 'europe', 'asia' ) );
	}

	/**
	 * Create the Flagships group (once) and assign camps matching the flagship domains.
	 *
	 * Idempotent: the group is matched by a term-meta marker, and camps already in
	 * the group are skipped. Only assigns — never removes (the group stays curated
	 * by admins afterward).
	 *
	 * @param string[] $domains Optional. Third-level domains; default us/europe/asia.
	 * @param bool     $dry_run If true, count what would change but write nothing.
	 *
	 * @return array { group_created (bool), group_name_taken (bool), camps_assigned (int), camps_matched (int) }
	 *               `group_name_taken` means a hand-made group already has the name, so nothing was done.
	 */
	public static function seed( array $domains = array(), $dry_run = false ) {
		$domains = $domains ? array_map( 'sanitize_key', $domains ) : self::default_domains();
		$summary = array(
			'group_created'    => false,
			'group_name_taken' => false,
			'camps_assigned'   => 0,
			'camps_matched'    => 0,
		);

		$group_id = self::find_flagships_group();

		if ( ! $group_id ) {
			// A hand-made group with this name isn't the seeded one, and wp_insert_term() would
			// refuse the duplicate. Stop here, in a dry run too, and let the caller report it.
			if ( term_exists( self::GROUP_NAME, MES_Sponsor_Group::TAXONOMY_SLUG ) ) {
				$summary['group_name_taken'] = true;

				return $summary;
			}

			$summary['group_created'] = true;

			if ( $dry_run ) {
				$group_id = -1; // Virtual placeholder, mirrors MES_Migrate_Groups.
			} else {
				$created = wp_insert_term( self::GROUP_NAME, MES_Sponsor_Group::TAXONOMY_SLUG );

				if ( is_wp_error( $created ) ) {
					$summary['group_created']    = false;
					$summary['group_name_taken'] = true;

					return $summary;
				}

				$group_id = (int) $created['term_id'];
				update_term_meta( $group_id, self::MARKER_TERM_META, 1 );
			}
		}

		$pattern = '^https?://(' . implode( '|', array_map( 'preg_quote', $domains ) ) . ')\.';

		$camps = get_posts( array(
			'post_type'   => WCPT_POST_TYPE_ID,
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
			'meta_query'  => array(
				array(
					'key'     => 'URL',
					'value'   => $pattern,
					'compare' => 'REGEXP',
				),
			),
		) );

		foreach ( $camps as $camp_id ) {
			++$summary['camps_matched'];

			$existing = MES_Sponsor_Group::get_stored_camp_groups( $camp_id );

			if ( in_array( $group_id, $existing, true ) ) {
				continue;
			}

			if ( ! $dry_run ) {
				$existing[] = $group_id;
				update_post_meta( $camp_id, 'mes_sponsor_groups', $existing );
			}

			++$summary['camps_assigned'];
		}

		return $summary;
	}

	/**
	 * Find the already-seeded Flagships group, or 0.
	 *
	 * @return int Group term ID, or 0 when it doesn't exist yet.
	 */
	protected static function find_flagships_group() {
		$terms = get_terms( array(
			'taxonomy'   => MES_Sponsor_Group::TAXONOMY_SLUG,
			'hide_empty' => false,
			'meta_key'   => self::MARKER_TERM_META,
			'meta_value' => 1,
		) );

		return ( ! is_wp_error( $terms ) && $terms ) ? (int) $terms[0]->term_id : 0;
	}
}
