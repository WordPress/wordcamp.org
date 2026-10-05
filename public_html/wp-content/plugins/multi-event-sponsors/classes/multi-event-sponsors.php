<?php

/*
 * Main controller to handle general functionality
 */

class Multi_Event_Sponsors {
	public const VERSION = '0.1';

	/**
	 * Constructor
	 */
	public function __construct() {
		add_shortcode( 'multi-event-sponsors', array( $this, 'shortcode_multi_event_sponsors' ) );
	}

	/**
	 * Prepares the site to use the plugin.
	 *
	 * This plugin is only intended to run in single-site mode on central.wordcamp.org.
	 *
	 * @param bool $network_wide
	 */
	public function activate( $network_wide ) {
		/** @var $mes_sponsor           MES_Sponsor */
		/** @var $mes_sponsorship_level MES_Sponsorship_Level */

		global $mes_sponsor, $mes_sponsorship_level;

		$mes_sponsor->create_post_type();
		$mes_sponsorship_level->create_post_type();
		flush_rewrite_rules();
	}

	/**
	 * Render the multi_event_sponsors shortcode output
	 *
	 * @param array $parameters
	 *
	 * @return string
	 */
	public function shortcode_multi_event_sponsors( $parameters ) {
		$sponsors           = $this->reindex_array_by_object_id( get_posts( array( 'post_type' => MES_Sponsor::POST_TYPE_SLUG, 'numberposts' => -1 ) ) );
		$regions            = $this->reindex_array_by_object_id( get_terms( MES_Region::TAXONOMY_SLUG, array( 'hide_empty' => false ) ) );
		$groups             = $this->reindex_array_by_object_id(
			get_terms(
				array(
					'taxonomy'   => MES_Sponsor_Group::TAXONOMY_SLUG,
					'hide_empty' => false,
				)
			)
		);
		$sponsorship_levels = $this->reindex_array_by_object_id( get_posts( array( 'post_type' => MES_Sponsorship_Level::POST_TYPE_SLUG, 'numberposts' => -1 ) ) );
		$grouped_sponsors   = $this->group_sponsors_by_region_and_level( $sponsors );
		$sponsors_by_group  = $this->group_sponsors_by_group_and_level( $sponsors );

		ob_start();
		require_once( dirname( __DIR__ ) . '/views/shortcode-multi-event-sponsors.php' );
		return ob_get_clean();
	}

	/**
	 * Re-indexes an array of posts or terms by their id.
	 *
	 * This makes for efficient direct access when the ID is known.
	 *
	 * @param array $old_array
	 *
	 * @return array
	 */
	protected function reindex_array_by_object_id( $old_array ) {
		$new_array = array();

		foreach ( $old_array as $item ) {
			if ( ! empty ( $item->ID ) ) {
				$new_array[ $item->ID ] = $item;
			} elseif ( ! empty( $item->term_id ) ) {
				$new_array[ $item->term_id ] = $item;
			}
		}

		return $new_array;
	}

	/**
	 * Create a multidimensional array that groups sponsors by region and sponsorship level.
	 *
	 * US East
	 *   WordCamp Pillar
	 *     BlueHost
	 *     Wired Tree
	 *   WordCamp Champion
	 *     Dreamhost
	 * US West
	 *   WordCamp Accomplice
	 *     Disqus
	 * etc
	 *
	 * @param array $sponsors
	 *
	 * @return array
	 */
	protected function group_sponsors_by_region_and_level( $sponsors ) {
		$grouped_sponsors = array();

		// Build the grouping
		foreach ( $sponsors as $sponsor ) {
			$regional_sponsorships = get_post_meta( $sponsor->ID, 'mes_regional_sponsorships', true );

			// Group-only sponsors have no regional map at all.
			if ( ! is_array( $regional_sponsorships ) ) {
				continue;
			}

			foreach ( $regional_sponsorships as $region_id => $level_id ) {
				if ( 'null' != $level_id ) {
					$grouped_sponsors[ $region_id ][ $level_id ][] = $sponsor->ID;
				}
			}
		}

		// Sort the grouping
		uksort( $grouped_sponsors, array( $this, 'uksort_regions' ) );

		foreach ( $grouped_sponsors as &$region ) {
			uksort( $region, array( $this, 'uksort_sponsorship_levels' ) );
		}

		return $grouped_sponsors;
	}

