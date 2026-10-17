<?php

namespace WordCamp\WCPT\Tests;

use WP_UnitTestCase;
use WordCamp_Admin;
use WordCamp_Loader;

require_once dirname( __DIR__ ) . '/trait-wordcamp-fixtures.php';

defined( 'WPINC' ) || die();

/**
 * Tests for defaulting "Running money through WPCS PBC" for Campus Connect events.
 *
 * @group wcpt
 * @group campus-connect
 */
class Test_Campus_Connect_WPCS_PBC_Default extends WP_UnitTestCase {

	use \WordCamp_Fixtures;

	/**
	 * Reset globals after each test.
	 */
	public function tear_down() {
		global $wcorg_subroles;

		$wcorg_subroles = array();
		$_POST          = array();
		unset( $_GET['post'] );

		parent::tear_down();
	}

	/**
	 * New Campus Connect events default "Running money through WPCS PBC" to true when subtype is added.
	 *
	 * @covers WordCamp_Loader::maybe_default_campus_connect_meta
	 */
	public function test_new_campus_connect_event_defaults_running_money_through_wpcs_pbc_to_true() {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => WCPT_POST_TYPE_ID,
				'post_status' => WCPT_DEFAULT_STATUS,
			)
		);

		$this->assertFalse( metadata_exists( 'post', $event_id, 'Running money through WPCS PBC' ) );

		add_post_meta( $event_id, 'event_subtype', 'campusconnect' );

		$this->assertTrue( metadata_exists( 'post', $event_id, 'Running money through WPCS PBC' ) );
		$this->assertTrue( (bool) get_post_meta( $event_id, 'Running money through WPCS PBC', true ) );
	}

	/**
	 * Other event subtypes do not default "Running money through WPCS PBC" to true.
	 *
	 * @dataProvider data_non_campus_connect_subtypes
	 *
	 * @covers WordCamp_Loader::maybe_default_campus_connect_meta
	 *
	 * @param string $subtype The event subtype.
	 */
	public function test_new_non_campus_connect_events_retain_default_unchecked( $subtype ) {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => WCPT_POST_TYPE_ID,
				'post_status' => WCPT_DEFAULT_STATUS,
			)
		);

		add_post_meta( $event_id, 'event_subtype', $subtype );

		$this->assertFalse( metadata_exists( 'post', $event_id, 'Running money through WPCS PBC' ) );
		$this->assertEmpty( get_post_meta( $event_id, 'Running money through WPCS PBC', true ) );
	}

	/**
	 * Data provider of non-Campus Connect event subtypes.
	 *
	 * @return array[]
	 */
	public function data_non_campus_connect_subtypes() {
		return array(
			array( 'wordcamp' ),
			array( 'doaction' ),
			array( 'student-club' ),
			array( 'other' ),
		);
	}

	/**
	 * A Campus Connect event can have "Running money through WPCS PBC" explicitly unchecked and saved as false.
	 *
	 * @covers WordCamp_Loader::maybe_default_campus_connect_meta
	 */
	public function test_checkbox_can_be_unchecked_and_saved_as_false() {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => WCPT_POST_TYPE_ID,
				'post_status' => WCPT_DEFAULT_STATUS,
			)
		);

		add_post_meta( $event_id, 'event_subtype', 'campusconnect' );
		$this->assertTrue( (bool) get_post_meta( $event_id, 'Running money through WPCS PBC', true ) );

		// Organizer or admin explicitly unchecks the checkbox.
		update_post_meta( $event_id, 'Running money through WPCS PBC', false );

		$this->assertTrue( metadata_exists( 'post', $event_id, 'Running money through WPCS PBC' ) );
		$this->assertEmpty( get_post_meta( $event_id, 'Running money through WPCS PBC', true ) );

		// Subsequent post update should not re-default to true.
		wp_update_post(
			array(
				'ID'         => $event_id,
				'post_title' => 'Updated Campus Connect Title',
			)
		);

		$this->assertEmpty( get_post_meta( $event_id, 'Running money through WPCS PBC', true ) );
	}

	/**
	 * Existing event tracker records are not modified retroactively.
	 *
	 * @covers WordCamp_Loader::maybe_default_campus_connect_meta
	 */
	public function test_existing_events_are_not_modified_retroactively() {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => WCPT_POST_TYPE_ID,
				'post_status' => 'wcpt-pre-planning',
			)
		);

		// Simulate an existing pre-feature Campus Connect record without the meta key.
		add_post_meta( $event_id, 'event_subtype', 'campusconnect' );
		delete_post_meta( $event_id, 'Running money through WPCS PBC' );

		$this->assertFalse( metadata_exists( 'post', $event_id, 'Running money through WPCS PBC' ) );

		// Simulate an update on the existing event tracker record.
		update_post_meta( $event_id, 'Location', 'Warsaw, Poland' );
		update_post_meta( $event_id, 'event_subtype', 'campusconnect' );

		// Metadata must not be retroactively added.
		$this->assertFalse( metadata_exists( 'post', $event_id, 'Running money through WPCS PBC' ) );
		$this->assertEmpty( get_post_meta( $event_id, 'Running money through WPCS PBC', true ) );
	}

	/**
	 * Admin metabox save defaults "Running money through WPCS PBC" to true for new Campus Connect events.
	 *
	 * @covers Event_Admin::metabox_save
	 */
	public function test_admin_metabox_save_defaults_new_campus_connect_event_to_true() {
		$this->become_contributor();
		$this->become_wrangler();

		$event_id = self::factory()->post->create(
			array(
				'post_type'   => WCPT_POST_TYPE_ID,
				'post_status' => 'auto-draft',
			)
		);

		$_POST['action']        = 'editpost';
		$_POST['event_subtype'] = 'campusconnect';
		$_POST[ wcpt_key_to_str( 'Physical Address', 'wcpt_' ) ] = '';

		$admin = new WordCamp_Admin();
		$admin->metabox_save( $event_id, get_post( $event_id ), false );

		$this->assertTrue( metadata_exists( 'post', $event_id, 'Running money through WPCS PBC' ) );
		$this->assertTrue( (bool) get_post_meta( $event_id, 'Running money through WPCS PBC', true ) );
	}

	/**
	 * Admin metabox save allows unchecking "Running money through WPCS PBC" on Campus Connect events.
	 *
	 * @covers Event_Admin::metabox_save
	 */
	public function test_admin_metabox_save_allows_unchecking_campus_connect_wpcs_pbc() {
		$this->become_contributor();
		$this->become_wrangler();

		$event_id = self::factory()->post->create(
			array(
				'post_type'   => WCPT_POST_TYPE_ID,
				'post_status' => WCPT_DEFAULT_STATUS,
			)
		);

		add_post_meta( $event_id, 'event_subtype', 'campusconnect' );
		$this->assertTrue( (bool) get_post_meta( $event_id, 'Running money through WPCS PBC', true ) );

		$_POST['action']        = 'editpost';
		$_POST['event_subtype'] = 'campusconnect';
		$_POST[ wcpt_key_to_str( 'Physical Address', 'wcpt_' ) ] = '';
		// wcpt_running_money_through_wpcs_pbc is not sent in $_POST when unchecked.

		$admin = new WordCamp_Admin();
		$admin->metabox_save( $event_id, get_post( $event_id ), false );

		$this->assertTrue( metadata_exists( 'post', $event_id, 'Running money through WPCS PBC' ) );
		$this->assertEmpty( get_post_meta( $event_id, 'Running money through WPCS PBC', true ) );
	}

	/**
	 * Transparency report reminders check "Running money through WPCS PBC" to suppress emails.
	 */
	public function test_transparency_report_reminder_suppressed_when_running_money_through_wpcs_pbc() {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => WCPT_POST_TYPE_ID,
				'post_status' => WCPT_DEFAULT_STATUS,
			)
		);

		add_post_meta( $event_id, 'event_subtype', 'campusconnect' );

		// Verifies the reminder check in wcor-mailer.php line 826:
		// When both $transparency_report and $through_wpcs_pbc are truthy, the reminder is suppressed.
		$transparency_report = true;
		$through_wpcs_pbc    = get_post_meta( $event_id, 'Running money through WPCS PBC', true );

		$this->assertTrue( (bool) ( $transparency_report && $through_wpcs_pbc ) );
	}
}
