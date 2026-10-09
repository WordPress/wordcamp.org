<?php

namespace WordCamp\WCPT\Tests;

use MES_Region;
use MES_Sponsor_Group;
use WP_UnitTestCase;

require_once dirname( __DIR__ ) . '/trait-wordcamp-fixtures.php';

defined( 'WPINC' ) || die();

/**
 * Tests for the metadata a WordCamp needs before it can be added to the schedule.
 *
 * @group wcpt
 */
class Test_WordCamp_Scheduled_Meta extends WP_UnitTestCase {

	use \WordCamp_Fixtures;

	/**
	 * The camp being scheduled.
	 *
	 * @var int
	 */
	protected $camp;

	/**
	 * Create a camp as its organizer, then switch to a wrangler, who can schedule it.
	 */
	public function set_up() {
		parent::set_up();

		global $wcorg_subroles;

		$wcorg_subroles = array();
		$this->camp     = $this->create_wordcamp( $this->become_contributor() );

		$this->become_wrangler();

		// The rule only applies above the site ID it went live for, which no factory post reaches.
		add_filter( 'wcpt_require_complete_meta_min_site_id', '__return_zero' );
	}

	/**
	 * Leave the globals clean for whatever runs next.
	 */
	public function tear_down() {
		global $wcorg_subroles;

		$wcorg_subroles = array();
		$_POST          = array();

		parent::tear_down();
	}

	/**
	 * Post every field scheduling requires, except the sponsor region, the way the WordCamp screen does.
	 */
	protected function post_every_scheduled_field_but_the_region() {
		foreach ( \WordCamp_Admin::get_required_fields( 'scheduled', $this->camp ) as $field ) {
			if ( 'Multi-Event Sponsor Region' !== $field ) {
				$_POST[ wcpt_key_to_str( $field, 'wcpt_' ) ] = 'filled in';
			}
		}
	}

	/**
	 * Ask for the camp to be scheduled, and return the status it ends up in.
	 *
	 * @return string
	 */
	protected function schedule() {
		wp_update_post(
			array(
				'ID'          => $this->camp,
				'post_status' => 'wcpt-scheduled',
			)
		);

		return get_post_status( $this->camp );
	}

	/**
	 * A camp with every field, including a sponsor region, can be scheduled.
	 *
	 * @covers WordCamp_Admin::require_complete_meta_to_publish_wordcamp
	 */
	public function test_a_region_camp_with_every_field_can_be_scheduled() {
		$this->post_every_scheduled_field_but_the_region();
		$_POST['wcpt_multi-event_sponsor_region'] = (string) self::factory()->term->create( array( 'taxonomy' => MES_Region::TAXONOMY_SLUG ) );

		$this->assertSame( 'wcpt-scheduled', $this->schedule() );
	}

	/**
	 * A camp with neither a sponsor region nor a group can't be scheduled, since it would get no sponsors.
	 *
	 * @covers WordCamp_Admin::require_complete_meta_to_publish_wordcamp
	 */
	public function test_a_camp_with_no_region_or_group_is_not_scheduled() {
		add_filter( 'mes_sponsor_groups_enabled', '__return_true' );

		$this->post_every_scheduled_field_but_the_region();
		$_POST['wcpt_multi-event_sponsor_region'] = '0';

		$this->assertSame( 'wcpt-needs-schedule', $this->schedule() );
	}

	/**
	 * While groups are on, a sponsor group stands in for the region, as it does for creating the site.
	 *
	 * @covers WordCamp_Admin::require_complete_meta_to_publish_wordcamp
	 */
	public function test_a_group_only_camp_can_be_scheduled_while_groups_are_enabled() {
		add_filter( 'mes_sponsor_groups_enabled', '__return_true' );

		$this->post_every_scheduled_field_but_the_region();
		$_POST['wcpt_multi-event_sponsor_region'] = '0';
		$_POST['wcpt_multi-event_sponsor_groups'] = array( (string) self::factory()->term->create( array( 'taxonomy' => MES_Sponsor_Group::TAXONOMY_SLUG ) ) );

		$this->assertSame( 'wcpt-scheduled', $this->schedule() );
	}

	/**
	 * While groups are off, a group doesn't count, so the region is still required.
	 *
	 * @covers WordCamp_Admin::require_complete_meta_to_publish_wordcamp
	 */
	public function test_a_group_only_camp_is_not_scheduled_while_groups_are_disabled() {
		$this->post_every_scheduled_field_but_the_region();
		$_POST['wcpt_multi-event_sponsor_region'] = '0';
		$_POST['wcpt_multi-event_sponsor_groups'] = array( (string) self::factory()->term->create( array( 'taxonomy' => MES_Sponsor_Group::TAXONOMY_SLUG ) ) );

		$this->assertSame( 'wcpt-needs-schedule', $this->schedule() );
	}

	/**
	 * Put the camp in the schedule directly, as if a wrangler had scheduled it earlier.
	 */
	protected function mark_scheduled() {
		global $wpdb;

		$wpdb->update( $wpdb->posts, array( 'post_status' => 'wcpt-scheduled' ), array( 'ID' => $this->camp ) );
		clean_post_cache( $this->camp );
	}

	/**
	 * Someone who can't change the groups (a mentor) gets a disabled picker that posts nothing, so the
	 * saved groups count for them. Otherwise their save would take a group-only camp off the schedule.
	 *
	 * @covers WordCamp_Admin::require_complete_meta_to_publish_wordcamp
	 */
	public function test_a_saved_group_keeps_the_camp_scheduled_when_the_user_cannot_change_groups() {
		add_filter( 'mes_sponsor_groups_enabled', '__return_true' );

		update_post_meta( $this->camp, MES_Sponsor_Group::CAMP_META_KEY, array( self::factory()->term->create( array( 'taxonomy' => MES_Sponsor_Group::TAXONOMY_SLUG ) ) ) );
		$this->mark_scheduled();
		$this->become_contributor();

		$this->post_every_scheduled_field_but_the_region();
		$_POST['wcpt_multi-event_sponsor_region'] = '0';

		$this->assertSame( 'wcpt-scheduled', $this->schedule() );
	}

	/**
	 * Groups posted by someone who can't change them don't count: they'd never be saved.
	 *
	 * @covers WordCamp_Admin::require_complete_meta_to_publish_wordcamp
	 */
	public function test_posted_groups_do_not_count_when_the_user_cannot_change_groups() {
		add_filter( 'mes_sponsor_groups_enabled', '__return_true' );

		$this->mark_scheduled();
		$this->become_contributor();

		$this->post_every_scheduled_field_but_the_region();
		$_POST['wcpt_multi-event_sponsor_region'] = '0';
		$_POST['wcpt_multi-event_sponsor_groups'] = array( (string) self::factory()->term->create( array( 'taxonomy' => MES_Sponsor_Group::TAXONOMY_SLUG ) ) );

		$this->assertSame( 'wcpt-needs-schedule', $this->schedule() );
	}

	/**
	 * A posted group ID that isn't a sponsor group doesn't stand in for the region.
	 *
	 * @covers WordCamp_Admin::require_complete_meta_to_publish_wordcamp
	 */
	public function test_a_made_up_group_id_does_not_schedule_the_camp() {
		add_filter( 'mes_sponsor_groups_enabled', '__return_true' );

		$this->post_every_scheduled_field_but_the_region();
		$_POST['wcpt_multi-event_sponsor_region'] = '0';
		$_POST['wcpt_multi-event_sponsor_groups'] = array( '999999' );

		$this->assertSame( 'wcpt-needs-schedule', $this->schedule() );
	}
}
