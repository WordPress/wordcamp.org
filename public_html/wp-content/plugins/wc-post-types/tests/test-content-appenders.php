<?php

namespace WordCamp\WC_Post_Types\Tests;

use WordCamp_Post_Types_Plugin;
use WP_UnitTestCase;

defined( 'WPINC' ) || die();

// Two of the callbacks call `has_block_with_attrs()`, which lives in the
// blocks mu-plugin and is always loaded alongside this plugin in production.
if ( ! function_exists( 'WordCamp\Blocks\has_block_with_attrs' ) ) {
	require_once SUT_WPMU_PLUGIN_DIR . '/blocks/blocks.php';
}

/**
 * Tests for the material appended to a single session's or speaker's content.
 *
 * These callbacks run on `the_content`, which core applies to the password
 * form it substitutes for a protected post's body.
 *
 * @group wc-post-types
 */
class Test_Content_Appenders extends WP_UnitTestCase {
	/**
	 * The plugin instance whose callbacks are under test.
	 *
	 * @var WordCamp_Post_Types_Plugin
	 */
	protected $plugin;

	/**
	 * Boot the plugin and open the site-id gates the callbacks check.
	 */
	public function set_up() {
		parent::set_up();

		$this->plugin = new WordCamp_Post_Types_Plugin();

		// `is_single_cpt_post()` reads the post type's own query var, which a
		// plain permalink carries directly. Set the structure here rather than
		// inheriting whatever an earlier test left behind -- a pretty one needs
		// rewrite rules this run doesn't have, and `go_to()` would then match
		// nothing. Core restores the original in `tear_down()`.
		$this->set_permalink_structure( '' );

		// `go_to()` fires `wp`, where this mu-plugin looks up the camp's other
		// sites. Those don't exist in the test network, and the failed queries
		// are only noise here.
		remove_action( 'wp', 'WordCamp\\Latest_Site_Hints\\maybe_add_latest_site_hints' );

		// The callbacks are limited to sites created after they shipped. This
		// site's id is far below those defaults, so open them for the test.
		foreach ( array(
			'wcpt_speaker_post_avatar_min_site_id',
			'wcpt_session_post_speaker_info_min_site_id',
			'wcpt_session_post_slides_info_min_site_id',
			'wcpt_session_post_video_info_min_site_id',
			'wcpt_speaker_post_session_info_min_site_id',
		) as $filter ) {
			add_filter( $filter, '__return_zero' );
		}
	}

	/**
	 * Clear the password cookie, so a test that fails part way through cannot
	 * leave a later one thinking the visitor holds the password.
	 */
	public function tear_down() {
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );

