<?php

namespace WordCamp\WCPT\Tests;

use WP_REST_Request;
use WP_UnitTestCase;

require_once dirname( __DIR__ ) . '/trait-wordcamp-fixtures.php';

defined( 'WPINC' ) || die();

/**
 * Tests for the rules about which status a WordCamp application may be written to.
 *
 * @group wcpt
 */
class Test_WordCamp_Status_Guard extends WP_UnitTestCase {

	use \WordCamp_Fixtures;

	/**
	 * An application waiting to be vetted.
	 *
	 * @var int
	 */
	protected $camp;

	/**
	 * The lead organizer who authored it.
	 *
	 * @var int
	 */
	protected $organizer;

	/**
	 * Reset the subroles global and create an unvetted application.
	 */
	public function set_up() {
		parent::set_up();

		global $wcorg_subroles;

		$wcorg_subroles = array();

		$this->organizer = $this->become_contributor();
		$this->camp      = $this->create_wordcamp( $this->organizer );
	}

	/**
	 * Leave the global clean for whatever runs next.
	 */
	public function tear_down() {
		global $wcorg_subroles;

		$wcorg_subroles = array();

		parent::tear_down();
	}

	/**
	 * The rule has to be in force wherever a post is written, not only on the screens
	 * `WordCamp_Admin` is constructed for.
	 *
	 * @covers WordCamp_Status_Guard::init
	 */
	public function test_the_guard_is_registered() {
		$this->assertNotFalse(
			has_filter( 'wp_insert_post_data', array( 'WordCamp_Status_Guard', 'enforce_post_status' ) )
		);
	}

