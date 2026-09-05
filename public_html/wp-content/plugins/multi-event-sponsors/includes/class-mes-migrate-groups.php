<?php
/*
 * One-time, idempotent migration: express every legacy region relationship as a
 * sponsor group.
 *
 * Seeds a mes_sponsor_group term per mes-regions term (recording the origin region
 * in term meta so re-runs find it), assigns each camp its region-equivalent group,
 * and copies each sponsor's {region → level} map into {group → level}. Existing
 * hand-made group data is preserved; already-migrated pieces are skipped.
 *
 * Run via WP-CLI on central: wp mes migrate-groups [--dry-run]
 */

class MES_Migrate_Groups {
	const ORIGIN_TERM_META = '_mes_migrated_from_region';

	/**
	 * Seed a group per region, assign camps, and copy sponsor maps.
	 *
	 * @param bool $dry_run If true, count what would change but write nothing.
	 *
	 * @return array { groups_created, camps_assigned, sponsors_migrated }
	 */
	public static function run( $dry_run = false ) {
		$summary = array(
			'groups_created'    => 0,
			'camps_assigned'    => 0,
			'sponsors_migrated' => 0,
		);

		$regions = get_terms( array(
			'taxonomy'   => MES_Region::TAXONOMY_SLUG,
			'hide_empty' => false,
		) );

		if ( is_wp_error( $regions ) ) {
			return $summary;
		}

		$region_map = array(); // region term ID => group term ID.

		foreach ( $regions as $region ) {
			$group_id = self::find_group_for_region( $region->term_id );

			if ( ! $group_id ) {
				if ( $dry_run ) {
					// Virtual placeholder so the camp/sponsor passes below can still
					// count what WOULD be assigned to the yet-uncreated group.
					$group_id = -1 * $region->term_id;
				} else {
					$created = wp_insert_term( $region->name, MES_Sponsor_Group::TAXONOMY_SLUG );

					if ( is_wp_error( $created ) ) {
						continue;
					}

					$group_id = (int) $created['term_id'];
					update_term_meta( $group_id, self::ORIGIN_TERM_META, $region->term_id );
				}

				++$summary['groups_created'];
			}

			$region_map[ $region->term_id ] = $group_id;
		}

		// Assign each camp's region as a group membership.
		$camps = get_posts( array(
			'post_type'   => WCPT_POST_TYPE_ID,
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
		) );

		foreach ( $camps as $camp_id ) {
			$region = absint( get_post_meta( $camp_id, 'Multi-Event Sponsor Region', true ) );

			if ( ! $region || empty( $region_map[ $region ] ) ) {
				continue;
			}

			$existing = MES_Sponsor_Group::get_camp_groups( $camp_id );

			if ( ! in_array( $region_map[ $region ], $existing, true ) ) {
				if ( ! $dry_run ) {
					$existing[] = $region_map[ $region ];
					update_post_meta( $camp_id, 'mes_sponsor_groups', $existing );
				}

				++$summary['camps_assigned'];
			}
		}

		// Copy each sponsor's regional map into the group map.
		$sponsors = get_posts( array(
			'post_type'   => MES_Sponsor::POST_TYPE_SLUG,
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
		) );

		foreach ( $sponsors as $sponsor_id ) {
			$regional = get_post_meta( $sponsor_id, 'mes_regional_sponsorships', true );

			if ( ! is_array( $regional ) || ! $regional ) {
				continue;
			}

			$group_map = MES_Sponsor::get_group_sponsorships( $sponsor_id );
			$changed   = false;

			foreach ( $regional as $region_id => $level_id ) {
				$region_id = absint( $region_id );
				$level_id  = absint( $level_id );

				if ( ! $region_id || ! $level_id || empty( $region_map[ $region_id ] ) ) {
					continue;
				}

				$group_id = $region_map[ $region_id ];

				if ( empty( $group_map[ $group_id ] ) ) {
					$group_map[ $group_id ] = $level_id;
					$changed                = true;
				}
			}

			if ( $changed ) {
				if ( ! $dry_run ) {
					update_post_meta( $sponsor_id, 'mes_group_sponsorships', $group_map );
				}

				++$summary['sponsors_migrated'];
			}
		}

		return $summary;
	}

	/**
	 * Find an already-migrated group for a region, or 0.
	 *
	 * @param int $region_id Region term ID.
	 *
	 * @return int Group term ID, or 0 when the region hasn't been migrated yet.
	 */
	protected static function find_group_for_region( $region_id ) {
		$terms = get_terms( array(
			'taxonomy'   => MES_Sponsor_Group::TAXONOMY_SLUG,
			'hide_empty' => false,
			'meta_key'   => self::ORIGIN_TERM_META,
			'meta_value' => $region_id,
		) );

		return ( ! is_wp_error( $terms ) && $terms ) ? (int) $terms[0]->term_id : 0;
	}
}
