<?php

namespace WordCamp\WCPT\Tests;
use WP_UnitTestCase;
use WordCamp_Admin;

defined( 'WPINC' ) || die();

/**
 * @group wcpt
 */
class Test_WordCamp_Admin extends WP_UnitTestCase {

	/**
	 * @var WordCamp_Admin
	 */
	private static $admin;

	/**
	 * Set up the test class.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		self::$admin = new WordCamp_Admin();
	}

	/**
	 * Create a WordCamp post with the given status, bypassing transition hooks
	 * that would otherwise fail due to missing Slack notification dependencies.
	 *
	 * @param string $status The desired post status.
	 *
	 * @return int Post ID.
	 */
	private function create_wordcamp( $status = 'draft' ) {
		global $wpdb;

		// Create with draft status to avoid triggering transition hooks.
		$post_id = self::factory()->post->create( array(
			'post_type'   => WCPT_POST_TYPE_ID,
			'post_status' => 'draft',
		) );

		// Set the desired status directly in the database to avoid triggering hooks.
		if ( 'draft' !== $status ) {
			$wpdb->update(
				$wpdb->posts,
				array( 'post_status' => $status ),
				array( 'ID' => $post_id )
			);
			clean_post_cache( $post_id );
		}

		return $post_id;
	}

	/**
	 * @covers WordCamp_Admin::get_required_fields
	 */
	public function test_get_required_fields_closed_includes_actual_attendees() {
		$post_id = $this->create_wordcamp();

		$fields = WordCamp_Admin::get_required_fields( 'closed', $post_id );

		$this->assertContains( 'Actual Attendees', $fields );
	}

	/**
	 * @covers WordCamp_Admin::get_required_fields
	 */
	public function test_get_required_fields_closed_does_not_include_scheduled_fields() {
		$post_id = $this->create_wordcamp();

		$fields = WordCamp_Admin::get_required_fields( 'closed', $post_id );

		$this->assertNotContains( 'Start Date (YYYY-mm-dd)', $fields );
		$this->assertNotContains( 'Location', $fields );
	}

	/**
	 * Closing a WordCamp should be blocked when Actual Attendees is not filled.
	 *
	 * @covers WordCamp_Admin::require_complete_meta_to_publish_wordcamp
	 */
	public function test_closing_blocked_without_actual_attendees() {
		$post_id = $this->create_wordcamp( 'wcpt-scheduled' );

		// Simulate no Actual Attendees in POST data.
		$_POST = array();

		$post_data = array(
			'post_type'   => WCPT_POST_TYPE_ID,
			'post_status' => 'wcpt-closed',
		);

		$post_data_raw = array(
			'ID' => $post_id,
		);

		$result = self::$admin->require_complete_meta_to_publish_wordcamp( $post_data, $post_data_raw );

		$this->assertNotEquals( 'wcpt-closed', $result['post_status'], 'Status should not be wcpt-closed when Actual Attendees is missing.' );
		$this->assertEquals( 'wcpt-scheduled', $result['post_status'], 'Status should revert to the previous status.' );
	}

	/**
	 * Closing a WordCamp should succeed when Actual Attendees is filled.
	 *
	 * @covers WordCamp_Admin::require_complete_meta_to_publish_wordcamp
	 */
	public function test_closing_allowed_with_actual_attendees() {
		$post_id = $this->create_wordcamp( 'wcpt-scheduled' );

		// Simulate Actual Attendees in POST data.
		$_POST = array(
			'wcpt_actual_attendees' => '150',
		);

		$post_data = array(
			'post_type'   => WCPT_POST_TYPE_ID,
			'post_status' => 'wcpt-closed',
		);

		$post_data_raw = array(
			'ID' => $post_id,
		);

		$result = self::$admin->require_complete_meta_to_publish_wordcamp( $post_data, $post_data_raw );

		$this->assertEquals( 'wcpt-closed', $result['post_status'], 'Status should be wcpt-closed when Actual Attendees is provided.' );
	}

	/**
	 * Resaving an already-closed WordCamp should not require re-validation.
	 *
	 * @covers WordCamp_Admin::require_complete_meta_to_publish_wordcamp
	 */
	public function test_resaving_closed_wordcamp_allowed() {
		$post_id = $this->create_wordcamp( 'wcpt-closed' );

		// Simulate no Actual Attendees in POST data (e.g. field wasn't re-submitted).
		$_POST = array();

		$post_data = array(
			'post_type'   => WCPT_POST_TYPE_ID,
			'post_status' => 'wcpt-closed',
		);

		$post_data_raw = array(
			'ID' => $post_id,
		);

		$result = self::$admin->require_complete_meta_to_publish_wordcamp( $post_data, $post_data_raw );

		$this->assertEquals( 'wcpt-closed', $result['post_status'], 'Status should remain wcpt-closed when resaving an already-closed WordCamp.' );
	}

