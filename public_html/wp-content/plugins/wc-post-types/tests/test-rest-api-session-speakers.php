<?php

namespace WordCamp\WC_Post_Types\Tests;

use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * Tests for the speakers the `session_speakers` REST field exposes.
 *
 * The field is served in the anonymous `view` context. Core's per-item read
 * check covers the session being listed, not the speaker posts this field
 * dereferences out of `_wcpt_speaker_id`.
 *
 * @group wc-post-types
 * @group rest-api
 */
class Test_Session_Speakers_Field extends WP_UnitTestCase {
	/**
	 * The session whose field is under test.
	 *
	 * @var int
	 */
	protected $session_id;

	/**
	 * Register the REST fields before the routes are queried.
	 */
	public static function wpSetUpBeforeClass(): void {
		do_action( 'rest_api_init' );
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

		// The meta holds bare post ids, so a stray one should not be returned
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
	 * Run the field's get_callback the way the REST controller does.
	 *
	 * `register_rest_field()` stores the callback in `$wp_rest_additional_fields`,
	 * which is where the controller reads it from when preparing a response. The
	 * full route is not exercised here because `rest_prepare_wcb_session` reaches
	 * into the theme-templates mu-plugin, which this suite does not load.
	 *
	 * @return array
	 */
	protected function get_speaker_names(): array {
		global $wp_rest_additional_fields;

		$callback = $wp_rest_additional_fields['wcb_session']['session_speakers']['get_callback'] ?? null;

		if ( ! $callback ) {
			$this->fail( 'Could not find the get_callback for the session_speakers field.' );
		}

		$speakers = call_user_func( $callback, array( 'id' => $this->session_id ), 'session_speakers', null );

		return wp_list_pluck( $speakers, 'name' );
	}

	/**
	 * Test that published speakers are still returned to a logged out caller.
	 */
	public function test_logged_out_gets_published_speaker() {
		wp_set_current_user( 0 );

		$this->assertContains( 'Published speaker', $this->get_speaker_names() );
	}

	/**
	 * Test that a logged out caller gets nothing it cannot read.
	 */
	public function test_logged_out_gets_only_the_published_speaker() {
		wp_set_current_user( 0 );

		$this->assertSame( array( 'Published speaker' ), $this->get_speaker_names() );
	}

	/**
	 * Test that a user who can read the speakers still gets all of them.
	 */
	public function test_editor_gets_every_speaker() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$names = $this->get_speaker_names();

		$this->assertContains( 'Published speaker', $names );
		$this->assertContains( 'Draft speaker', $names );
		$this->assertContains( 'Private speaker', $names );
	}

	/**
	 * Test that an id naming something other than a speaker is never returned.
	 */
	public function test_unrelated_post_type_is_never_returned() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertNotContains( 'Unrelated draft post', $this->get_speaker_names() );
	}
}
