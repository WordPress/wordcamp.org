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
	 * @var WP_User[]
	 */
	protected static $users = array();

	/**
	 * @var WP_Post[]
	 */
	protected static $posts = array();

	/**
	 * @var WP_Comment[]
	 */
	protected static $feedback_comments = array();

	/**
	 * Set up shared fixtures for these tests.
	 *
	 * @param WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$users['speaker']     = $factory->user->create_and_get( array(
			'role' => 'subscriber',
		) );
		self::$users['non-speaker'] = $factory->user->create_and_get( array(
			'role' => 'subscriber',
		) );
		self::$users['editor']      = $factory->user->create_and_get( array(
			'role' => 'editor',
		) );

		self::$posts['speaker-1'] = $factory->post->create_and_get( array(
			'post_type'  => 'wcb_speaker',
			'meta_input' => array(
				'_wcpt_user_id' => self::$users['speaker']->ID,
			),
		) );
		self::$posts['speaker-2'] = $factory->post->create_and_get( array(
			'post_type'  => 'wcb_speaker',
			'meta_input' => array(
				'_wcpt_user_id' => self::$users['editor']->ID,
			),
		) );
		self::$posts['session-1'] = $factory->post->create_and_get( array(
			'post_type' => 'wcb_session',
		) );
		add_post_meta( self::$posts['session-1']->ID, '_wcpt_speaker_id', self::$posts['speaker-1']->ID );
		self::$posts['session-2'] = $factory->post->create_and_get( array(
			'post_type' => 'wcb_session',
		) );
		add_post_meta( self::$posts['session-2']->ID, '_wcpt_speaker_id', self::$posts['speaker-2']->ID );

		self::$feedback_comments['session-1-approve'] = self::factory()->comment->create_and_get( array(
			'comment_type'     => COMMENT_TYPE,
			'comment_post_ID'  => self::$posts['session-1']->ID,
			'comment_approved' => 1,
		) );
		self::$feedback_comments['session-1-hold']    = self::factory()->comment->create_and_get( array(
			'comment_type'     => COMMENT_TYPE,
			'comment_post_ID'  => self::$posts['session-1']->ID,
			'comment_approved' => 0,
		) );
		self::$feedback_comments['session-2-hold']    = self::factory()->comment->create_and_get( array(
			'comment_type'     => COMMENT_TYPE,
			'comment_post_ID'  => self::$posts['session-2']->ID,
			'comment_approved' => 0,
		) );
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
	 * @covers \WordCamp\SpeakerFeedback\Capabilities\map_meta_caps()
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
			self::$feedback_comments['session-2-hold']
		) );
		$this->assertFalse( user_can(
			self::$users['non-speaker'],
			'read_' . COMMENT_TYPE,
			self::$feedback_comments['session-2-hold']
		) );
		$this->assertTrue( user_can(
			self::$users['editor'],
			'read_' . COMMENT_TYPE,
			self::$feedback_comments['session-2-hold']
		) );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\Capabilities\map_meta_caps()
	 */
	public function test_cap_read_post() {
		// Session 1.
		$this->assertTrue( user_can(
			self::$users['speaker'],
			'read_post_' . COMMENT_TYPE,
			self::$posts['session-1']
		) );
		$this->assertFalse( user_can(
			self::$users['non-speaker'],
			'read_post_' . COMMENT_TYPE,
			self::$posts['session-1']
		) );
		$this->assertTrue( user_can(
			self::$users['editor'],
			'read_post_' . COMMENT_TYPE,
			self::$posts['session-1']
		) );

		// Session 2.
		$this->assertFalse( user_can(
			self::$users['speaker'],
			'read_post_' . COMMENT_TYPE,
			self::$posts['session-2']
		) );
		$this->assertFalse( user_can(
			self::$users['non-speaker'],
			'read_post_' . COMMENT_TYPE,
			self::$posts['session-2']
		) );
		$this->assertTrue( user_can(
			self::$users['editor'],
			'read_post_' . COMMENT_TYPE,
			self::$posts['session-2']
		) );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\Capabilities\map_meta_caps()
	 */
	public function test_cap_moderate() {
		$this->assertFalse( user_can(
			self::$users['speaker'],
			'moderate_' . COMMENT_TYPE
		) );
		$this->assertFalse( user_can(
			self::$users['non-speaker'],
			'moderate_' . COMMENT_TYPE
		) );
		$this->assertTrue( user_can(
			self::$users['editor'],
			'moderate_' . COMMENT_TYPE
		) );
	}
}
