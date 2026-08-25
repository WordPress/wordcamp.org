<?php

namespace WordCamp\WC_Post_Types\Tests;

use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * Tests for the private meta the admin list-table columns print.
 *
 * @group wc-post-types
 */
class Test_Private_Column_Meta extends WP_UnitTestCase {

	/**
	 * @covers WordCamp_Post_Types_Plugin::manage_post_types_columns_output
	 */
	public function test_speaker_email_is_hidden_from_a_contributor() {
		$speaker = $this->create_speaker( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$this->assertSame( '', $this->render_column( 'wcb_speaker_email', $speaker ) );
	}

	/**
	 * The organizers who run the camp still need the column.
	 *
	 * @covers WordCamp_Post_Types_Plugin::manage_post_types_columns_output
	 */
	public function test_speaker_email_is_shown_to_an_editor() {
		$editor  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$speaker = $this->create_speaker( $editor );

		wp_set_current_user( $editor );

		$this->assertSame( 'speaker@example.org', $this->render_column( 'wcb_speaker_email', $speaker ) );
	}

	/**
	 * The check is per row, not per role. The call-for-speakers form authors its drafts as
	 * the shared support user, so this covers the records organizers add by hand.
	 *
	 * @covers WordCamp_Post_Types_Plugin::manage_post_types_columns_output
	 */
	public function test_speaker_email_is_shown_on_the_contributors_own_draft() {
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$speaker     = $this->create_speaker( $contributor, 'draft' );

		wp_set_current_user( $contributor );

		$this->assertSame( 'speaker@example.org', $this->render_column( 'wcb_speaker_email', $speaker ) );
	}

	/**
	 * @covers WordCamp_Post_Types_Plugin::manage_post_types_columns_output
	 */
	public function test_sponsor_amount_is_hidden_from_a_contributor() {
		$sponsor = $this->create_sponsor( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$this->assertSame( '', $this->render_column( 'wcb_sponsor_amount', $sponsor ) );
	}

	/**
	 * @covers WordCamp_Post_Types_Plugin::manage_post_types_columns_output
	 */
	public function test_sponsor_amount_is_shown_to_an_editor() {
		$editor  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$sponsor = $this->create_sponsor( $editor );

		wp_set_current_user( $editor );

		$this->assertSame( '5000 USD', $this->render_column( 'wcb_sponsor_amount', $sponsor ) );
	}

	/**
	 * The Sessions screen dereferences speaker posts, and `post_status => any` picks up
	 * the private ones that the Speakers screen withholds from the same viewer.
	 *
	 * @covers WordCamp_Post_Types_Plugin::manage_post_types_columns_output
	 */
	public function test_private_speaker_is_hidden_from_a_session_row() {
		$editor  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$session = $this->create_session( $editor, $this->create_speaker( $editor, 'private' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$this->assertSame( '', $this->render_column( 'wcb_session_speakers', $session ) );
	}

	/**
	 * The Speakers screen lists drafts to everyone who can open it, so the session row has
	 * to keep listing them too. `read_post` on a draft falls through to `edit_others_posts`,
	 * which would have hidden them.
	 *
	 * @covers WordCamp_Post_Types_Plugin::manage_post_types_columns_output
	 */
	public function test_draft_speaker_is_still_shown_on_a_session_row() {
		$editor  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$speaker = $this->create_speaker( $editor, 'draft' );
		$session = $this->create_session( $editor, $speaker );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$rendered = $this->render_column( 'wcb_session_speakers', $session );

		$this->assertStringContainsString( get_the_title( $speaker ), $rendered );

		// `get_edit_post_link()` is null for a viewer who cannot edit, and `esc_url( null )`
		// is deprecated. Name it rather than linking nowhere.
		$this->assertStringNotContainsString( '<a href', $rendered );
	}

	/**
	 * The organizers who can read the record still get it, which is also what proves the
	 * query returns private speakers at all.
	 *
	 * @covers WordCamp_Post_Types_Plugin::manage_post_types_columns_output
	 */
	public function test_private_speaker_is_shown_to_an_editor() {
		$editor  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$speaker = $this->create_speaker( $editor, 'private' );
		$session = $this->create_session( $editor, $speaker );

		wp_set_current_user( $editor );

		$this->assertStringContainsString(
			get_the_title( $speaker ),
			$this->render_column( 'wcb_session_speakers', $session )
		);
	}

	/**
	 * Capture one column of one row.
	 *
	 * @param string $column  The column to render.
	 * @param int    $post_id The row to render it for.
	 */
	protected function render_column( $column, $post_id ): string {
		ob_start();
		$GLOBALS['wcpt_plugin']->manage_post_types_columns_output( $column, $post_id );

		return ob_get_clean();
	}

	/**
	 * @param int $author_id  The session record's author.
	 * @param int $speaker_id The speaker to attach.
	 */
	protected function create_session( $author_id, $speaker_id ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'wcb_session',
				'post_author' => $author_id,
				'meta_input'  => array( '_wcpt_speaker_id' => $speaker_id ),
			)
		);
	}

	/**
	 * @param int    $author_id The speaker record's author.
	 * @param string $status    The record's post status.
	 */
	protected function create_speaker( $author_id, $status = 'publish' ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'wcb_speaker',
				'post_status' => $status,
				'post_author' => $author_id,
				'meta_input'  => array( '_wcb_speaker_email' => 'speaker@example.org' ),
			)
		);
	}

	/**
	 * @param int $author_id The sponsor record's author.
	 */
	protected function create_sponsor( $author_id ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'wcb_sponsor',
				'post_author' => $author_id,
				'meta_input'  => array(
					'_wcb_sponsor_amount'   => '5000',
					'_wcb_sponsor_currency' => 'USD',
				),
			)
		);
	}
}
