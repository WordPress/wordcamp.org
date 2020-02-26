<?php

namespace WordCamp\SpeakerFeedback\Tests;

use WP_UnitTestCase;
use WP_Post, WP_User;
use WP_REST_Request, WP_REST_Response;
use WordCamp\SpeakerFeedback\REST_Feedback_Controller;
use const WordCamp\SpeakerFeedback\CommentMeta\{ META_VERSION };

defined( 'WPINC' ) || die();

/**
 * Class Test_SpeakerFeedback_Comment
 *
 * @group wordcamp-speaker-feedback
 */
class Test_SpeakerFeedback_REST_Feedback_Controller extends WP_UnitTestCase {
	/**
	 * @var REST_Feedback_Controller
	 */
	protected $controller;

	/**
	 * @var WP_Post
	 */
	protected $session_post;

	/**
	 * @var WP_User
	 */
	protected $user;

	/**
	 * @var array
	 */
	protected $valid_meta;

	/**
	 * Test_SpeakerFeedback_REST_Feedback_Controller constructor.
	 *
	 * @param null   $name
	 * @param array  $data
	 * @param string $data_name
	 */
	public function __construct( $name = null, array $data = array(), $data_name = '' ) {
		parent::__construct( $name, $data, $data_name );

		$this->controller = new REST_Feedback_Controller();

		$this->session_post = self::factory()->post->create_and_get( array(
			'post_type' => 'wcb_session',
		) );

		$this->user = self::factory()->user->create_and_get();

		$this->valid_meta = array(
			'version' => META_VERSION,
			'rating'  => 1,
			'q1'      => 'asdf 1',
			'q2'      => 'asdf 2',
			'q3'      => 'asdf 3',
		);
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\REST_Feedback_Controller::create_item()
	 */
	public function test_create_item() {
		$request = new WP_REST_Request( 'POST', '/wordcamp-speaker-feedback/v1/feedback' );

		$params = array(
			'post'   => $this->session_post->ID,
			'author' => $this->user->ID,
			'meta'   => $this->valid_meta,
		);

		$request->set_body_params( $params );

		$response = $this->controller->create_item( $request );

		$this->assertTrue( $response instanceof WP_REST_Response );
		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( 'Success!', $response->get_data() );
	}
}