	/**
	 * `wcpt-scheduled` means the camp is on the official WordPress schedule, and core's
	 * `handle_status_param()` waves custom statuses through with no capability check.
	 *
	 * @covers WordCamp_Status_Guard::enforce_post_status
	 */
	public function test_rest_status_change_by_the_author_is_reverted() {
		$response = $this->request_status( 'wcpt-scheduled' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( WCPT_DEFAULT_STATUS, get_post_status( $this->camp ) );
	}

	/**
	 * A status with no `trigger_schedule_actions()` hook, so this asserts the clamp and
	 * nothing about the notifications.
	 *
	 * @covers WordCamp_Status_Guard::enforce_post_status
	 */
	public function test_rest_status_change_by_a_wrangler_is_kept() {
		$this->become_wrangler();

		$this->request_status( 'wcpt-interview-sched' );

		$this->assertSame( 'wcpt-interview-sched', get_post_status( $this->camp ) );
	}

	/**
	 * `WordCamp_Loader::set_scheduled_date()` stamps `menu_order` off the submitted status
	 * at priority 20 and never overwrites it, so the clamp has to land first or a rejected
	 * write leaves behind a scheduled date the camp never had.
	 *
	 * @covers WordCamp_Status_Guard::enforce_post_status
	 */
	public function test_direct_status_change_by_the_author_is_reverted() {
		$this->set_status( 'wcpt-scheduled' );

		$this->assertSame( WCPT_DEFAULT_STATUS, get_post_status( $this->camp ) );
		$this->assertSame( 0, get_post( $this->camp )->menu_order );
	}

	/**
	 * The other arm that reverts a status. `require_complete_meta_to_publish_wordcamp()`
	 * runs at 11, so the scheduled date has to be stamped after it too, not just after the
	 * capability clamp.
	 *
	 * @covers WordCamp_Loader::set_scheduled_date
	 */
	public function test_an_incomplete_scheduled_write_does_not_stamp_the_date() {
		$this->become_wrangler();

		// The rule only applies above the site ID it went live for, which no factory post reaches.
		add_filter( 'wcpt_require_complete_meta_min_site_id', '__return_zero' );

		$this->set_status( 'wcpt-scheduled' );

		remove_filter( 'wcpt_require_complete_meta_min_site_id', '__return_zero' );

		$this->assertSame( 'wcpt-needs-schedule', get_post_status( $this->camp ) );
		$this->assertSame( 0, get_post( $this->camp )->menu_order );
	}

	/**
	 * A logged out writer holds no capability either, so it clamps the same way.
	 *
	 * @covers WordCamp_Status_Guard::enforce_post_status
	 */
	public function test_logged_out_status_change_is_reverted() {
		wp_set_current_user( 0 );

		$this->set_status( 'wcpt-scheduled' );

		$this->assertSame( WCPT_DEFAULT_STATUS, get_post_status( $this->camp ) );
	}

	/**
	 * `close_wordcamps_after_event()` writes `wcpt-closed` from cron, with no user at all.
	 *
	 * @covers WordCamp_Status_Guard::enforce_post_status
	 */
	public function test_cron_can_change_the_status() {
		wp_set_current_user( 0 );
		add_filter( 'wp_doing_cron', '__return_true' );

		$this->set_status( 'wcpt-closed' );

		remove_filter( 'wp_doing_cron', '__return_true' );

		$this->assertSame( 'wcpt-closed', get_post_status( $this->camp ) );
	}

	/**
	 * The application starts somewhere else first, so passing this cannot just mean the
	 * write was reverted to the status it already had.
	 *
	 * @covers WordCamp_Status_Guard::enforce_post_status
	 */
	public function test_unregistered_status_falls_back_to_the_default() {
		$this->become_wrangler();
		$this->set_status( 'wcpt-interview-sched' );

		$this->set_status( 'not-a-status' );

		$this->assertSame( WCPT_DEFAULT_STATUS, get_post_status( $this->camp ) );
	}

	/**
	 * `wcpt-needs-action` belongs to the Campus Connect workflow.
	 *
	 * @covers WordCamp_Status_Guard::enforce_post_status
	 */
	public function test_campus_connect_status_is_blocked_on_another_event() {
		$this->become_wrangler();

		$this->set_status( 'wcpt-needs-action' );

		$this->assertSame( WCPT_DEFAULT_STATUS, get_post_status( $this->camp ) );
	}

	/**
	 * @covers WordCamp_Status_Guard::is_campus_connect_post_for_save
	 */
	public function test_campus_connect_status_is_allowed_on_a_campus_connect_event() {
		$this->become_wrangler();
		update_post_meta( $this->camp, 'event_subtype', 'campusconnect' );

		$this->set_status( 'wcpt-needs-action' );

		$this->assertSame( 'wcpt-needs-action', get_post_status( $this->camp ) );
	}

	/**
	 * Write a status straight to the post.
	 *
	 * @param string $status The status to write.
	 */
	protected function set_status( $status ) {
		wp_update_post(
			array(
				'ID'          => $this->camp,
				'post_status' => $status,
			)
		);
	}

	/**
	 * `notify_new_wordcamp_in_slack()` renders the start date, and `gmdate()` raises a
	 * TypeError on an empty one under PHP 8.4. `require_complete_meta_to_publish_wordcamp()`
	 * keeps that off the admin path, so a fixture is the only way to reach it.
	 *
	 * @covers WordCamp_Admin::notify_new_wordcamp_in_slack
	 */
	public function test_scheduling_without_a_start_date_does_not_fatal() {
		$this->become_wrangler();

		$this->set_status( 'wcpt-scheduled' );

		$this->assertSame( 'wcpt-scheduled', get_post_status( $this->camp ) );
	}

	/**
	 * Ask the REST controller to write a status.
	 *
	 * @param string $status The status to submit.
	 *
	 * @return \WP_REST_Response
	 */
	protected function request_status( $status ) {
		$request = new WP_REST_Request( 'POST', '/wp/v2/wordcamps/' . $this->camp );
		$request->set_body_params( array( 'status' => $status ) );

		return rest_do_request( $request );
	}
}
