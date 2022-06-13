<?php

namespace WordCamp\SpeakerFeedback\Tests;

use WP_Post, WP_User;
use WP_UnitTestCase, WP_UnitTest_Factory;
use WordCamp\SpeakerFeedback\Feedback;
use const WordCamp\SpeakerFeedback\Comment\COMMENT_TYPE;
use function WordCamp\SpeakerFeedback\Comment\{
	add_feedback,
	update_feedback,
	get_feedback,
	delete_feedback,
	is_feedback,
	get_feedback_comment,
};

defined( 'WPINC' ) || die();

/**
 * Class Test_SpeakerFeedback_Comment
 *
 * @group wordcamp-speaker-feedback
 */
class Test_SpeakerFeedback_Comment extends WP_UnitTestCase {
	/**
	 * @var WP_Post
	 */
	protected static $session_post;

	/**
	 * @var WP_User
	 */
	protected static $user;

	/**
	 * Set up shared fixtures for these tests.
	 *
	 * @param WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$session_post = $factory->post->create_and_get( array(
			'post_type' => 'wcb_session',
		) );

		self::$user = $factory->user->create_and_get( array(
			'role' => 'subscriber',
		) );
	}

	/**
	 * Reset after each test.
	 */
	protected function tearDown() : void {
		global $wpdb;

		$ids = $wpdb->get_col( "SELECT comment_ID FROM {$wpdb->prefix}comments" );

		// This ensures that only comments created during a given test exist in the database and cache during the test.
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}comments" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}commentmeta" );
		clean_comment_cache( $ids );

		parent::tearDown();
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\Comment\is_feedback()
	 */
	public function test_is_feedback() {
		$comment_object = self::factory()->comment->create_and_get( array(
			'comment_type' => COMMENT_TYPE,
		) );

		$this->assertTrue( is_feedback( $comment_object ) );
		$this->assertTrue( is_feedback( $comment_object->comment_ID ) );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\Comment\is_feedback()
	 */
	public function test_is_not_feedback() {
		$comment_object = self::factory()->comment->create_and_get();

		$this->assertFalse( is_feedback( $comment_object ) );
		$this->assertFalse( is_feedback( $comment_object->comment_ID ) );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\Comment\get_feedback_comment()
	 */
	public function test_get_feedback_comment() {
		$comment = self::factory()->comment->create_and_get( array(
			'comment_type' => COMMENT_TYPE,
		) );

		$feedback = get_feedback_comment( $comment );

		$this->assertTrue( $feedback instanceof Feedback );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\Comment\get_feedback_comment()
	 */
	public function test_get_feedback_comment_invalid() {
		$comment = self::factory()->comment->create_and_get();

		$feedback = get_feedback_comment( $comment );

		$this->assertNull( $feedback );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\Comment\add_feedback()
	 */
	public function test_add_feedback() {
		$comment_data = array(
			'comment_post_ID'      => self::$session_post->ID,
			'comment_author'       => 'Huck Finn',
			'comment_author_email' => 'huck.finn@example.org',
			'comment_meta'         => array(),
		);

		$comment_id = add_feedback( $comment_data );

		$comment = get_comment( $comment_id );

		$this->assertTrue( is_feedback( $comment ) );

		// Feedback default status is `hold`/`0`.
		$this->assertEquals( 0, $comment->comment_approved );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\Comment\add_feedback()
	 */
	public function test_add_feedback_missing_meta() {
		$comment_data = array(
			'comment_post_ID'      => self::$session_post->ID,
			'comment_author'       => 'Huck Finn',
			'comment_author_email' => 'huck.finn@example.org',
		);

		$comment_id = add_feedback( $comment_data );

		$this->assertFalse( $comment_id );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\Comment\update_feedback()
	 */
	public function test_update_feedback() {
		$comment_data = array(
			'comment_post_ID'      => self::$session_post->ID,
			'comment_author'       => 'Huck Finn',
			'comment_author_email' => 'huck.finn@example.org',
			'comment_meta'         => array(
				'rating' => 1,
			),
		);

		$comment_id = add_feedback( $comment_data );

		$feedback = get_feedback_comment( $comment_id );

		$this->assertEquals( 1, $feedback->rating );

		$new_meta = array(
			'rating' => 5,
		);

		update_feedback( $comment_id, $new_meta );

		$this->assertEquals( 5, $feedback->rating );
	}

	/**
	 * The assertions here assume that a filter has been applied to the comment query
	 * to exclude feedback comments unless they are specifically requested.
	 *
	 * @covers \WordCamp\SpeakerFeedback\Comment\get_feedback()
	 * @covers \WordCamp\SpeakerFeedback\Query\pre_get_comments()
	 */
	public function test_get_feedback_exclude_other_comments() {
		self::factory()->comment->create_many( 3 );
		self::factory()->comment->create_many(
			3,
			array(
				'comment_type' => COMMENT_TYPE,
			)
		);

		$standard_comments = get_comments();
		$feedback          = get_feedback();

		$this->assertCount( 3, $standard_comments );
		$this->assertCount( 3, $feedback );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\Comment\delete_feedback()
	 */
	public function test_delete_feedback() {
		$comment_data = array(
			'comment_post_ID'      => self::$session_post->ID,
			'comment_author'       => 'Huck Finn',
			'comment_author_email' => 'huck.finn@example.org',
			'comment_meta'         => array(),
		);

		$comment_id = add_feedback( $comment_data );

		$deleted = delete_feedback( $comment_id );

		$this->assertTrue( $deleted );
		$this->assertEquals( 'trash', get_comment( $comment_id )->comment_approved );
	}
}
