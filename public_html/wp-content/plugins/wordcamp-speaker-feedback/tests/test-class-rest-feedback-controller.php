<?php

namespace WordCamp\SpeakerFeedback\Tests;

use WP_UnitTestCase, WP_UnitTest_Factory;
use WP_Comment, WP_Post, WP_User;
use WP_REST_Request, WP_REST_Response;
use WordCamp\SpeakerFeedback\REST_Feedback_Controller;
use function WordCamp\SpeakerFeedback\Comment\{ get_feedback, get_feedback_comment };
use const WordCamp\SpeakerFeedback\Comment\COMMENT_TYPE;

defined( 'WPINC' ) || die();

/**
 * Class Test_SpeakerFeedback_REST_Feedback_Controller
 *
 * @group wordcamp-speaker-feedback
 */
class Test_SpeakerFeedback_REST_Feedback_Controller extends WP_UnitTestCase {
	/**
	 * @var REST_Feedback_Controller
	 */
	protected static $controller;

	/**
	 * @var WP_Post[]
	 */
	protected static $posts = array();

	/**
	 * @var WP_User[]
	 */
	protected static $users = array();

	/**
	 * @var WP_Comment[]
	 */
	protected static $feedback_comments = array();

	/**
	 * @var array
	 */
	protected static $valid_meta;

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
		add_post_type_support( 'wcb_session', 'wordcamp-speaker-feedback' );

		self::$controller = new REST_Feedback_Controller();

		self::$users['subscriber'] = $factory->user->create_and_get( array(
			'role' => 'subscriber',
		) );

		self::$users['speaker'] = $factory->user->create_and_get( array(
			'role' => 'subscriber',
		) );

		self::$users['admin'] = $factory->user->create_and_get( array(
			'role' => 'administrator',
		) );

		self::$posts['speaker'] = $factory->post->create_and_get( array(
			'post_type'  => 'wcb_speaker',
			'meta_input' => array(
				'_wcpt_user_id' => self::$users['speaker']->ID,
			),
		) );

		self::$posts['valid-session'] = $factory->post->create_and_get( array(
			'post_type'  => 'wcb_session',
			'meta_input' => array(
				'_wcpt_session_type' => 'session',
				'_wcpt_session_time' => strtotime( '- 1 day' ),
			),
		) );

		self::$posts['valid-session-with-speaker'] = $factory->post->create_and_get( array(
			'post_type'  => 'wcb_session',
			'meta_input' => array(
				'_wcpt_session_type' => 'session',
				'_wcpt_session_time' => strtotime( '- 1 day' ),
			),
		) );
		// It doesn't work to add this value via `meta_input` for some reason.
		add_post_meta( self::$posts['valid-session-with-speaker']->ID, '_wcpt_speaker_id', self::$posts['speaker']->ID );

		self::$feedback_comments['feedback-approved'] = self::factory()->comment->create_and_get( array(
			'comment_type'     => COMMENT_TYPE,
			'comment_post_ID'  => self::$posts['valid-session-with-speaker']->ID,
			'comment_approved' => 1,
		) );

		self::$feedback_comments['not-feedback'] = self::factory()->comment->create_and_get( array(
			'comment_post_ID'  => self::$posts['valid-session-with-speaker']->ID,
			'comment_approved' => 1,
		) );

		self::$valid_meta = array(
			'rating' => 1,
			'q1'     => 'asdf 1',
			'q2'     => 'asdf 2',
			'q3'     => 'asdf 3',
		);

		tests_add_filter( 'rest_api_init', array( self::$controller, 'register_routes' ) );
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

