<?php

defined( 'WPINC' ) || die();

/**
 * Tests for the regions → groups migration.
 *
 * @group multi-event-sponsors
 * @group sponsor-groups
 */
class Test_MES_Migrate_Groups extends WP_UnitTestCase {
	/**
	 * Create a region + level + sponsor (mapped via the region) + camp (in the region).
	 *
	 * @return array [ $region_id, $level_id, $sponsor_id, $wordcamp_id ]
	 */
	protected function make_legacy_setup() {
		$region_id  = self::factory()->term->create( array(
			'taxonomy' => MES_Region::TAXONOMY_SLUG, 'name' => 'EU',
		) );
		$level_id   = self::factory()->post->create( array( 'post_type' => MES_Sponsorship_Level::POST_TYPE_SLUG ) );
		$sponsor_id = self::factory()->post->create( array( 'post_type' => MES_Sponsor::POST_TYPE_SLUG ) );

		update_post_meta( $sponsor_id, 'mes_regional_sponsorships', array( $region_id => $level_id ) );

		$wordcamp_id = self::factory()->post->create( array( 'post_type' => 'wordcamp' ) );

		update_post_meta( $wordcamp_id, 'Multi-Event Sponsor Region', $region_id );

		return array( $region_id, $level_id, $sponsor_id, $wordcamp_id );
	}

	/**
	 * Migration seeds groups and copies maps.
	 */
	public function test_migration_seeds_groups_and_copies_maps() {
		list( , $level_id, $sponsor_id, $wordcamp_id ) = $this->make_legacy_setup();

		$summary = MES_Migrate_Groups::run();

		$this->assertSame( 1, $summary['groups_created'] );
		$this->assertSame( 1, $summary['camps_assigned'] );
		$this->assertSame( 1, $summary['sponsors_migrated'] );

		// The camp now belongs to the migrated group, and the sponsor maps that group → level.
		$camp_groups = MES_Sponsor_Group::get_stored_camp_groups( $wordcamp_id );
		$this->assertCount( 1, $camp_groups );

		$group_id = $camp_groups[0];
		$this->assertSame( array( $group_id => $level_id ), MES_Sponsor::get_stored_group_sponsorships( $sponsor_id ) );

		// The migrated group keeps the region's name and records its origin.
		$group = get_term( $group_id, MES_Sponsor_Group::TAXONOMY_SLUG );
		$this->assertSame( 'EU', $group->name );
	}

	/**
	 * Migration is idempotent.
	 */
	public function test_migration_is_idempotent() {
		$this->make_legacy_setup();

		MES_Migrate_Groups::run();
		$again = MES_Migrate_Groups::run();

		$this->assertSame( 0, $again['groups_created'] );
		$this->assertSame( 0, $again['camps_assigned'] );
		$this->assertSame( 0, $again['sponsors_migrated'] );
	}

	/**
	 * Migrated join matches like the region did.
	 */
	public function test_migrated_join_matches_like_the_region_did() {
		// The whole point: after migration, the group path alone reproduces the
		// legacy placement, so the region meta could eventually be retired.
		list( , $level_id, $sponsor_id, $wordcamp_id ) = $this->make_legacy_setup();

		MES_Migrate_Groups::run();

		// Groups only count once the flag is on.
		add_filter( 'mes_sponsor_groups_enabled', '__return_true' );

		// Remove the legacy region signals entirely.
		delete_post_meta( $wordcamp_id, 'Multi-Event Sponsor Region' );
		delete_post_meta( $sponsor_id, 'mes_regional_sponsorships' );

		$mes      = new Multi_Event_Sponsors();
		$by_level = $mes->get_wordcamp_me_sponsors( $wordcamp_id, 'sponsor_level' );

		$this->assertArrayHasKey( $level_id, $by_level );
		$this->assertContains( $sponsor_id, wp_list_pluck( $by_level[ $level_id ], 'ID' ) );
	}

	/**
	 * Dry run counts without writing.
	 */
	public function test_dry_run_counts_without_writing() {
		list( , , $sponsor_id, $wordcamp_id ) = $this->make_legacy_setup();

		$summary = MES_Migrate_Groups::run( true );

		$this->assertSame( 1, $summary['groups_created'] );
		$this->assertSame( 1, $summary['camps_assigned'] );
		$this->assertSame( 1, $summary['sponsors_migrated'] );

		// Nothing was actually written.
		$created_groups = get_terms( array(
			'taxonomy'   => MES_Sponsor_Group::TAXONOMY_SLUG,
			'hide_empty' => false,
		) );

		$this->assertSame( array(), $created_groups );
		$this->assertSame( array(), MES_Sponsor_Group::get_stored_camp_groups( $wordcamp_id ) );
		$this->assertSame( array(), MES_Sponsor::get_stored_group_sponsorships( $sponsor_id ) );
	}

	/**
	 * Migration preserves existing group data.
	 */
	public function test_migration_preserves_existing_group_data() {
		list( $region_id, $level_id, $sponsor_id, $wordcamp_id ) = $this->make_legacy_setup();

		// The camp and sponsor already have hand-made group assignments.
		$custom_group = self::factory()->term->create( array( 'taxonomy' => MES_Sponsor_Group::TAXONOMY_SLUG ) );
		$custom_level = self::factory()->post->create( array( 'post_type' => MES_Sponsorship_Level::POST_TYPE_SLUG ) );

		update_post_meta( $wordcamp_id, 'mes_sponsor_groups', array( $custom_group ) );
		update_post_meta( $sponsor_id, 'mes_group_sponsorships', array( $custom_group => $custom_level ) );

		MES_Migrate_Groups::run();

		$camp_groups = MES_Sponsor_Group::get_stored_camp_groups( $wordcamp_id );
		$this->assertContains( $custom_group, $camp_groups );
		$this->assertCount( 2, $camp_groups );

		$map = MES_Sponsor::get_stored_group_sponsorships( $sponsor_id );
		$this->assertSame( $custom_level, $map[ $custom_group ] );
		$this->assertCount( 2, $map );
	}

	/**
	 * Running the migration while the flag is off changes nothing: later region edits still apply.
	 *
	 * The region screens are the only ones wranglers have until the flag is on, so groups mustn't
	 * quietly answer instead of them.
	 */
	public function test_region_edits_still_apply_after_migration_while_disabled() {
		list( $region_id, $level_id, $sponsor_id, $wordcamp_id ) = $this->make_legacy_setup();

		MES_Migrate_Groups::run();

		$new_level_id = self::factory()->post->create( array( 'post_type' => MES_Sponsorship_Level::POST_TYPE_SLUG ) );
		update_post_meta( $sponsor_id, 'mes_regional_sponsorships', array( $region_id => $new_level_id ) );

		$mes      = new Multi_Event_Sponsors();
		$by_level = $mes->get_wordcamp_me_sponsors( $wordcamp_id, 'sponsor_level' );

		$this->assertArrayHasKey( $new_level_id, $by_level );
		$this->assertArrayNotHasKey( $level_id, $by_level );
	}
}