		parent::tear_down();
	}

	/**
	 * Hold the password the way `wp-login.php?action=postpass` does.
	 *
	 * @param string $password The post's password.
	 */
	private function hold_the_password( string $password ) {
		require_once ABSPATH . WPINC . '/class-phpass.php';
		$hasher = new \PasswordHash( 8, true );

		$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = $hasher->HashPassword( $password );
	}

	/**
	 * Create a session with a speaker, slides, video and a category, then make
	 * it the queried post the way a request for its permalink would.
	 *
	 * @param array $args Extra arguments for the session.
	 * @return int The session's ID.
	 */
	private function go_to_session( array $args = array() ): int {
		$speaker_id = self::factory()->post->create( array(
			'post_type'   => 'wcb_speaker',
			'post_status' => 'publish',
			'post_title'  => 'Speaker Name',
		) );

		$session_id = self::factory()->post->create( array_merge(
			array(
				'post_type'    => 'wcb_session',
				'post_status'  => 'publish',
				'post_title'   => 'Session Title',
				'post_content' => 'Session body.',
			),
			$args
		) );

		add_post_meta( $session_id, '_wcpt_speaker_id', $speaker_id );
		add_post_meta( $session_id, '_wcpt_session_slides', 'https://example.org/slides' );
		// The meta is sanitized to '' unless the host is wordpress.tv.
		add_post_meta( $session_id, '_wcpt_session_video', 'https://wordpress.tv/session-video' );

		wp_set_object_terms( $session_id, 'Unannounced Track', 'wcb_session_category' );

		$this->go_to( get_permalink( $session_id ) );

		return $session_id;
	}

	/**
	 * Run every session callback over the content the post would render with.
	 *
	 * @param int    $session_id The session.
	 * @param string $content    The content core would hand the filters.
	 * @return string
	 */
	private function apply_session_appenders( int $session_id, string $content ): string {
		foreach ( array(
			'add_speaker_info_to_session_posts',
			'add_slides_info_to_session_posts',
			'add_video_info_to_session_posts',
			'add_session_categories_to_session_posts',
		) as $callback ) {
			$content = $this->plugin->$callback( $content );
		}

		return $content;
	}

	/**
	 * The ordinary case: everything is appended to a public session.
	 */
	public function test_appends_to_a_public_session() {
		$session_id = $this->go_to_session();

		$output = $this->apply_session_appenders( $session_id, 'Session body.' );

		$this->assertStringContainsString( 'Speaker Name', $output );
		$this->assertStringContainsString( 'https://example.org/slides', $output );
		$this->assertStringContainsString( 'https://wordpress.tv/session-video', $output );
		$this->assertStringContainsString( 'Unannounced Track', $output );
	}

	/**
	 * The spliced speaker name is appended as text, not run through the shortcode parser.
	 *
	 * These callbacks are registered on `the_content` at priority 10 and core parses
	 * shortcodes at 11, so whatever they append is handed to the parser afterwards.
	 */
	public function test_appended_speaker_name_is_text() {
		$session_id = $this->go_to_session();

		$speaker_id = self::factory()->post->create( array(
			'post_type'   => 'wcb_speaker',
			'post_status' => 'publish',
			'post_title'  => 'Escaped [caption width=1 caption=x]y[/caption] Speaker',
		) );
		add_post_meta( $session_id, '_wcpt_speaker_id', $speaker_id );

		$output = $this->apply_session_appenders( $session_id, 'Session body.' );

		$this->assertStringContainsString( 'Escaped', $output );
		$this->assertStringNotContainsString( '[caption', $output );
		$this->assertSame( $output, do_shortcode( $output ) );
	}

	/**
	 * A protected session keeps the password form on its own. Core has already
	 * replaced the body by the time these run, so appending would put the
	 * session's own material back beneath the form.
	 */
	public function test_appends_nothing_to_a_protected_session() {
		$session_id = $this->go_to_session( array( 'post_password' => 'hunter2' ) );

		$form   = get_the_password_form( $session_id );
		$output = $this->apply_session_appenders( $session_id, $form );

		$this->assertSame( $form, $output );
		$this->assertStringNotContainsString( 'Speaker Name', $output );
		$this->assertStringNotContainsString( 'https://example.org/slides', $output );
		$this->assertStringNotContainsString( 'https://wordpress.tv/session-video', $output );
		$this->assertStringNotContainsString( 'Unannounced Track', $output );
	}

	/**
	 * A visitor holding the password sees the appended material again.
	 */
	public function test_appends_to_a_protected_session_for_the_password_holder() {
		$session_id = $this->go_to_session( array( 'post_password' => 'hunter2' ) );

		$this->hold_the_password( 'hunter2' );

		$output = $this->apply_session_appenders( $session_id, 'Session body.' );

		$this->assertStringContainsString( 'Speaker Name', $output );
		$this->assertStringContainsString( 'https://wordpress.tv/session-video', $output );
	}

	/**
	 * Create a speaker with a session referencing them, then make the speaker
	 * the queried post the way a request for its permalink would.
	 *
	 * @param array $args Extra arguments for the speaker.
	 * @return int The speaker's ID.
	 */
	private function go_to_speaker( array $args = array() ): int {
		$speaker_id = self::factory()->post->create( array_merge(
			array(
				'post_type'   => 'wcb_speaker',
				'post_status' => 'publish',
				'post_title'  => 'Speaker Name',
			),
			$args
		) );

		$session_id = self::factory()->post->create( array(
			'post_type'   => 'wcb_session',
			'post_status' => 'publish',
			'post_title'  => 'Unannounced Session',
		) );

		add_post_meta( $session_id, '_wcpt_speaker_id', $speaker_id );

		$this->go_to( get_permalink( $speaker_id ) );

		return $speaker_id;
	}

	/**
	 * Run the speaker callbacks in the order they are hooked, so the avatar
	 * goes above the content and the session list below it.
	 *
	 * @param string $content The content core would hand the filters.
	 * @return string
	 */
	private function apply_speaker_appenders( string $content ): string {
		$content = $this->plugin->add_avatar_to_speaker_posts( $content );

		return $this->plugin->add_session_info_to_speaker_posts( $content );
	}

	/**
	 * The ordinary case: both callbacks add their markup to a public speaker.
	 *
	 * Without this, the negative test below could pass because the callbacks
	 * never ran at all rather than because the password stopped them.
	 */
	public function test_appends_to_a_public_speaker() {
		$this->go_to_speaker();

		$output = $this->apply_speaker_appenders( 'Speaker bio.' );

		$this->assertStringContainsString( 'speaker-avatar', $output );
		$this->assertStringContainsString( 'Unannounced Session', $output );
	}

	/**
	 * The speaker-side callbacks behave the same way as the session ones.
	 */
	public function test_appends_nothing_to_a_protected_speaker() {
		$speaker_id = $this->go_to_speaker( array( 'post_password' => 'hunter2' ) );

		$form   = get_the_password_form( $speaker_id );
		$output = $this->apply_speaker_appenders( $form );

		$this->assertSame( $form, $output );
		$this->assertStringNotContainsString( 'Unannounced Session', $output );
		$this->assertStringNotContainsString( 'speaker-avatar', $output );
	}

	/**
	 * A visitor holding the speaker's password sees both again.
	 */
	public function test_appends_to_a_protected_speaker_for_the_password_holder() {
		$this->go_to_speaker( array( 'post_password' => 'hunter2' ) );

		$this->hold_the_password( 'hunter2' );

		$output = $this->apply_speaker_appenders( 'Speaker bio.' );

		$this->assertStringContainsString( 'speaker-avatar', $output );
		$this->assertStringContainsString( 'Unannounced Session', $output );
	}
}