		foreach ( self::$feedback_comments as $comment ) {
			wp_delete_comment( $comment, true );
		}
	}

	/**
	 * Set up before each test.
	 */
	public function setUp() {
		parent::setUp();

		$this->request = new WP_REST_Request( 'POST', '/wordcamp-speaker-feedback/v1/feedback' );

		wp_set_current_user( self::$users['subscriber']->ID );
	}

	/**
	 * Reset after each test.
	 */
	public function tearDown() {
		$created_feedback = get_feedback( array( self::$posts['valid-session']->ID ) );
		foreach ( $created_feedback as $feedback ) {
			wp_delete_comment( $feedback->comment_ID, true );
		}

		$this->request = null;

		parent::tearDown();
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\REST_Feedback_Controller::create_item()
	 */
	public function test_create_item_user_id() {
		$params = array(
			'post'   => self::$posts['valid-session']->ID,
			'author' => self::$users['subscriber']->ID,
			'meta'   => self::$valid_meta,
		);

		$this->request->set_body_params( $params );

		$response = self::$controller->create_item( $this->request );

		$this->assertTrue( $response instanceof WP_REST_Response );
		$this->assertEquals( 201, $response->get_status() );
		$this->assertCount( 1, get_feedback( array( self::$posts['valid-session']->ID ) ) );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\REST_Feedback_Controller::create_item()
	 */
	public function test_create_item_user_array() {
		$params = array(
			'post'         => self::$posts['valid-session']->ID,
			'author_name'  => 'Foo',
			'author_email' => 'bar@example.org',
			'meta'         => self::$valid_meta,
		);

		$this->request->set_body_params( $params );

		$response = self::$controller->create_item( $this->request );

		$this->assertTrue( $response instanceof WP_REST_Response );
		$this->assertEquals( 201, $response->get_status() );
		$this->assertCount( 1, get_feedback( array( self::$posts['valid-session']->ID ) ) );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\REST_Feedback_Controller::create_item()
	 */
	public function test_create_item_no_user() {
		$params = array(
			'post' => self::$posts['valid-session']->ID,
			'meta' => self::$valid_meta,
		);

		$this->request->set_body_params( $params );

		$response = self::$controller->create_item( $this->request );

		$this->assertWPError( $response );
		$this->assertEquals( 'rest_feedback_author_data_required', $response->get_error_code() );
		$this->assertCount( 0, get_feedback( array( self::$posts['valid-session']->ID ) ) );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\REST_Feedback_Controller::create_item()
	 */
	public function test_create_item_no_meta() {
		$params = array(
			'post'   => self::$posts['valid-session']->ID,
			'author' => self::$users['subscriber']->ID,
		);

		$this->request->set_body_params( $params );

		$response = self::$controller->create_item( $this->request );

		$this->assertWPError( $response );
		$this->assertEquals( 'rest_feedback_meta_data_required', $response->get_error_code() );
		$this->assertCount( 0, get_feedback( array( self::$posts['valid-session']->ID ) ) );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\REST_Feedback_Controller::duplicate_check()
	 */
	public function test_create_item_duplicate() {
		// Ensure multiple comments doesn't trigger a "comment flood". Admins get a pass.
		wp_set_current_user( self::$users['admin']->ID );

		$params = array(
			'post'   => self::$posts['valid-session']->ID,
			'author' => self::$users['subscriber']->ID,
			'meta'   => self::$valid_meta,
		);

		$this->request->set_body_params( $params );

		$response1 = self::$controller->create_item( $this->request );

		$this->assertTrue( $response1 instanceof WP_REST_Response );
		$this->assertEquals( 201, $response1->get_status() );
		$this->assertCount( 1, get_feedback( array( self::$posts['valid-session']->ID ) ) );

		$response2 = self::$controller->create_item( $this->request );

		$this->assertWPError( $response2 );
		$this->assertEquals( 'comment_duplicate', $response2->get_error_code() );
		$this->assertCount( 1, get_feedback( array( self::$posts['valid-session']->ID ) ) );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\REST_Feedback_Controller::duplicate_check()
	 */
	public function test_create_item_not_duplicate() {
		// Ensure multiple comments doesn't trigger a "comment flood". Admins get a pass.
		wp_set_current_user( self::$users['admin']->ID );

		$params = array(
			'post'   => self::$posts['valid-session']->ID,
			'author' => self::$users['subscriber']->ID,
			'meta'   => array(
				'rating' => 1,
				'q1'     => 'asdf 1',
				'q2'     => 'asdf 2',
				'q3'     => 'asdf 3',
			),
		);

		$this->request->set_body_params( $params );

		$response1 = self::$controller->create_item( $this->request );

		$this->assertTrue( $response1 instanceof WP_REST_Response );
		$this->assertEquals( 201, $response1->get_status() );
		$this->assertCount( 1, get_feedback( array( self::$posts['valid-session']->ID ) ) );

		$params['meta']['rating'] = 2; // Different value.

		$this->request->set_body_params( $params );

		$response2 = self::$controller->create_item( $this->request );

		$this->assertTrue( $response2 instanceof WP_REST_Response );
		$this->assertEquals( 201, $response2->get_status() );
		$this->assertCount( 2, get_feedback( array( self::$posts['valid-session']->ID ) ) );

		unset( $params['meta']['q3'] ); // Missing value.

		$this->request->set_body_params( $params );

		$response3 = self::$controller->create_item( $this->request );

		$this->assertTrue( $response3 instanceof WP_REST_Response );
		$this->assertEquals( 201, $response3->get_status() );
		$this->assertCount( 3, get_feedback( array( self::$posts['valid-session']->ID ) ) );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\REST_Feedback_Controller::create_item_permissions_check()
	 */
	public function test_create_item_permissions_check_is_valid() {
		$params = array(
			'post'   => self::$posts['valid-session']->ID,
			'author' => self::$users['subscriber']->ID,
			'meta'   => self::$valid_meta,
		);

		$this->request->set_body_params( $params );

		$response = self::$controller->create_item_permissions_check( $this->request );

		$this->assertTrue( $response );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\REST_Feedback_Controller::create_item_permissions_check()
	 */
	public function test_create_item_permissions_check_no_post() {
		$params = array(
			'author' => self::$users['subscriber']->ID,
			'meta'   => self::$valid_meta,
		);

		$this->request->set_body_params( $params );

		$response = self::$controller->create_item_permissions_check( $this->request );

		$this->assertWPError( $response );
		$this->assertEquals( 'rest_feedback_invalid_post_id', $response->get_error_code() );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\REST_Feedback_Controller::create_item_permissions_check()
	 */
	public function test_create_item_permissions_check_post_not_accepting() {
		$params = array(
			'post'   => 999999999,
			'author' => self::$users['subscriber']->ID,
			'meta'   => self::$valid_meta,
		);

		$this->request->set_body_params( $params );

		$response = self::$controller->create_item_permissions_check( $this->request );

		$this->assertWPError( $response );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\REST_Feedback_Controller::update_item()
	 */
	public function test_update_item_set_helpful() {
		$params = array(
			'id'   => self::$feedback_comments['feedback-approved']->comment_ID,
			'meta' => array(
				'helpful' => true,
			),
		);

		$this->request->set_body_params( $params );

		$response = self::$controller->update_item( $this->request );

		$this->assertTrue( $response instanceof WP_REST_Response );
		$this->assertEquals( 201, $response->get_status() );
		$this->assertTrue( (bool) get_feedback_comment( self::$feedback_comments['feedback-approved'] )->helpful );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\REST_Feedback_Controller::update_item()
	 */
	public function test_update_item_unset_helpful() {
		$params = array(
			'id'   => self::$feedback_comments['feedback-approved']->comment_ID,
			'meta' => array(
				'helpful' => false,
			),
		);

		$this->request->set_body_params( $params );

		$response = self::$controller->update_item( $this->request );

		$this->assertTrue( $response instanceof WP_REST_Response );
		$this->assertEquals( 201, $response->get_status() );
		$this->assertFalse( (bool) get_feedback_comment( self::$feedback_comments['feedback-approved'] )->helpful );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\REST_Feedback_Controller::update_item()
	 */
	public function test_update_item_no_comment_id() {
		$params = array(
			'meta' => array(
				'helpful' => true,
			),
		);

		$this->request->set_body_params( $params );

		$response = self::$controller->update_item( $this->request );

		$this->assertWPError( $response );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\REST_Feedback_Controller::update_item()
	 */
	public function test_update_item_no_meta() {
		$params = array(
			'id' => self::$feedback_comments['feedback-approved']->comment_ID,
		);

		$this->request->set_body_params( $params );

		$response = self::$controller->update_item( $this->request );

		$this->assertWPError( $response );
		$this->assertEquals( 'rest_feedback_meta_data_required', $response->get_error_code() );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\REST_Feedback_Controller::update_item_permissions_check()
	 */
	public function test_update_item_permissions_check() {
		wp_set_current_user( self::$users['speaker']->ID );

		$params = array(
			'id'   => self::$feedback_comments['feedback-approved']->comment_ID,
			'meta' => array(
				'helpful' => true,
			),
		);

		$this->request->set_body_params( $params );

		$response1 = self::$controller->update_item_permissions_check( $this->request );

		$this->assertTrue( $response1 );

		wp_set_current_user( self::$users['subscriber']->ID );

		$response2 = self::$controller->update_item_permissions_check( $this->request );

		$this->assertWPError( $response2 );
		$this->assertEquals( 'rest_feedback_no_permission', $response2->get_error_code() );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\REST_Feedback_Controller::update_item_permissions_check()
	 */
	public function test_update_item_permissions_check_not_feedback() {
		wp_set_current_user( self::$users['admin']->ID );

		$params = array(
			'id'   => self::$feedback_comments['not-feedback']->comment_ID,
			'meta' => array(
				'helpful' => true,
			),
		);

		$this->request->set_body_params( $params );

		$response = self::$controller->update_item_permissions_check( $this->request );

		$this->assertWPError( $response );
		$this->assertEquals( 'rest_feedback_not_feedback', $response->get_error_code() );
	}
}
