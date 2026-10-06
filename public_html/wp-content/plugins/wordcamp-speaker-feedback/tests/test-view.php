<?php

namespace WordCamp\SpeakerFeedback\Tests;

use WP_Post;
use WP_UnitTestCase, WP_UnitTest_Factory;
use const WordCamp\SpeakerFeedback\Comment\COMMENT_TYPE;
use function WordCamp\SpeakerFeedback\View\{ render_feedback_comment, sanitize_answer_for_display };

defined( 'WPINC' ) || die();

/**
 * Class Test_SpeakerFeedback_View
 *
 * @group wordcamp-speaker-feedback
 */
class Test_SpeakerFeedback_View extends WP_UnitTestCase {
	/**
	 * @var WP_Post
	 */
	protected static $session_post;

	/**
	 * Set up shared fixtures for these tests.
	 *
	 * @param WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$session_post = $factory->post->create_and_get( array(
			'post_type' => 'wcb_session',
		) );
	}

	/**
	 * Reset after each test.
	 */
	protected function tearDown(): void {
		global $wpdb;

		$ids = $wpdb->get_col( "SELECT comment_ID FROM {$wpdb->prefix}comments" );

		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}comments" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}commentmeta" );
		clean_comment_cache( $ids );

		parent::tearDown();
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\View\sanitize_answer_for_display()
	 */
	public function test_sanitize_answer_for_display_keeps_the_text_readable() {
		$this->assertSame(
			'It was great! I liked the &#91;demo&#93; part.',
			sanitize_answer_for_display( 'It was great! I liked the [demo] part.' )
		);
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\View\sanitize_answer_for_display()
	 */
	public function test_sanitize_answer_for_display_encodes_delimiters() {
		$answer = '[caption width=1 caption=\x3ca\x20id=x\x3eY\x3c/a\x3e]Z[/caption]';

		$sanitized = sanitize_answer_for_display( $answer );

		$this->assertStringNotContainsString( '[', $sanitized );
		$this->assertStringNotContainsString( ']', $sanitized );
		$this->assertSame( $sanitized, do_shortcode( $sanitized ) );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\View\render_feedback_comment()
	 */
	public function test_rendered_answers_survive_a_later_shortcode_pass() {
		$comment_id = self::factory()->comment->create( array(
			'comment_post_ID' => self::$session_post->ID,
			'comment_type'    => COMMENT_TYPE,
			'comment_meta'    => array(
				'version' => 1,
				'rating'  => 5,
				'q1'      => '[caption width=1 caption=\x3ca\x20id=x\x3eY\x3c/a\x3e]Z[/caption]',
			),
		) );

		$output = render_feedback_comment( $comment_id, false );

		$this->assertStringContainsString( 'speaker-feedback__answer', $output );
		$this->assertSame( $output, do_shortcode( $output ) );
		$this->assertStringNotContainsString( '<a ', do_shortcode( $output ) );
	}

	/**
	 * The rendered view is appended to `the_content`, so it must land after core's shortcode pass.
	 *
	 * @covers \WordCamp\SpeakerFeedback\View\render()
	 */
	public function test_render_runs_after_do_shortcode() {
		$this->assertGreaterThan(
			has_filter( 'the_content', 'do_shortcode' ),
			has_filter( 'the_content', 'WordCamp\SpeakerFeedback\View\render' )
		);
	}
}
