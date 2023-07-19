<?php

namespace WordCamp\SpeakerFeedback\Tests;

use WP_UnitTestCase, WP_UnitTest_Factory;
use WP_Post;
use WordCamp_Post_Types_Plugin;
use function WordCamp\SpeakerFeedback\Post\{
	get_earliest_session_timestamp, get_latest_session_ending_timestamp,
	post_accepts_feedback, get_session_speaker_user_ids
};

defined( 'WPINC' ) || die();

/**
 * Class Test_SpeakerFeedback_Post
 *
 * @group wordcamp-speaker-feedback
 */
class Test_SpeakerFeedback_Post extends WP_UnitTestCase {
	/**
	 * @var WP_Post[]
	 */
	protected static $posts = array();

	/**
	 * @var int
	 */
	protected static $now;

	/**
	 * Set up fixtures.
	 *
	 * @param WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		add_post_type_support( 'wcb_session', 'wordcamp-speaker-feedback' );

		self::$now = time();

		self::$posts['speaker-with-id'] = $factory->post->create_and_get( array(
			'post_type'   => 'wcb_speaker',
			'post_status' => 'publish',
			'meta_input'  => array(
				'_wcpt_user_id' => 1,
			),
		) );

		self::$posts['speaker-invalid-id'] = $factory->post->create_and_get( array(
			'post_type'   => 'wcb_speaker',
			'post_status' => 'publish',
			'meta_input'  => array(
				'_wcpt_user_id' => 'potato',
			),
		) );

		self::$posts['yes'] = $factory->post->create_and_get( array(
			'post_type'   => 'wcb_session',
			'post_status' => 'publish',
			'meta_input'  => array(
				'_wcpt_session_type' => 'session',
				'_wcpt_session_time' => strtotime( '- 1 day', self::$now ),
			),
		) );
		add_post_meta( self::$posts['yes']->ID, '_wcpt_speaker_id', self::$posts['speaker-with-id']->ID );

		self::$posts['no-support'] = $factory->post->create_and_get( array(
			'post_type'   => 'wcb_sponsor',
			'post_status' => 'publish',
		) );

		self::$posts['no-status'] = $factory->post->create_and_get( array(
			'post_type'   => 'wcb_session',
			'post_status' => 'draft',
		) );

		self::$posts['no-session-type'] = $factory->post->create_and_get( array(
			'post_type'   => 'wcb_session',
			'post_status' => 'publish',
			'meta_input'  => array(
				'_wcpt_session_type' => 'custom',
				'_wcpt_session_time' => strtotime( '- 1 day', self::$now ),
			),
		) );

		self::$posts['no-too-soon'] = $factory->post->create_and_get( array(
			'post_type'   => 'wcb_session',
			'post_status' => 'publish',
			'meta_input'  => array(
				'_wcpt_session_type' => 'session',
				'_wcpt_session_time' => strtotime( '+ 1 day', self::$now ),
			),
		) );
		add_post_meta( self::$posts['no-too-soon']->ID, '_wcpt_speaker_id', self::$posts['speaker-invalid-id']->ID );

		self::$posts['no-too-late'] = $factory->post->create_and_get( array(
			'post_type'   => 'wcb_session',
			'post_status' => 'publish',
			'meta_input'  => array(
				'_wcpt_session_type' => 'session',
				'_wcpt_session_time' => strtotime( '- 15 days', self::$now ),
			),
		) );
	}

	/**
	 * Remove fixtures.
	 */
	public static function wpTearDownAfterClass() {
		foreach ( self::$posts as $post ) {
			wp_delete_post( $post->ID, true );
		}
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\Post\post_accepts_feedback()
	 */
	public function test_post_accepts_feedback() {
		$result = post_accepts_feedback( self::$posts['yes']->ID );

		$this->assertTrue( $result );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\Post\post_accepts_feedback()
	 */
	public function test_post_accepts_feedback_no_post() {
		$result = post_accepts_feedback( 999999999 );

		$this->assertWPError( $result );
		$this->assertEquals( 'speaker_feedback_invalid_post_id', $result->get_error_code() );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\Post\post_accepts_feedback()
	 */
	public function test_post_accepts_feedback_no_support() {
		$result = post_accepts_feedback( self::$posts['no-support']->ID );

		$this->assertWPError( $result );
		$this->assertEquals( 'speaker_feedback_post_not_supported', $result->get_error_code() );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\Post\post_accepts_feedback()
	 */
	public function test_post_accepts_feedback_no_status() {
		$result = post_accepts_feedback( self::$posts['no-status']->ID );

		$this->assertWPError( $result );
		$this->assertEquals( 'speaker_feedback_post_unavailable', $result->get_error_code() );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\Post\post_accepts_feedback()
	 */
	public function test_post_accepts_feedback_no_session_type() {
		$result = post_accepts_feedback( self::$posts['no-session-type']->ID );

		$this->assertWPError( $result );
		$this->assertEquals( 'speaker_feedback_invalid_session_type', $result->get_error_code() );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\Post\post_accepts_feedback()
	 */
	public function test_post_accepts_feedback_no_too_soon() {
		$result = post_accepts_feedback( self::$posts['no-too-soon']->ID );

		$this->assertWPError( $result );
		$this->assertEquals( 'speaker_feedback_session_too_soon', $result->get_error_code() );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\Post\post_accepts_feedback()
	 */
	public function test_post_accepts_feedback_no_too_late() {
		$result = post_accepts_feedback( self::$posts['no-too-late']->ID );

		$this->assertWPError( $result );
		$this->assertEquals( 'speaker_feedback_session_too_late', $result->get_error_code() );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\Post\get_earliest_session_timestamp()
	 */
	public function test_get_earliest_session_timestamp() {
		$result   = get_earliest_session_timestamp();
		$expected = strtotime( '- 15 days', self::$now );

		$this->assertEquals( $expected, $result );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\Post\get_latest_session_ending_timestamp()
	 */
	public function test_get_latest_session_ending_timestamp_default_duration() {
		$result   = get_latest_session_ending_timestamp();
		$expected = strtotime( '+ 1 day', self::$now ) + WordCamp_Post_Types_Plugin::SESSION_DEFAULT_DURATION;

		$this->assertEquals( $expected, $result );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\Post\get_latest_session_ending_timestamp()
	 */
	public function test_get_latest_session_ending_timestamp_custom_duration() {
		update_post_meta( self::$posts['no-too-soon']->ID, '_wcpt_session_duration', 1234 );

		$result   = get_latest_session_ending_timestamp();
		$expected = strtotime( '+ 1 day', self::$now ) + 1234;

		$this->assertEquals( $expected, $result );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\Post\get_session_speaker_user_ids()
	 */
	public function test_get_session_speaker_user_ids() {
		$user_ids = get_session_speaker_user_ids( self::$posts['yes']->ID );

		$this->assertCount( 1, $user_ids );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\Post\get_session_speaker_user_ids()
	 */
	public function test_get_session_speaker_user_ids_invalid() {
		$user_ids = get_session_speaker_user_ids( self::$posts['no-too-soon']->ID );

		$this->assertCount( 0, $user_ids );
	}
}
