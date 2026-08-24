<?php

namespace WordCamp\WCPT\Tests;

use WP_REST_Request;
use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * Tests for the rules about which status a WordCamp application may be written to.
 *
 * @group wcpt
 */
class Test_WordCamp_Status_Guard extends WP_UnitTestCase {

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

		$this->organizer = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$this->camp      = self::factory()->post->create(
			array(
				'post_type'   => WCPT_POST_TYPE_ID,
				'post_status' => WCPT_DEFAULT_STATUS,
				'post_author' => $this->organizer,
			)
		);

		wp_set_current_user( $this->organizer );
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
	 * @covers WordCamp_Status_Guard::enforce_post_status
	 */
	public function test_direct_status_change_by_the_author_is_reverted() {
		$this->set_status( 'wcpt-scheduled' );

		$this->assertSame( WCPT_DEFAULT_STATUS, get_post_status( $this->camp ) );
	}

	/**
	 * `WordCamp_Loader::set_scheduled_date()` stamps `menu_order` off the submitted status,
	 * and never overwrites it once set. The clamp has to land first, or a rejected write
	 * leaves behind a scheduled date the camp never had.
	 *
	 * @covers WordCamp_Status_Guard::init
	 */
	public function test_a_reverted_write_does_not_stamp_the_scheduled_date() {
		$this->set_status( 'wcpt-scheduled' );

		$this->assertSame( WCPT_DEFAULT_STATUS, get_post_status( $this->camp ) );
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
	 * Give the current user the wrangler capability.
	 *
	 * It has to come through the subroles system: `omit_usermeta_caps()` deliberately
	 * strips anything granted with `WP_User::add_cap()`.
	 */
	protected function become_wrangler() {
		global $wcorg_subroles;

		$wcorg_subroles = array( get_current_user_id() => array( 'wordcamp_wrangler' ) );
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
