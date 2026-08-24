<?php

namespace WordCamp\WCPT\Tests;

use WP_REST_Request;
use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * Tests for which cancelled camps stay readable.
 *
 * A camp that reached the official schedule and was then cancelled is a public record
 * that consumers still need. A camp cancelled while it was still an application was
 * never listed anywhere.
 *
 * @group wcpt
 */
class Test_Cancelled_Camp_Visibility extends WP_UnitTestCase {

	/**
	 * Reset the subroles global.
	 */
	public function set_up() {
		parent::set_up();

		global $wcorg_subroles;

		$wcorg_subroles = array();
		wp_set_current_user( 0 );
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
	 * The status is no longer what decides, so it must not be registered as though it were.
	 *
	 * @covers WordCamp_Loader::get_publicly_viewable_post_statuses
	 */
	public function test_cancelled_is_not_publicly_viewable() {
		$this->assertNotContains( 'wcpt-cancelled', \WordCamp_Loader::get_publicly_viewable_post_statuses() );
		$this->assertFalse( get_post_status_object( 'wcpt-cancelled' )->public );
		$this->assertTrue( get_post_status_object( 'wcpt-cancelled' )->protected );
	}

	/**
	 * Official WordPress Events reads these to drop cancelled camps from the events widget.
	 *
	 * @covers WordCamp_REST_WordCamps_Controller::check_read_permission
	 */
	public function test_camp_cancelled_after_scheduling_is_readable() {
		$camp = $this->create_cancelled_camp( 1560293422 );

		$this->assertSame( 200, $this->request_camp( $camp )->get_status() );
	}

	/**
	 * @covers WordCamp_REST_WordCamps_Controller::check_read_permission
	 */
	public function test_camp_cancelled_while_an_application_is_not_readable() {
		$camp = $this->create_cancelled_camp( 0 );

		$this->assertSame( 401, $this->request_camp( $camp )->get_status() );
	}

	/**
	 * The collection is what the events widget actually polls, so the same split has to
	 * hold there rather than only on the single item.
	 *
	 * @covers WordCamp_REST_WordCamps_Controller::check_read_permission
	 */
	public function test_collection_returns_only_camps_cancelled_after_scheduling() {
		$scheduled   = $this->create_cancelled_camp( 1560293422 );
		$application = $this->create_cancelled_camp( 0 );

		$request = new WP_REST_Request( 'GET', '/wp/v2/wordcamps' );
		$request->set_param( 'status', 'wcpt-cancelled' );
		$request->set_param( '_fields', 'id' );

		$ids = wp_list_pluck( rest_do_request( $request )->get_data(), 'id' );

		$this->assertContains( $scheduled, $ids );
		$this->assertNotContains( $application, $ids );
	}

	/**
	 * The controller refuses every non-public status regardless of capability, and always
	 * has. What `protected` buys a wrangler is the front-end single view, not this route.
	 *
	 * @covers WordCamp_REST_WordCamps_Controller::check_read_permission
	 */
	public function test_rest_refuses_an_application_stage_cancellation_even_for_a_wrangler() {
		global $wcorg_subroles;

		$camp     = $this->create_cancelled_camp( 0 );
		$wrangler = self::factory()->user->create( array( 'role' => 'contributor' ) );

		// `omit_usermeta_caps()` strips anything granted with `WP_User::add_cap()`.
		$wcorg_subroles = array( $wrangler => array( 'wordcamp_wrangler' ) );
		wp_set_current_user( $wrangler );

		$this->assertSame( 403, $this->request_camp( $camp )->get_status() );
	}

	/**
	 * `protected` keeps the permalink working for anyone who can edit the post, which is
	 * how wranglers open a record they are processing.
	 *
	 * @covers WordCamp_Loader::get_publicly_viewable_post_statuses
	 */
	public function test_wrangler_still_resolves_the_permalink() {
		global $wcorg_subroles;

		$camp     = $this->create_cancelled_camp( 0 );
		$wrangler = self::factory()->user->create( array( 'role' => 'contributor' ) );

		$wcorg_subroles = array( $wrangler => array( 'wordcamp_wrangler' ) );
		wp_set_current_user( $wrangler );

		set_current_screen( 'front' );

		$query = new \WP_Query(
			array(
				'post_type' => WCPT_POST_TYPE_ID,
				'name'      => get_post_field( 'post_name', $camp ),
			)
		);

		$this->assertCount( 1, $query->posts );
		$this->assertSame( $camp, $query->posts[0]->ID );
	}

	/**
	 * Ask the REST controller for one camp, anonymously unless a user is already set.
	 *
	 * @param int $post_id The camp to request.
	 */
	protected function request_camp( $post_id ): \WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/wp/v2/wordcamps/' . $post_id );
		$request->set_param( '_fields', 'id,status' );

		return rest_do_request( $request );
	}

	/**
	 * @param int $scheduled_date The date the camp was added to the schedule, or 0 if it
	 *                            never reached it. Stored in `menu_order`.
	 */
	protected function create_cancelled_camp( $scheduled_date ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => WCPT_POST_TYPE_ID,
				'post_status' => 'wcpt-cancelled',
				'menu_order'  => $scheduled_date,
			)
		);
	}
}