	/**
	 * Create a multidimensional array that groups sponsors by sponsor group and level.
	 *
	 * The group-based analogue of group_sponsors_by_region_and_level(), reading the
	 * mes_group_sponsorships map instead of the legacy regional one.
	 *
	 * @param array $sponsors
	 *
	 * @return array
	 */
	protected function group_sponsors_by_group_and_level( $sponsors ) {
		$grouped_sponsors = array();

		foreach ( $sponsors as $sponsor ) {
			foreach ( MES_Sponsor::get_group_sponsorships( $sponsor->ID ) as $group_id => $level_id ) {
				if ( $group_id && $level_id ) {
					$grouped_sponsors[ $group_id ][ $level_id ][] = $sponsor->ID;
				}
			}
		}

		uksort( $grouped_sponsors, array( $this, 'uksort_groups' ) );

		foreach ( $grouped_sponsors as &$group ) {
			uksort( $group, array( $this, 'uksort_sponsorship_levels' ) );
		}

		return $grouped_sponsors;
	}

	/**
	 * Sort sponsor groups by their name
	 *
	 * This is a callback for uksort().
	 *
	 * @param int $group_a_id
	 * @param int $group_b_id
	 *
	 * @return int
	 */
	protected function uksort_groups( $group_a_id, $group_b_id ) {
		$group_a = get_term( $group_a_id, MES_Sponsor_Group::TAXONOMY_SLUG );
		$group_b = get_term( $group_b_id, MES_Sponsor_Group::TAXONOMY_SLUG );

		return strcmp( $group_a->name ?? '', $group_b->name ?? '' );
	}

	/**
	 * Sort regions by their name
	 *
	 * This is a callback for uksort().
	 *
	 * @param int $region_a_id
	 * @param int $region_b_id
	 *
	 * @return int
	 */
	protected function uksort_regions( $region_a_id, $region_b_id ) {
		$region_a = get_term( $region_a_id, MES_Region::TAXONOMY_SLUG );
		$region_b = get_term( $region_b_id, MES_Region::TAXONOMY_SLUG );

		if ( $region_a->name == $region_b->name ) {
			return 0;
		} else {
			return ( $region_a->name < $region_b->name ) ? -1 : 1;
		}
	}

	/**
	 * Sort sponsorship levels by their contribution amount.
	 *
	 * This is a callback for uksort().
	 *
	 * @param int $level_a_id
	 * @param int $level_b_id
	 *
	 * @return int
	 */
	protected function uksort_sponsorship_levels( $level_a_id, $level_b_id ) {
		$level_a_contribution = (float) get_post_meta( $level_a_id, 'mes_contribution_per_attendee', true );
		$level_b_contribution = (float) get_post_meta( $level_b_id, 'mes_contribution_per_attendee', true );

		if ( $level_a_contribution == $level_b_contribution ) {
			return 0;
		} else {
			return ( $level_a_contribution > $level_b_contribution ) ? -1 : 1;
		}
	}

	/**
	 * Retrieve all of the Multi-Event Sponsors for the given WordCamp.
	 *
	 * @param int $wordcamp_id
	 * @param string $grouped_by
	 *     'ungrouped' will return a one-dimensional array;
	 *     'sponsor_level' will return an associative array with sponsors grouped by their level and indexed by level ID
	 *
	 * @return array
	 */
	public function get_wordcamp_me_sponsors( $wordcamp_id, $grouped_by = 'ungrouped' ) {
		$wordcamp_sponsors = array();

		// Legacy single region (kept for back-compat until the groups migration completes).
		if ( ! empty( $_POST[ wcpt_key_to_str( 'Multi-Event Sponsor Region', 'wcpt_' ) ] ) ) {
			$wordcamp_region = absint( $_POST[ wcpt_key_to_str( 'Multi-Event Sponsor Region', 'wcpt_' ) ] );
		} else {
			$wordcamp_region = absint( get_post_meta( $wordcamp_id, 'Multi-Event Sponsor Region', true ) );
		}

		$camp_groups = MES_Sponsor_Group::get_camp_groups( $wordcamp_id );

		$all_me_sponsors = get_posts( array(
			'post_type'   => MES_Sponsor::POST_TYPE_SLUG,
			'numberposts' => -1
		) );

		foreach ( $all_me_sponsors as $sponsor ) {
			$level_id = $this->get_sponsor_level_for_camp( $sponsor->ID, $wordcamp_region, $camp_groups );

			if ( ! $level_id ) {
				continue;
			}

			if ( 'sponsor_level' == $grouped_by ) {
				$sponsorship_level = get_post( $level_id );
				$sponsor->sponsorship_level = $sponsorship_level;
				$wordcamp_sponsors[ $sponsorship_level->ID ][] = $sponsor;
			} else {
				$wordcamp_sponsors[] = $sponsor;
			}
		}

		return $wordcamp_sponsors;
	}

