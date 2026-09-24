<?php

namespace WordCamp\Tests;

use WP_Block;
use WP_Block_Type_Registry;
use WP_UnitTestCase;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/blocks/source/blocks/session-speakers/controller.php';

/**
 * Tests for the speakers the Session Speakers block lists.
 *
 * The block runs on the network's default `single-wcb_session` template, so
 * whatever it dereferences out of `_wcpt_speaker_id` is published on a public
 * page. Speaker records come from the public Call for Speakers form as drafts.
 *
 * @group blocks
 */
class Test_Session_Speakers_Block extends WP_UnitTestCase {
	/**
	 * The session the block renders for.
	 *
	 * @var int
	 */
	protected $session_id;

	/**
	 * Register the block, so that rendering it resolves its context and supports.
	 */
	public static function wpSetUpBeforeClass(): void {
		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( 'wordcamp/session-speakers' ) ) {
			\WordCamp\Blocks\SessionSpeakers\init();
		}
	}

	/**
	 * Create a session and attach one speaker of each status to it.
	 */
	public function set_up() {
		parent::set_up();

		$author_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->session_id = wp_insert_post( array(
			'post_type'   => 'wcb_session',
			'post_title'  => 'Test Session',
			'post_status' => 'publish',
			'post_author' => $author_id,
		) );

		$this->attach_speaker( 'Published speaker', 'publish', $author_id );
		$this->attach_speaker( 'Draft speaker', 'draft', $author_id );
		$this->attach_speaker( 'Private speaker', 'private', $author_id );

		// The meta holds bare post ids, so a stray one should not be printed
		// just because it exists.
		$unrelated_id = wp_insert_post( array(
			'post_type'   => 'post',
			'post_title'  => 'Unrelated draft post',
			'post_status' => 'draft',
			'post_author' => $author_id,
		) );
		add_post_meta( $this->session_id, '_wcpt_speaker_id', $unrelated_id );
	}

	/**
	 * Create a speaker and attach it to the test session.
	 *
	 * @param string $title  Speaker title.
	 * @param string $status Post status.
	 * @param int    $author Post author ID.
	 *
	 * @return int
	 */
	protected function attach_speaker( string $title, string $status, int $author ): int {
		$speaker_id = wp_insert_post( array(
			'post_type'   => 'wcb_speaker',
			'post_title'  => $title,
			'post_status' => $status,
			'post_author' => $author,
		) );

		add_post_meta( $this->session_id, '_wcpt_speaker_id', $speaker_id );

		return $speaker_id;
	}

	/**
	 * Render the block for the test session, the way the template does.
	 *
	 * @return string
	 */
	protected function render_block(): string {
		$block = new WP_Block(
			array(
				'blockName'    => 'wordcamp/session-speakers',
				'attrs'        => array(
					'byline' => 'Presented by',
					'isLink' => true,
				),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			),
			array( 'postId' => $this->session_id )
		);

		return $block->render();
	}

	/**
	 * Test that published speakers are still listed for a logged out visitor.
	 */
	public function test_logged_out_sees_published_speaker() {
		wp_set_current_user( 0 );

		$this->assertStringContainsString( 'Published speaker', $this->render_block() );
	}

	/**
	 * A speaker title is listed as text, not run through the shortcode parser.
	 *
	 * The block's output is concatenated into post content, which core parses at
	 * `the_content` priority 11.
	 */
	public function test_speaker_title_is_listed_as_text() {
		wp_set_current_user( 0 );

		$this->attach_speaker( 'Escaped [caption width=1]x[/caption] speaker', 'publish', 0 );

		$output = $this->render_block();

		$this->assertStringContainsString( 'Escaped', $output );
		$this->assertStringNotContainsString( '[caption', $output );
		$this->assertSame( $output, do_shortcode( $output ) );
	}

	/**
	 * Test that draft speakers are not listed for a logged out visitor.
	 */
	public function test_logged_out_does_not_see_draft_speaker() {
		wp_set_current_user( 0 );

		$this->assertStringNotContainsString( 'Draft speaker', $this->render_block() );
	}

	/**
	 * Test that private speakers are not listed for a logged out visitor.
	 */
	public function test_logged_out_does_not_see_private_speaker() {
		wp_set_current_user( 0 );

		$this->assertStringNotContainsString( 'Private speaker', $this->render_block() );
	}

	/**
	 * Test that an id naming something other than a speaker is not printed.
	 */
	public function test_logged_out_does_not_see_unrelated_post_type() {
		wp_set_current_user( 0 );

		$this->assertStringNotContainsString( 'Unrelated draft post', $this->render_block() );
	}

	/**
	 * Test that a session with nothing readable renders nothing at all.
	 *
	 * The byline is emitted before the speaker names, so returning early matters:
	 * otherwise the page shows "Presented by" followed by no one.
	 */
	public function test_logged_out_gets_nothing_when_no_speaker_is_readable() {
		$speaker_ids = get_post_meta( $this->session_id, '_wcpt_speaker_id', false );

		foreach ( $speaker_ids as $speaker_id ) {
			wp_update_post( array(
				'ID'          => $speaker_id,
				'post_status' => 'draft',
			) );
		}

		wp_set_current_user( 0 );

		$this->assertSame( '', $this->render_block() );
	}

	/**
	 * Test that a user who can read the speakers still sees all of them.
	 */
	public function test_editor_sees_every_speaker() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$rendered = $this->render_block();

		$this->assertStringContainsString( 'Published speaker', $rendered );
		$this->assertStringContainsString( 'Draft speaker', $rendered );
		$this->assertStringContainsString( 'Private speaker', $rendered );
	}
}
