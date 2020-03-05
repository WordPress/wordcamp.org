<?php

namespace WordCamp\SpeakerFeedback\Tests;

use WP_UnitTestCase, WP_UnitTest_Factory;
use WP_Comment, WP_Post, WP_User;
use const WordCamp\SpeakerFeedback\Comment\COMMENT_TYPE;

defined( 'WPINC' ) || die();

/**
 * Class Test_SpeakerFeedback_Capabilities
 *
 * @group wordcamp-speaker-feedback
 */
class Test_SpeakerFeedback_Capabilities extends WP_UnitTestCase {
	/**
	 * @var WP_User
	 */
	protected static $users;

	/**
	 * @var WP_Post
	 */
	protected static $session_posts;

	/**
	 * @var WP_Comment
	 */
	protected static $feedback_comments;

	/**
	 * Set up shared fixtures for these tests.
	 *
	 * @param WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$users = array();

		self::$users['speaker']     = $factory->user->create_and_get( array(
			'role' => 'subscriber',
		) );
		self::$users['non-speaker'] = $factory->user->create_and_get( array(
			'role' => 'subscriber',
		) );
		self::$users['editor']      = $factory->user->create_and_get( array(
			'role' => 'editor',
		) );

		self::$session_posts = array();

		self::$session_posts['session-1'] = $factory->post->create_and_get( array(
			'post_type' => 'wcb_session',
		) );
		self::$session_posts['session-2'] = $factory->post->create_and_get( array(
			'post_type' => 'wcb_session',
		) );

		add_post_meta( self::$session_posts['session-1']->ID, '_wcpt_speaker_id', absint( self::$users['speaker']->ID ) );

		self::$feedback_comments = array();

		self::$feedback_comments['session-1-approve'] = self::factory()->comment->create_and_get( array(
			'comment_type'     => COMMENT_TYPE,
			'comment_post_ID'  => self::$session_posts['session-1']->ID,
			'comment_approved' => 1,
		) );
		self::$feedback_comments['session-1-hold']    = self::factory()->comment->create_and_get( array(
			'comment_type'     => COMMENT_TYPE,
			'comment_post_ID'  => self::$session_posts['session-1']->ID,
			'comment_approved' => 0,
		) );
		self::$feedback_comments['session-2-approve'] = self::factory()->comment->create_and_get( array(
			'comment_type'     => COMMENT_TYPE,
			'comment_post_ID'  => self::$session_posts['session-2']->ID,
			'comment_approved' => 1,
		) );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\Comment\map_meta_caps()
	 */
	public function test_cap_read() {
		// Session 1 approved feedback comment.
		$this->assertTrue( user_can(
			self::$users['speaker'],
			'read_' . COMMENT_TYPE,
			self::$feedback_comments['session-1-approve']
		) );
		$this->assertFalse( user_can(
			self::$users['non-speaker'],
			'read_' . COMMENT_TYPE,
			self::$feedback_comments['session-1-approve']
		) );
		$this->assertTrue( user_can(
			self::$users['editor'],
			'read_' . COMMENT_TYPE,
			self::$feedback_comments['session-1-approve']
		) );

		// Session 1 unapproved feedback comment.
		$this->assertFalse( user_can(
			self::$users['speaker'],
			'read_' . COMMENT_TYPE,
			self::$feedback_comments['session-1-hold']
		) );
		$this->assertFalse( user_can(
			self::$users['non-speaker'],
			'read_' . COMMENT_TYPE,
			self::$feedback_comments['session-1-hold']
		) );
		$this->assertTrue( user_can(
			self::$users['editor'],
			'read_' . COMMENT_TYPE,
			self::$feedback_comments['session-1-hold']
		) );

		// Session 2 approved feedback comment.
		$this->assertFalse( user_can(
			self::$users['speaker'],
			'read_' . COMMENT_TYPE,
			self::$feedback_comments['session-2-approve']
		) );
		$this->assertFalse( user_can(
			self::$users['non-speaker'],
			'read_' . COMMENT_TYPE,
			self::$feedback_comments['session-2-approve']
		) );
		$this->assertTrue( user_can(
			self::$users['editor'],
			'read_' . COMMENT_TYPE,
			self::$feedback_comments['session-2-approve']
		) );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\Comment\map_meta_caps()
	 */
	public function test_cap_moderate() {
		// Session 1 approved feedback comment.
		$this->assertFalse( user_can(
			self::$users['speaker'],
			'moderate_' . COMMENT_TYPE,
			self::$feedback_comments['session-1-approve']
		) );
		$this->assertFalse( user_can(
			self::$users['non-speaker'],
			'moderate_' . COMMENT_TYPE,
			self::$feedback_comments['session-1-approve']
		) );
		$this->assertTrue( user_can(
			self::$users['editor'],
			'moderate_' . COMMENT_TYPE,
			self::$feedback_comments['session-1-approve']
		) );

		// Session 1 unapproved feedback comment.
		$this->assertFalse( user_can(
			self::$users['speaker'],
			'moderate_' . COMMENT_TYPE,
			self::$feedback_comments['session-1-hold']
		) );
		$this->assertFalse( user_can(
			self::$users['non-speaker'],
			'moderate_' . COMMENT_TYPE,
			self::$feedback_comments['session-1-hold']
		) );
		$this->assertTrue( user_can(
			self::$users['editor'],
			'moderate_' . COMMENT_TYPE,
			self::$feedback_comments['session-1-hold']
		) );

		// Session 2 approved feedback comment.
		$this->assertFalse( user_can(
			self::$users['speaker'],
			'moderate_' . COMMENT_TYPE,
			self::$feedback_comments['session-2-approve']
		) );
		$this->assertFalse( user_can(
			self::$users['non-speaker'],
			'moderate_' . COMMENT_TYPE,
			self::$feedback_comments['session-2-approve']
		) );
		$this->assertTrue( user_can(
			self::$users['editor'],
			'moderate_' . COMMENT_TYPE,
			self::$feedback_comments['session-2-approve']
		) );
	}
}
