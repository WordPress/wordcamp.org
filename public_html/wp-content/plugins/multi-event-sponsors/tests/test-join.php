<?php

defined( 'WPINC' ) || die();

/**
 * Tests for Multi_Event_Sponsors::get_wordcamp_me_sponsors().
 *
 * The first two tests are CHARACTERIZATION tests — they pin the legacy
 * region-based behavior so the group-aware rework can't regress it.
 *
 * @group multi-event-sponsors
 * @group sponsor-groups
 */
class Test_MES_Join extends WP_UnitTestCase {
	/**
	 * Start each test from a clean join, with no leftover request state.
	 */
	public function set_up() {
		parent::set_up();

		// The join reads the region from $_POST before falling back to post meta.
		unset( $_POST[ wcpt_key_to_str( 'Multi-Event Sponsor Region', 'wcpt_' ) ] );
	}

	/**
	 * Create a sponsor, a camp, and a level, joined via the legacy region map.
	 *
	 * @return array [ $sponsor_id, $wordcamp_id, $level_id, $region_id ]
	 */
	protected function make_region_pair() {
		$region_id  = self::factory()->term->create( array( 'taxonomy' => MES_Region::TAXONOMY_SLUG ) );
		$level_id   = self::factory()->post->create( array( 'post_type' => MES_Sponsorship_Level::POST_TYPE_SLUG ) );
		$sponsor_id = self::factory()->post->create( array( 'post_type' => MES_Sponsor::POST_TYPE_SLUG ) );

		update_post_meta( $sponsor_id, 'mes_regional_sponsorships', array( $region_id => $level_id ) );

		$wordcamp_id = self::factory()->post->create( array( 'post_type' => 'wordcamp' ) );

		update_post_meta( $wordcamp_id, 'Multi-Event Sponsor Region', $region_id );

		return array( $sponsor_id, $wordcamp_id, $level_id, $region_id );
	}

	/**
	 * Region match includes sponsor.
	 */
	public function test_region_match_includes_sponsor() {
		list( $sponsor_id, $wordcamp_id ) = $this->make_region_pair();

		$mes     = new Multi_Event_Sponsors();
		$matched = $mes->get_wordcamp_me_sponsors( $wordcamp_id );

		$this->assertContains( $sponsor_id, wp_list_pluck( $matched, 'ID' ) );
	}

	/**
	 * No region match excludes sponsor.
	 */
	public function test_no_region_match_excludes_sponsor() {
		$sponsor_id = self::factory()->post->create( array( 'post_type' => MES_Sponsor::POST_TYPE_SLUG ) );

		update_post_meta( $sponsor_id, 'mes_regional_sponsorships', array( 999 => 501 ) );

		$wordcamp_id = self::factory()->post->create( array( 'post_type' => 'wordcamp' ) );

		update_post_meta( $wordcamp_id, 'Multi-Event Sponsor Region', 11 );

		$mes = new Multi_Event_Sponsors();

		$this->assertEmpty( $mes->get_wordcamp_me_sponsors( $wordcamp_id ) );
	}

	/**
	 * Create a sponsor, a camp, and a level, joined via a sponsor group.
	 *
	 * @return array [ $sponsor_id, $wordcamp_id, $level_id, $group_id ]
	 */
	protected function make_group_pair() {
		$group_id   = self::factory()->term->create( array( 'taxonomy' => MES_Sponsor_Group::TAXONOMY_SLUG ) );
		$level_id   = self::factory()->post->create( array( 'post_type' => MES_Sponsorship_Level::POST_TYPE_SLUG ) );
		$sponsor_id = self::factory()->post->create( array( 'post_type' => MES_Sponsor::POST_TYPE_SLUG ) );

		update_post_meta( $sponsor_id, 'mes_group_sponsorships', array( $group_id => $level_id ) );

		$wordcamp_id = self::factory()->post->create( array( 'post_type' => 'wordcamp' ) );

		update_post_meta( $wordcamp_id, 'mes_sponsor_groups', array( $group_id ) );

		return array( $sponsor_id, $wordcamp_id, $level_id, $group_id );
	}

