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
	 * Create a WordCamp whose event has already happened.
	 *
	 * The attendance requirement only applies once there is attendance to report, so a camp
	 * with no dates is exempt from it. Every test that expects a refusal needs one of these.
	 *
	 * @param string $status The desired post status.
	 *
	 * @return int Post ID.
	 */
	private function create_past_wordcamp( $status = 'wcpt-scheduled' ) {
		$post_id = $this->create_wordcamp( $status );

		update_post_meta( $post_id, 'End Date (YYYY-mm-dd)', strtotime( '-7 days' ) );

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
		$post_id = $this->create_past_wordcamp();

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
		$post_id = $this->create_past_wordcamp();

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
		$post_id = $this->create_past_wordcamp();

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
	 * A recorded zero is a count, not a gap.
	 *
	 * `empty( '0' )` is true in PHP, so a check written with `empty()` refuses an event that
	 * nobody attended while accepting the equivalent '0.0'.
	 *
	 * @covers WordCamp_Admin::require_complete_meta_to_publish_wordcamp
	 */
	public function test_recorded_zero_is_a_count() {
		$post_id = $this->create_past_wordcamp();

		$_POST = array(
			'wcpt_actual_attendees' => '0',
		);

		$post_data = array(
			'post_type'   => WCPT_POST_TYPE_ID,
			'post_status' => 'wcpt-closed',
		);

		$result = self::$admin->require_complete_meta_to_publish_wordcamp( $post_data, array( 'ID' => $post_id ) );

		$this->assertEquals( 'wcpt-closed', $result['post_status'], 'An event nobody attended should still close.' );
	}

	/**
	 * A stored count satisfies the requirement when the form posts nothing.
	 *
	 * The field is disabled until the event has ended, and a disabled input posts nothing, so a
	 * save that changes something else must not read the attendance as missing.
	 *
	 * @covers WordCamp_Admin::require_complete_meta_to_publish_wordcamp
	 */
	public function test_stored_meta_satisfies_the_requirement_when_the_form_posts_nothing() {
		$post_id = $this->create_past_wordcamp();

		update_post_meta( $post_id, 'Actual Attendees', '120' );

		$_POST = array();

		$post_data = array(
			'post_type'   => WCPT_POST_TYPE_ID,
			'post_status' => 'wcpt-closed',
		);

		$result = self::$admin->require_complete_meta_to_publish_wordcamp( $post_data, array( 'ID' => $post_id ) );

		$this->assertEquals( 'wcpt-closed', $result['post_status'], 'A stored count should satisfy the requirement.' );
	}

	/**
	 * Cron closes an event whose attendance was never recorded.
	 *
	 * `close_wordcamps_after_event()` closes every scheduled camp whose end date has passed, with
	 * no POST data of any kind. Validating there would wedge the event in `wcpt-scheduled` on
	 * every run, for ever, and silently.
	 *
	 * @covers WordCamp_Admin::require_complete_meta_to_publish_wordcamp
	 */
	public function test_cron_closes_an_event_with_no_count() {
		$post_id = $this->create_past_wordcamp();

		$_POST = array();

		add_filter( 'wp_doing_cron', '__return_true' );

		$post_data = array(
			'post_type'   => WCPT_POST_TYPE_ID,
			'post_status' => 'wcpt-closed',
		);

		$result = self::$admin->require_complete_meta_to_publish_wordcamp( $post_data, array( 'ID' => $post_id ) );

		remove_filter( 'wp_doing_cron', '__return_true' );

		$this->assertEquals( 'wcpt-closed', $result['post_status'], 'Cron should close an event with no recorded attendance.' );
	}

	/**
	 * Cron closes an event that already holds a count.
	 *
	 * Worth its own test: the validator reads `$_POST` first, which is empty under cron, so an
	 * event with a recorded number was blocked too before the fallback and the exemption.
	 *
	 * @covers WordCamp_Admin::require_complete_meta_to_publish_wordcamp
	 */
	public function test_cron_closes_an_event_that_already_has_a_count() {
		$post_id = $this->create_past_wordcamp();

		update_post_meta( $post_id, 'Actual Attendees', '95' );

		$_POST = array();

		add_filter( 'wp_doing_cron', '__return_true' );

		$post_data = array(
			'post_type'   => WCPT_POST_TYPE_ID,
			'post_status' => 'wcpt-closed',
		);

		$result = self::$admin->require_complete_meta_to_publish_wordcamp( $post_data, array( 'ID' => $post_id ) );

		remove_filter( 'wp_doing_cron', '__return_true' );

		$this->assertEquals( 'wcpt-closed', $result['post_status'], 'Cron should close an event that already has a count.' );
	}

	/**
	 * A blank attendance box must not record zero.
	 *
	 * This is the defect the first version of this branch shipped. With the field visible,
	 * `Event_Admin::metabox_save()` reached it on every save and `floatval( '' )` is 0.0, so an
	 * unrelated edit wrote `Actual Attendees = 0`. The requirement then passed on a figure
	 * nobody typed, and the event closed recording no attendees. A blank can be chased; a
	 * fabricated zero cannot be told apart from an event nobody came to.
	 *
	 * @covers Event_Admin::metabox_save
	 */
	public function test_a_blank_attendance_box_does_not_record_zero() {
		$post_id = $this->create_past_wordcamp();

		$_POST = array(
			'action'                => 'editpost',
			'wcpt_actual_attendees' => '',
		);

		self::$admin->metabox_save( $post_id, get_post( $post_id ), false );

		$this->assertSame(
			'',
			get_post_meta( $post_id, 'Actual Attendees', true ),
			'A blank attendance box should leave no value, not a zero.'
		);
	}

	/**
	 * A figure that was typed in is still saved.
	 *
	 * The guard above must not stop a real number reaching the database.
	 *
	 * @covers Event_Admin::metabox_save
	 */
	public function test_a_typed_attendance_figure_is_saved() {
		$post_id = $this->create_past_wordcamp();

		$_POST = array(
			'action'                => 'editpost',
			'wcpt_actual_attendees' => '87',
		);

		self::$admin->metabox_save( $post_id, get_post( $post_id ), false );

		$this->assertEquals( 87, get_post_meta( $post_id, 'Actual Attendees', true ), 'A typed figure should be saved.' );
	}

	/**
	 * An event that has not happened yet is exempt from the requirement.
	 *
	 * Nobody can report attendance for an event still ahead, and the box is read-only until the
	 * end date passes, so requiring it earlier refuses the close and then names a field the
	 * screen will not let anyone fill in.
	 *
	 * @covers WordCamp_Admin::require_complete_meta_to_publish_wordcamp
	 */
	public function test_an_event_still_ahead_is_exempt() {
		$post_id = $this->create_wordcamp( 'wcpt-scheduled' );

		update_post_meta( $post_id, 'End Date (YYYY-mm-dd)', strtotime( '+30 days' ) );

		$_POST = array();

		$post_data = array(
			'post_type'   => WCPT_POST_TYPE_ID,
			'post_status' => 'wcpt-closed',
		);

		$result = self::$admin->require_complete_meta_to_publish_wordcamp( $post_data, array( 'ID' => $post_id ) );

		$this->assertEquals( 'wcpt-closed', $result['post_status'], 'An event that has not happened should close without a count.' );
	}

	/**
	 * A camp with no dates at all can still be closed.
	 *
	 * Cron skips a camp with no start date, so one stranded in Cancelled or On Hold with dates
	 * never filled in would otherwise have no way to reach Closed at all.
	 *
	 * @covers WordCamp_Admin::require_complete_meta_to_publish_wordcamp
	 */
	public function test_a_camp_with_no_dates_can_be_closed() {
		$post_id = $this->create_wordcamp( 'wcpt-scheduled' );

		$_POST = array();

		$post_data = array(
			'post_type'   => WCPT_POST_TYPE_ID,
			'post_status' => 'wcpt-closed',
		);

		$result = self::$admin->require_complete_meta_to_publish_wordcamp( $post_data, array( 'ID' => $post_id ) );

		$this->assertEquals( 'wcpt-closed', $result['post_status'], 'A camp with no dates should not be stranded.' );
	}

	/**
	 * Clean up $_POST after each test.
	 */
	public function tear_down() {
		$_POST = array();

		parent::tear_down();
	}
}
