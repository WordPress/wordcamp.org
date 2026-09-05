<?php

defined( 'WPINC' ) || die();

/**
 * Tests for seeding the operational "Flagships" sponsor group.
 *
 * @group multi-event-sponsors
 * @group sponsor-groups
 */
class Test_MES_Flagships extends WP_UnitTestCase {
	/**
	 * Create a camp with a URL meta value.
	 *
	 * @param string $url
	 *
	 * @return int WordCamp post ID.
	 */
	protected function make_camp( $url ) {
		$wordcamp_id = self::factory()->post->create( array( 'post_type' => 'wordcamp' ) );

		update_post_meta( $wordcamp_id, 'URL', $url );

		return $wordcamp_id;
	}

	/**
	 * Seed creates group and assigns flagship camps.
	 */
	public function test_seed_creates_group_and_assigns_flagship_camps() {
		$us     = $this->make_camp( 'https://us.wordcamp.org/2026/' );
		$europe = $this->make_camp( 'https://europe.wordcamp.org/2026/' );
		$local  = $this->make_camp( 'https://belgrade.wordcamp.org/2026/' );

		$summary = MES_Flagships::seed();

		$this->assertTrue( $summary['group_created'] );
		$this->assertSame( 2, $summary['camps_matched'] );
		$this->assertSame( 2, $summary['camps_assigned'] );

		$us_groups = MES_Sponsor_Group::get_stored_camp_groups( $us );
		$this->assertCount( 1, $us_groups );

		$group = get_term( $us_groups[0], MES_Sponsor_Group::TAXONOMY_SLUG );
		$this->assertSame( 'Flagships', $group->name );

		$this->assertSame( $us_groups, MES_Sponsor_Group::get_stored_camp_groups( $europe ) );
		$this->assertSame( array(), MES_Sponsor_Group::get_stored_camp_groups( $local ) );
	}

	/**
	 * Seed is idempotent.
	 */
	public function test_seed_is_idempotent() {
		$this->make_camp( 'https://us.wordcamp.org/2026/' );

		MES_Flagships::seed();
		$again = MES_Flagships::seed();

		$this->assertFalse( $again['group_created'] );
		$this->assertSame( 0, $again['camps_assigned'] );
		$this->assertSame( 1, $again['camps_matched'] );
	}

	/**
	 * Seed dry run counts without writing.
	 */
	public function test_seed_dry_run_counts_without_writing() {
		$us = $this->make_camp( 'https://us.wordcamp.org/2026/' );

		$summary = MES_Flagships::seed( array(), true );

		$this->assertTrue( $summary['group_created'] );
		$this->assertSame( 1, $summary['camps_assigned'] );

		$remaining_groups = get_terms( array(
			'taxonomy'   => MES_Sponsor_Group::TAXONOMY_SLUG,
			'hide_empty' => false,
		) );

		$this->assertSame( array(), $remaining_groups );
		$this->assertSame( array(), MES_Sponsor_Group::get_stored_camp_groups( $us ) );
	}

	/**
	 * Seed respects custom domains.
	 */
	public function test_seed_respects_custom_domains() {
		$asia   = $this->make_camp( 'https://asia.wordcamp.org/2026/' );
		$centro = $this->make_camp( 'https://centroamerica.wordcamp.org/2026/' );

		$summary = MES_Flagships::seed( array( 'centroamerica' ) );

		$this->assertSame( 1, $summary['camps_assigned'] );
		$this->assertNotEmpty( MES_Sponsor_Group::get_stored_camp_groups( $centro ) );
		$this->assertSame( array(), MES_Sponsor_Group::get_stored_camp_groups( $asia ) );
	}

	/**
	 * Flagship targeted sponsor distributes via join.
	 */
	public function test_flagship_targeted_sponsor_distributes_via_join() {
		// The end-to-end point of AC3: a "flagships only" sponsor reaches exactly
		// the flagship camps through the group-aware join.
		$us    = $this->make_camp( 'https://us.wordcamp.org/2026/' );
		$local = $this->make_camp( 'https://belgrade.wordcamp.org/2026/' );

		MES_Flagships::seed();

		$group_id = MES_Sponsor_Group::get_stored_camp_groups( $us )[0];
		$level_id = self::factory()->post->create( array( 'post_type' => MES_Sponsorship_Level::POST_TYPE_SLUG ) );

		$sponsor_id = self::factory()->post->create( array( 'post_type' => MES_Sponsor::POST_TYPE_SLUG ) );
		update_post_meta( $sponsor_id, 'mes_group_sponsorships', array( $group_id => $level_id ) );

		// Groups only count once the flag is on.
		add_filter( 'mes_sponsor_groups_enabled', '__return_true' );

		$mes = new Multi_Event_Sponsors();

		$this->assertContains( $sponsor_id, wp_list_pluck( $mes->get_wordcamp_me_sponsors( $us ), 'ID' ) );
		$this->assertEmpty( $mes->get_wordcamp_me_sponsors( $local ) );
	}
}