	/**
	 * Group match includes sponsor.
	 */
	public function test_group_match_includes_sponsor() {
		list( $sponsor_id, $wordcamp_id ) = $this->make_group_pair();

		$mes = new Multi_Event_Sponsors();
		$ids = wp_list_pluck( $mes->get_wordcamp_me_sponsors( $wordcamp_id ), 'ID' );

		$this->assertContains( $sponsor_id, $ids );
	}

	/**
	 * Group grouped by level sets level.
	 */
	public function test_group_grouped_by_level_sets_level() {
		list( , $wordcamp_id, $level_id ) = $this->make_group_pair();

		$mes      = new Multi_Event_Sponsors();
		$by_level = $mes->get_wordcamp_me_sponsors( $wordcamp_id, 'sponsor_level' );

		$this->assertArrayHasKey( $level_id, $by_level );
	}

	/**
	 * Group only sponsor appears without any region.
	 */
	public function test_group_only_sponsor_appears_without_any_region() {
		// The core à la carte case: no legacy region anywhere.
		list( $sponsor_id, $wordcamp_id ) = $this->make_group_pair();

		$this->assertSame( '', get_post_meta( $wordcamp_id, 'Multi-Event Sponsor Region', true ) );

		$mes = new Multi_Event_Sponsors();
		$ids = wp_list_pluck( $mes->get_wordcamp_me_sponsors( $wordcamp_id ), 'ID' );

		$this->assertContains( $sponsor_id, $ids );
	}

	/**
	 * Group match takes precedence over region.
	 */
	public function test_group_match_takes_precedence_over_region() {
		list( $sponsor_id, $wordcamp_id, $group_level_id, $group_id ) = $this->make_group_pair();

		// Same sponsor+camp also joined via region, mapping to a DIFFERENT level.
		$region_id       = self::factory()->term->create( array( 'taxonomy' => MES_Region::TAXONOMY_SLUG ) );
		$region_level_id = self::factory()->post->create( array( 'post_type' => MES_Sponsorship_Level::POST_TYPE_SLUG ) );

		update_post_meta( $sponsor_id, 'mes_regional_sponsorships', array( $region_id => $region_level_id ) );
		update_post_meta( $wordcamp_id, 'Multi-Event Sponsor Region', $region_id );

		$mes      = new Multi_Event_Sponsors();
		$by_level = $mes->get_wordcamp_me_sponsors( $wordcamp_id, 'sponsor_level' );

		$this->assertArrayHasKey( $group_level_id, $by_level );
		$this->assertArrayNotHasKey( $region_level_id, $by_level );
	}

	/**
	 * Multiple matching groups pick highest ordered level.
	 */
	public function test_multiple_matching_groups_pick_highest_ordered_level() {
		$group_a = self::factory()->term->create( array( 'taxonomy' => MES_Sponsor_Group::TAXONOMY_SLUG ) );
		$group_b = self::factory()->term->create( array( 'taxonomy' => MES_Sponsor_Group::TAXONOMY_SLUG ) );

		$low_level  = self::factory()->post->create( array(
			'post_type' => MES_Sponsorship_Level::POST_TYPE_SLUG, 'menu_order' => 1,
		) );
		$high_level = self::factory()->post->create( array(
			'post_type' => MES_Sponsorship_Level::POST_TYPE_SLUG, 'menu_order' => 9,
		) );

		$sponsor_id = self::factory()->post->create( array( 'post_type' => MES_Sponsor::POST_TYPE_SLUG ) );

		update_post_meta(
			$sponsor_id,
			'mes_group_sponsorships',
			array(
				$group_a => $low_level,
				$group_b => $high_level,
			)
		);

		$wordcamp_id = self::factory()->post->create( array( 'post_type' => 'wordcamp' ) );

		update_post_meta( $wordcamp_id, 'mes_sponsor_groups', array( $group_a, $group_b ) );

		$mes      = new Multi_Event_Sponsors();
		$by_level = $mes->get_wordcamp_me_sponsors( $wordcamp_id, 'sponsor_level' );

		$this->assertArrayHasKey( $high_level, $by_level );
		$this->assertArrayNotHasKey( $low_level, $by_level );
	}
}