	/**
	 * Resolve the sponsorship level a sponsor gets at a camp, via a group match or
	 * the legacy region.
	 *
	 * A group match takes precedence over the legacy region. When a camp is in
	 * multiple groups the sponsor targets, the level with the highest contribution
	 * per attendee wins, the same ranking uksort_sponsorship_levels() applies; ties
	 * fall to the lowest level post ID for determinism.
	 *
	 * @param int   $sponsor_id
	 * @param int   $wordcamp_region Legacy region term ID (0 if none).
	 * @param int[] $camp_groups     Group term IDs the camp belongs to.
	 *
	 * @return int Sponsorship level post ID, or 0 if the sponsor doesn't apply.
	 */
	protected function get_sponsor_level_for_camp( $sponsor_id, $wordcamp_region, array $camp_groups ) {
		$group_map = MES_Sponsor::get_group_sponsorships( $sponsor_id );
		$matches   = array();

		foreach ( $camp_groups as $group_id ) {
			if ( ! empty( $group_map[ $group_id ] ) && is_numeric( $group_map[ $group_id ] ) ) {
				$matches[] = absint( $group_map[ $group_id ] );
			}
		}

		if ( $matches ) {
			usort(
				$matches,
				function ( $a, $b ) {
					return $this->uksort_sponsorship_levels( $a, $b ) ?: ( $a <=> $b );
				}
			);

			return $matches[0];
		}

		// Legacy region fallback.
		if ( $wordcamp_region ) {
			$regional_sponsorships = get_post_meta( $sponsor_id, 'mes_regional_sponsorships', true );

			if ( ! empty( $regional_sponsorships[ $wordcamp_region ] ) && is_numeric( $regional_sponsorships[ $wordcamp_region ] ) ) {
				return absint( $regional_sponsorships[ $wordcamp_region ] );
			}
		}

		return 0;
	}

	/**
	 * Retrieve all of the e-mail addresses for the given sponsors.
	 *
	 * @param array $sponsors
	 *
	 * @return array
	 */
	public function get_sponsor_emails( $sponsors ) {
		$addresses = array();

		foreach ( $sponsors as $sponsor ) {
			$address = get_post_meta( $sponsor->ID, 'mes_email_address', true );

			if ( $address ) {
				$addresses[] = $address;
			}
		}

		return $addresses;
	}

	/**
	 * Retrieve the names of the given sponsors in a sentence format.
	 *
	 * @param array $sponsors
	 *
	 * @return string
	 */
	public function get_sponsor_names( $sponsors ) {
		$names = wp_list_pluck( $sponsors, 'post_title' );
		$count = count( $names );

		if ( 0 === $count ) {
			$names = '';
		} else if ( 1 === $count ) {
			$names = $names[0];
		} else {
			$names = implode( ', ', array_slice( $names, 0, $count - 1 ) ) . ' and ' . $names[ $count - 1 ];
		}

		return $names;
	}

	/**
	 * Retrieve general info for the given sponsors.
	 *
	 * @param array $sponsors
	 *
	 * @return array
	 */
	public function get_sponsor_info( $sponsors ) {
		$info = array();

		foreach ( $sponsors as $sponsor ) {
			$info[ $sponsor->ID ]['company_name']       = $sponsor->post_title;
			$info[ $sponsor->ID ]['sponsorship_levels'] = get_post_meta( $sponsor->ID, 'mes_regional_sponsorships', true );
			$info[ $sponsor->ID ]['contact_first_name'] = get_post_meta( $sponsor->ID, 'mes_first_name',            true );
			$info[ $sponsor->ID ]['contact_last_name']  = get_post_meta( $sponsor->ID, 'mes_last_name',             true );
			$info[ $sponsor->ID ]['contact_email']      = get_post_meta( $sponsor->ID, 'mes_email_address',         true );
		}

		return $info;
	}

	/**
	 * Get the excerpts for the given sponsors in HTML paragraphs.
	 *
	 * @param array $sponsors
	 *
	 * @return string
	 */
	public function get_sponsor_excerpts( $sponsors ) {
		$excerpts = wp_list_pluck( $sponsors, 'post_excerpt' );

		foreach ( $excerpts as & $excerpt ) {
			$excerpt = '<p>' . $excerpt . '</p>';
		}

		return implode( ' ', $excerpts );
	}
} // end Multi_Event_Sponsors
