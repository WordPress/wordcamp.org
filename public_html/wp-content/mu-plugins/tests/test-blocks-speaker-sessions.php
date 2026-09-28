<?php

namespace WordCamp\Tests;

use WP_Block;
use WP_Block_Type_Registry;
use WP_UnitTestCase;
use function WordCamp\Blocks\SpeakerSessions\get_readable_session_statuses;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/blocks/source/blocks/speaker-sessions/controller.php';

/**
 * Tests for the sessions the Speaker Sessions block lists.
 *
 * The block runs on the network's default `single-wcb_speaker` template, so
 * whatever its query returns is published on a public page.
 *
 * @group blocks
 */
class Test_Speaker_Sessions_Block extends WP_UnitTestCase {
	/**
	 * A speaker for the sessions to hang off.
	 *
	 * @var int
	 */
	protected $speaker_id;

	/**
	 * Register the block, so that rendering it resolves its context and supports.
	 */
	public static function wpSetUpBeforeClass(): void {
		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( 'wordcamp/speaker-sessions' ) ) {
			\WordCamp\Blocks\SpeakerSessions\init();
		}
	}

	/**
	 * Create a speaker and attach one session of each status to it.
	 */
	public function set_up() {
		parent::set_up();

		$author_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->speaker_id = wp_insert_post( array(
			'post_type'   => 'wcb_speaker',
			'post_title'  => 'Test Speaker',
			'post_status' => 'publish',
			'post_author' => $author_id,
		) );

		$this->add_session( 'Published session', 'publish', $author_id );
		$this->add_session( 'Private session', 'private', $author_id );

		// A session nobody owns. `perm => 'readable'` scopes private posts by
		// post_author, and 0 matches a logged out visitor, so this is the case
		// an author-based check lets through.
		$this->add_session( 'Unowned private session', 'private', 0 );
	}

	/**
	 * Create a session attached to the test speaker.
	 *
	 * @param string $title  Session title.
	 * @param string $status Post status.
	 * @param int    $author Post author ID.
	 *
	 * @return int
	 */
	protected function add_session( string $title, string $status, int $author ): int {
		$session_id = wp_insert_post( array(
			'post_type'   => 'wcb_session',
			'post_title'  => $title,
			'post_status' => $status,
			'post_author' => $author,
		) );

		add_post_meta( $session_id, '_wcpt_speaker_id', $this->speaker_id );

		return $session_id;
	}

	/**
	 * Render the block for the test speaker, the way the template does.
	 *
	 * @return string
	 */
	protected function render_block(): string {
		$block = new WP_Block(
			array(
				'blockName'    => 'wordcamp/speaker-sessions',
				'attrs'        => array(
					'hasSessionDetails' => true,
					'isLink'            => true,
				),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			),
			array( 'postId' => $this->speaker_id )
		);

		return $block->render();
	}

	/**
	 * A session title is listed as text, not run through the shortcode parser.
	 *
	 * The block's output is concatenated into post content, which core parses at
	 * `the_content` priority 11.
	 */
	public function test_session_title_is_listed_as_text() {
		wp_set_current_user( 0 );

		$this->add_session( 'Escaped [caption width=1]x[/caption] session', 'publish', 0 );

		$output = $this->render_block();

		$this->assertStringContainsString( 'Escaped', $output );
		$this->assertStringNotContainsString( '[caption', $output );
		$this->assertSame( $output, do_shortcode( $output ) );
	}

	/**
	 * Test that a logged out visitor is only asked about published sessions.
	 */
	public function test_logged_out_gets_published_status_only() {
		wp_set_current_user( 0 );

		$this->assertSame( array( 'publish' ), get_readable_session_statuses() );
	}

	/**
	 * Test that a user with read_private_posts is asked about private sessions too.
	 */
	public function test_editor_gets_private_status() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertContains( 'private', get_readable_session_statuses() );
	}

	/**
	 * Test that published sessions are still listed for a logged out visitor.
	 *
	 * Logged out visitors hold no capabilities at all, so a per-post
	 * `current_user_can( 'read_post' )` check drops published sessions unless
	 * the published status is allowed for first.
	 */
	public function test_logged_out_sees_published_session() {
		wp_set_current_user( 0 );

		$this->assertStringContainsString( 'Published session', $this->render_block() );
	}

	/**
	 * Test that private sessions are not listed for a logged out visitor.
	 */
	public function test_logged_out_does_not_see_private_session() {
		wp_set_current_user( 0 );

		$this->assertStringNotContainsString( 'Private session', $this->render_block() );
	}

	/**
	 * Test that a private session with no author is not listed either.
	 */
	public function test_logged_out_does_not_see_unowned_private_session() {
		wp_set_current_user( 0 );

		$this->assertStringNotContainsString( 'Unowned private session', $this->render_block() );
	}

	/**
	 * Test that a user with read_private_posts still sees every session.
	 */
	public function test_editor_sees_all_sessions() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$rendered = $this->render_block();

		$this->assertStringContainsString( 'Published session', $rendered );
		$this->assertStringContainsString( 'Private session', $rendered );
		$this->assertStringContainsString( 'Unowned private session', $rendered );
	}
}