	/**
	 * Non-WordCamp post types should pass through without validation.
	 *
	 * @covers WordCamp_Admin::require_complete_meta_to_publish_wordcamp
	 */
	public function test_non_wordcamp_post_type_passes_through() {
		$post_data = array(
			'post_type'   => 'post',
			'post_status' => 'wcpt-closed',
		);

		$post_data_raw = array(
			'ID' => 1,
		);

		$result = self::$admin->require_complete_meta_to_publish_wordcamp( $post_data, $post_data_raw );

		$this->assertEquals( $post_data, $result, 'Non-WordCamp post data should not be modified.' );
	}

	/**
	 * Actual Attendees should be protected before the event end date passes.
	 *
	 * @covers WordCamp_Admin::get_protected_fields
	 */
	public function test_actual_attendees_protected_before_end_date() {
		$post_id = $this->create_wordcamp();

		// Set end date to the future.
		update_post_meta( $post_id, 'End Date (YYYY-mm-dd)', strtotime( '+30 days' ) );

		// get_protected_fields() uses get_post() which relies on the global $post.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Required to set context for get_protected_fields().
		$GLOBALS['post'] = get_post( $post_id );

		$protected = WordCamp_Admin::get_protected_fields();

		$this->assertContains( 'Actual Attendees', $protected, 'Actual Attendees should be protected before the event ends.' );

		// Clean up.
		unset( $GLOBALS['post'] );
	}

	/**
	 * Actual Attendees should not be protected after the event end date passes.
	 *
	 * @covers WordCamp_Admin::get_protected_fields
	 */
	public function test_actual_attendees_not_protected_after_end_date() {
		$post_id = $this->create_wordcamp();

		// Set end date to the past.
		update_post_meta( $post_id, 'End Date (YYYY-mm-dd)', strtotime( '-30 days' ) );

		// get_protected_fields() uses get_post() which relies on the global $post.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Required to set context for get_protected_fields().
		$GLOBALS['post'] = get_post( $post_id );

		$protected = WordCamp_Admin::get_protected_fields();

		$this->assertNotContains( 'Actual Attendees', $protected, 'Actual Attendees should not be protected after the event ends.' );

		// Clean up.
		unset( $GLOBALS['post'] );
	}

	/**
	 * When no end date is set, start date should be used for protection.
	 *
	 * @covers WordCamp_Admin::get_protected_fields
	 */
	public function test_actual_attendees_uses_start_date_when_no_end_date() {
		$post_id = $this->create_wordcamp();

		// Set only start date in the future, no end date.
		update_post_meta( $post_id, 'Start Date (YYYY-mm-dd)', strtotime( '+30 days' ) );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Required to set context for get_protected_fields().
		$GLOBALS['post'] = get_post( $post_id );

		$protected = WordCamp_Admin::get_protected_fields();

		$this->assertContains( 'Actual Attendees', $protected, 'Actual Attendees should be protected using start date when no end date is set.' );

		// Clean up.
		unset( $GLOBALS['post'] );
	}

	/**
	 * Closing should store missing fields in a transient.
	 *
	 * @covers WordCamp_Admin::require_complete_meta_to_publish_wordcamp
	 */
	public function test_closing_stores_missing_fields_transient() {
		$post_id = $this->create_wordcamp( 'wcpt-scheduled' );

		$_POST = array();

		$post_data = array(
			'post_type'   => WCPT_POST_TYPE_ID,
			'post_status' => 'wcpt-closed',
		);

		$post_data_raw = array(
			'ID' => $post_id,
		);

		self::$admin->require_complete_meta_to_publish_wordcamp( $post_data, $post_data_raw );

		$transient = get_transient( 'wcpt_missing_fields_' . $post_id );

		$this->assertNotEmpty( $transient, 'Missing fields transient should be set.' );
		$this->assertContains( 'Actual Attendees', $transient, 'Transient should contain Actual Attendees.' );
	}

	/**
	 * Clean up $_POST after each test.
	 */
	public function tear_down() {
		$_POST = array();

		parent::tear_down();
	}
}
