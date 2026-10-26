<?php

namespace WordCamp\WCPT\Tests;

use MES_Region;
use WP_UnitTestCase;
use WordCamp_Admin;

require_once dirname( __DIR__ ) . '/trait-wordcamp-fixtures.php';

defined( 'WPINC' ) || die();

/**
 * Tests for the Number of venues field on Campus Connect events.
 *
 * @group wcpt
 * @group campus-connect
 */
class Test_Campus_Connect_Number_Of_Venues extends WP_UnitTestCase {

	use \WordCamp_Fixtures;

	/**
	 * Event being tested.
	 *
	 * @var int
	 */
	protected $event_id;

	/**
	 * Set up test environment and create an event.
	 */
	public function set_up() {
		parent::set_up();

		global $wcorg_subroles;

		$wcorg_subroles = array();
		$this->event_id = $this->create_wordcamp( $this->become_contributor() );

		$this->become_wrangler();

		// The rule only applies above the site ID it went live for, which no factory post reaches.
		add_filter( 'wcpt_require_complete_meta_min_site_id', '__return_zero' );
	}

	/**
	 * Clean up globals.
	 */
	public function tear_down() {
		global $wcorg_subroles;

		$wcorg_subroles = array();
		$_POST          = array();

		parent::tear_down();
	}

	/**
	 * Verify that Number of venues is registered in venue and all meta keys.
	 *
	 * @covers WordCamp_Admin::meta_keys
	 */
	public function test_number_of_venues_is_registered_in_meta_keys() {
		$venue_keys = WordCamp_Admin::meta_keys( 'venue' );
		$all_keys   = WordCamp_Admin::meta_keys( 'all' );

		$this->assertArrayHasKey( 'Number of venues', $venue_keys );
		$this->assertSame( 'number', $venue_keys['Number of venues'] );

		$this->assertArrayHasKey( 'Number of venues', $all_keys );
		$this->assertSame( 'number', $all_keys['Number of venues'] );
	}

	/**
	 * Verify that Number of venues is required for scheduled Campus Connect events.
	 *
	 * @covers WordCamp_Admin::get_required_fields
	 */
	public function test_number_of_venues_required_for_campus_connect_scheduled() {
		update_post_meta( $this->event_id, 'event_subtype', 'campusconnect' );

		$required_scheduled = WordCamp_Admin::get_required_fields( 'scheduled', $this->event_id );
		$required_any       = WordCamp_Admin::get_required_fields( 'any', $this->event_id );

		$this->assertContains( 'Number of venues', $required_scheduled );
		$this->assertContains( 'Number of venues', $required_any );
	}

	/**
	 * Verify that Number of venues is not required for standard WordCamps.
	 *
	 * @covers WordCamp_Admin::get_required_fields
	 */
	public function test_number_of_venues_not_required_for_regular_wordcamp() {
		update_post_meta( $this->event_id, 'event_subtype', 'wordcamp' );

		$required_scheduled = WordCamp_Admin::get_required_fields( 'scheduled', $this->event_id );

		$this->assertNotContains( 'Number of venues', $required_scheduled );
	}

	/**
	 * Verify that Number of venues is not required for virtual Campus Connect events.
	 *
	 * @covers WordCamp_Admin::get_required_fields
	 */
	public function test_number_of_venues_not_required_for_virtual_campus_connect() {
		update_post_meta( $this->event_id, 'event_subtype', 'campusconnect' );
		update_post_meta( $this->event_id, 'Virtual event only', '1' );

		$required_scheduled = WordCamp_Admin::get_required_fields( 'scheduled', $this->event_id );

		$this->assertNotContains( 'Number of venues', $required_scheduled );
	}

	/**
	 * Populate required fields for scheduling a camp.
	 */
	protected function post_required_fields() {
		foreach ( WordCamp_Admin::get_required_fields( 'scheduled', $this->event_id ) as $field ) {
			if ( 'Multi-Event Sponsor Region' !== $field ) {
				$_POST[ wcpt_key_to_str( $field, 'wcpt_' ) ] = 'filled in';
			}
		}

		$_POST['wcpt_multi-event_sponsor_region'] = (string) self::factory()->term->create(
			array( 'taxonomy' => MES_Region::TAXONOMY_SLUG )
		);
	}

	/**
	 * Attempt to transition the event to scheduled.
	 *
	 * @return string The resulting status.
	 */
	protected function schedule_event() {
		wp_update_post(
			array(
				'ID'          => $this->event_id,
				'post_status' => 'wcpt-scheduled',
			)
		);

		return get_post_status( $this->event_id );
	}

	/**
	 * Campus Connect events cannot be scheduled when Number of venues is missing.
	 *
	 * @covers WordCamp_Admin::require_complete_meta_to_publish_wordcamp
	 */
	public function test_campus_connect_cannot_be_scheduled_without_number_of_venues() {
		update_post_meta( $this->event_id, 'event_subtype', 'campusconnect' );
		$_POST['event_subtype'] = 'campusconnect';

		$this->post_required_fields();
		unset( $_POST['wcpt_number_of_venues'] );

		// Falls back to Approved for Pre-Planning on validation failure for Campus Connect.
		$this->assertSame( 'wcpt-approved-pre-pl', $this->schedule_event() );
	}

	/**
	 * Campus Connect events can be scheduled when Number of venues is provided.
	 *
	 * @covers WordCamp_Admin::require_complete_meta_to_publish_wordcamp
	 */
	public function test_campus_connect_can_be_scheduled_with_number_of_venues() {
		update_post_meta( $this->event_id, 'event_subtype', 'campusconnect' );
		$_POST['event_subtype'] = 'campusconnect';

		$this->post_required_fields();
		$_POST['wcpt_number_of_venues'] = '3';

		$this->assertSame( 'wcpt-scheduled', $this->schedule_event() );
	}

	/**
	 * Saving Number of venues sanitizes to a positive integer or deletes when empty.
	 *
	 * @covers Event_Admin::metabox_save
	 */
	public function test_saving_number_of_venues_sanitization() {
		$admin           = $GLOBALS['wordcamp_admin'];
		$_POST['action'] = 'editpost';

		// Valid positive integer.
		$_POST['wcpt_number_of_venues'] = '5';
		$admin->metabox_save( $this->event_id, get_post( $this->event_id ), false );
		$this->assertSame( 5, (int) get_post_meta( $this->event_id, 'Number of venues', true ) );

		// Empty string deletes meta.
		$_POST['wcpt_number_of_venues'] = '';
		$admin->metabox_save( $this->event_id, get_post( $this->event_id ), false );
		$this->assertSame( '', get_post_meta( $this->event_id, 'Number of venues', true ) );

		// Zero or negative deletes meta.
		$_POST['wcpt_number_of_venues'] = '0';
		$admin->metabox_save( $this->event_id, get_post( $this->event_id ), false );
		$this->assertSame( '', get_post_meta( $this->event_id, 'Number of venues', true ) );
	}
}
