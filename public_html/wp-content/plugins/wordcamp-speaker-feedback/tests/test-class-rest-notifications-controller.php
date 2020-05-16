<?php

namespace WordCamp\SpeakerFeedback\Tests;

use WP_UnitTestCase, WP_UnitTest_Factory;
use WP_Comment, WP_Post, WP_User;
use WP_REST_Request, WP_REST_Response;
use WordCamp\SpeakerFeedback\REST_Notifications_Controller;
use const WordCamp\SpeakerFeedback\Cron\SPEAKER_OPT_OUT_KEY;

defined( 'WPINC' ) || die();

/**
 * Class Test_SpeakerFeedback_REST_Notifications_Controller
 *
 * @group wordcamp-speaker-feedback
 */
class Test_SpeakerFeedback_REST_Notifications_Controller extends WP_UnitTestCase {
	/**
	 * @var REST_Notifications_Controller
	 */
	protected static $controller;

	/**
	 * @var WP_User[]
	 */
	protected static $users = array();

	/**
	 * @var WP_Post[]
	 */
	protected static $posts = array();

	/**
	 * @var WP_REST_Request
	 */
	protected $request;

	/**
	 * Set up shared fixtures for these tests.
	 *
	 * @param WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$controller = new REST_Notifications_Controller();
		tests_add_filter( 'rest_api_init', array( self::$controller, 'register_routes' ) );

		self::$users['speaker'] = $factory->user->create_and_get( array(
			'role' => 'subscriber',
		) );

		self::$users['non-speaker'] = $factory->user->create_and_get( array(
			'role' => 'subscriber',
		) );

		self::$users['administrator'] = $factory->user->create_and_get( array(
			'role' => 'administrator',
		) );

		self::$posts['speaker'] = $factory->post->create_and_get( array(
			'post_type'  => 'wcb_speaker',
			'meta_input' => array(
				'_wcpt_user_id' => self::$users['speaker']->ID,
			),
		) );

		self::$posts['session'] = $factory->post->create_and_get( array(
			'post_type'  => 'wcb_session',
			'meta_input' => array(
				'_wcpt_session_type' => 'session',
				'_wcpt_session_time' => strtotime( '- 1 day' ),
			),
		) );
		// It doesn't work to add this value via `meta_input` for some reason.
		add_post_meta( self::$posts['session']->ID, '_wcpt_speaker_id', self::$posts['speaker']->ID );
	}

	/**
	 * Remove fixtures.
	 */
	public static function wpTearDownAfterClass() {
		foreach ( self::$posts as $post ) {
			wp_delete_post( $post->ID, true );
		}

		foreach ( self::$users as $user ) {
			wp_delete_user( $user->ID );
		}
	}

	/**
	 * Set up before each test.
	 */
	public function setUp() {
		parent::setUp();

		wp_set_current_user( self::$users['speaker']->ID );

		$this->request = new WP_REST_Request( 'POST', '/wordcamp-speaker-feedback/v1/notifications' );
	}

	/**
	 * Reset after each test.
	 */
	public function tearDown() {
		$this->request = null;

		foreach ( self::$users as $user ) {
			delete_user_meta( $user->ID, SPEAKER_OPT_OUT_KEY );
		}

		parent::tearDown();
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\REST_Notifications_Controller::update_notifications()
	 */
	public function test_update_notifications_speaker_opt_out() {
		$params = array(
			'id'              => self::$users['speaker']->ID,
			'speaker_opt_out' => 'true',
		);

		$this->request->set_body_params( $params );

		$response1 = self::$controller->update_notifications( $this->request );

		$this->assertTrue( $response1 instanceof WP_REST_Response );
		$this->assertEquals( 201, $response1->get_status() );
		$this->assertTrue( (bool) self::$users['speaker']->{SPEAKER_OPT_OUT_KEY} );

		$response2 = self::$controller->update_notifications( $this->request );

		$this->assertWPError( $response2 );
		$this->assertEquals( 'rest_feedback_notifications_data_not_changed', $response2->get_error_code() );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\REST_Notifications_Controller::update_notifications()
	 */
	public function test_update_notifications_speaker_opt_back_in() {
		update_user_meta( self::$users['speaker']->ID, SPEAKER_OPT_OUT_KEY, true );

		$params = array(
			'id'              => self::$users['speaker']->ID,
			'speaker_opt_out' => 'false',
		);

		$this->request->set_body_params( $params );

		$response1 = self::$controller->update_notifications( $this->request );

		$this->assertTrue( $response1 instanceof WP_REST_Response );
		$this->assertEquals( 201, $response1->get_status() );
		$this->assertFalse( (bool) self::$users['speaker']->{SPEAKER_OPT_OUT_KEY} );

		$response2 = self::$controller->update_notifications( $this->request );

		$this->assertWPError( $response2 );
		$this->assertEquals( 'rest_feedback_notifications_data_not_changed', $response2->get_error_code() );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\REST_Notifications_Controller::update_notifications()
	 */
	public function test_update_notifications_no_id() {
		$params = array(
			'speaker_opt_out' => 'true',
		);

		$this->request->set_body_params( $params );

		$response = self::$controller->update_notifications( $this->request );

		$this->assertWPError( $response );
		$this->assertEquals( 'rest_feedback_notifications_invalid_user_id', $response->get_error_code() );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\REST_Notifications_Controller::update_notifications()
	 */
	public function test_update_notifications_invalid_parameter() {
		$params = array(
			'id'              => self::$users['speaker']->ID,
			'out_opt_speaker' => 'true',
		);

		$this->request->set_body_params( $params );

		$response = self::$controller->update_notifications( $this->request );

		$this->assertWPError( $response );
		$this->assertEquals( 'rest_feedback_notifications_data_required', $response->get_error_code() );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\REST_Notifications_Controller::update_notifications_permissions_check()
	 */
	public function test_update_notifications_permissions_check_can_edit_self() {
		$params = array(
			'id'              => self::$users['speaker']->ID,
			'speaker_opt_out' => 'true',
		);

		$this->request->set_body_params( $params );

		$response = self::$controller->update_notifications_permissions_check( $this->request );

		$this->assertTrue( $response );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\REST_Notifications_Controller::update_notifications_permissions_check()
	 */
	public function test_update_notifications_permissions_check_cannot_edit() {
		wp_set_current_user( self::$users['non-speaker']->ID );

		$params = array(
			'id'              => self::$users['speaker']->ID,
			'speaker_opt_out' => 'true',
		);

		$this->request->set_body_params( $params );

		$response = self::$controller->update_notifications_permissions_check( $this->request );

		$this->assertWPError( $response );
		$this->assertEquals( 'rest_feedback_cannot_edit_user', $response->get_error_code() );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\REST_Notifications_Controller::update_notifications_permissions_check()
	 */
	public function test_update_notifications_permissions_check_admin_can_edit() {
		wp_set_current_user( self::$users['administrator']->ID );

		$params = array(
			'id'              => self::$users['speaker']->ID,
			'speaker_opt_out' => 'true',
		);

		$this->request->set_body_params( $params );

		$response = self::$controller->update_notifications_permissions_check( $this->request );

		$this->assertTrue( $response );
	}
}
