<?php

namespace WordCamp\WC_Post_Types\Tests;

use WP_UnitTestCase, WP_UnitTest_Factory;

defined( 'WPINC' ) || die();

/**
 * Tests for the markup that `[schedule]` builds.
 *
 * The grid is assembled by hand with `sprintf()` rather than by a template, so
 * these cover that each value taken from user-entered content -- track names,
 * session titles and speaker names -- is escaped for the position it lands in.
 *
 * @group wc-post-types
 */
class Test_Schedule_Shortcode extends WP_UnitTestCase {
	/**
	 * A track name holding the characters that matter in an attribute value.
	 *
	 * Term names keep double quotes verbatim: core's `pre_term_name` filters
	 * run `_wp_specialchars()` with the default `ENT_NOQUOTES`, so `<` and `>`
	 * are encoded on save but `"` is not.
	 */
	const TRACK_NAME = 'Main Hall" data-extra="1';

	/**
	 * A session title holding markup that survives `wp_filter_kses()`.
	 *
	 * `title_save_pre` runs post titles through kses, not through an entity
	 * encoder, so any tag in the small `$allowedtags` set (`em`, `code`, `a`,
	 * ...) is stored as-is and reaches the renderer intact.
	 */
	const SESSION_TITLE = 'Opening <em>Remarks</em>';

	/**
	 * A speaker name holding the same kses-permitted markup.
	 */
	const SPEAKER_NAME = 'Ada <em>Lovelace</em>';

	/**
	 * Track term ID.
	 *
	 * @var int
	 */
	protected static $track_id;

	/**
	 * Session post ID.
	 *
	 * @var int
	 */
	protected static $session_id;

	/**
	 * Speaker post ID.
	 *
	 * @var int
	 */
	protected static $speaker_id;

	/**
	 * Create one track, one speaker, and one scheduled session joining them.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		$track = wp_insert_term( self::TRACK_NAME, 'wcb_track', array( 'slug' => 'main-hall' ) );

		self::$track_id = $track['term_id'];

		self::$speaker_id = $factory->post->create( array(
			'post_type'  => 'wcb_speaker',
			'post_title' => self::SPEAKER_NAME,
		) );

		self::$session_id = $factory->post->create( array(
			'post_type'  => 'wcb_session',
			'post_title' => self::SESSION_TITLE,
			'meta_input' => array(
				'_wcpt_session_time' => 1786788000,
				'_wcpt_session_type' => 'session',
				'_wcpt_speaker_id'   => self::$speaker_id,
			),
		) );

		wp_set_object_terms( self::$session_id, array( self::$track_id ), 'wcb_track' );
	}

	/**
	 * Render the schedule grid.
	 *
	 * @param array $attributes Shortcode attributes to override.
	 */
	protected function render_schedule( array $attributes = array() ): string {
		return $GLOBALS['wcpt_plugin']->shortcode_schedule( $attributes, '' );
	}

	/**
	 * The favourites email is `text/plain`, so entity-encoded titles are decoded
	 * for it -- the speaker name the same way the session title beside it is.
	 *
	 * Submitted titles are stored entity-encoded, so `Ada &#91;Lovelace&#93;` in the
	 * database has to read as `Ada [Lovelace]` in the mail rather than as its entities.
	 *
	 * @covers ::generate_email_body
	 */
	public function test_email_body_decodes_entities_in_titles(): void {
		$speaker_id = self::factory()->post->create( array(
			'post_type'  => 'wcb_speaker',
			'post_title' => 'Ada &#91;Lovelace&#93;',
		) );

		$session_id = self::factory()->post->create( array(
			'post_type'  => 'wcb_session',
			'post_title' => 'Closing &#91;Remarks&#93;',
			'meta_input' => array(
				'_wcpt_session_time' => 1786791600,
				'_wcpt_session_type' => 'session',
				'_wcpt_speaker_id'   => $speaker_id,
			),
		) );

		wp_set_object_terms( $session_id, array( self::$track_id ), 'wcb_track' );

		$body = generate_email_body( 'WordCamp Test', array( $session_id => 1 ), 'https://example.org/schedule/' );

		$this->assertStringContainsString( 'Closing [Remarks]', $body );
		$this->assertStringContainsString( 'Ada [Lovelace]', $body );
		$this->assertStringNotContainsString( '&#91;', $body );
	}

	/**
	 * The track name lands in the `data-track-title` attribute, so a double
	 * quote in it has to be encoded rather than closing the attribute early.
	 *
	 * @covers WordCamp_Post_Types_Plugin::shortcode_schedule
	 */
	public function test_track_name_is_escaped_in_the_cell_attribute(): void {
		$html = $this->render_schedule();

		$this->assertStringContainsString(
			'data-track-title="' . esc_attr( self::TRACK_NAME ) . '"',
			$html
		);

		// With the quote encoded, the rest of the name stays inside the value
		// instead of becoming a second attribute on the cell.
		$this->assertStringNotContainsString( 'data-extra="1"', $html );
	}

	/**
	 * The same track name is displayed as text in the column heading, where it
	 * was already escaped -- kept as a regression guard on the pair.
	 *
	 * @covers WordCamp_Post_Types_Plugin::shortcode_schedule
	 */
	public function test_track_name_is_escaped_in_the_column_heading(): void {
		$html = $this->render_schedule();

		$this->assertStringContainsString(
			'<span class="wcpt-track-name">' . esc_html( self::TRACK_NAME ) . '</span>',
			$html
		);
	}

	/**
	 * The session title is displayed as text, both in the cell and in the
	 * favorite-session button's screen-reader label.
	 *
	 * @covers WordCamp_Post_Types_Plugin::shortcode_schedule
	 */
	public function test_session_title_is_escaped(): void {
		$html = $this->render_schedule();

		$this->assertStringContainsString( esc_html( self::SESSION_TITLE ), $html );
		$this->assertStringContainsString(
			sprintf( 'Favorite session: %s', esc_html( self::SESSION_TITLE ) ),
			$html
		);
		$this->assertStringNotContainsString( '<em>', $html );
	}

	/**
	 * The session title is displayed as text in the `session_link="none"`
	 * variant too, which uses a `<span>` instead of an anchor.
	 *
	 * @covers WordCamp_Post_Types_Plugin::shortcode_schedule
	 */
	public function test_session_title_is_escaped_without_a_link(): void {
		$html = $this->render_schedule( array( 'session_link' => 'none' ) );

		$this->assertStringContainsString(
			'<span class="wcpt-session-title">' . esc_html( self::SESSION_TITLE ) . '</span>',
			$html
		);
		$this->assertStringNotContainsString( '<em>', $html );
	}

	/**
	 * Speaker names are displayed as text whether or not they are wrapped in a
	 * link, so the escaping can't live in the linked branch alone.
	 *
	 * @covers WordCamp_Post_Types_Plugin::shortcode_schedule
	 *
	 * @dataProvider data_speaker_link_variants
	 */
	public function test_speaker_name_is_escaped( string $speaker_link ): void {
		$html = $this->render_schedule( array( 'speaker_link' => $speaker_link ) );

		$this->assertStringContainsString( esc_html( self::SPEAKER_NAME ), $html );
		$this->assertStringNotContainsString( '<em>', $html );
	}

	/**
	 * Test cases for test_speaker_name_is_escaped().
	 */
	public function data_speaker_link_variants(): array {
		return array(
			'linked names'   => array( 'permalink' ),
			'unlinked names' => array( 'none' ),
		);
	}

	/**
	 * A private session is left out of the grid whoever renders it. `[schedule]`
	 * is not cached itself, but authored into a widgetised bio it renders into a
	 * fragment the listing widgets serve to every visitor.
	 *
	 * @covers WordCamp_Post_Types_Plugin::shortcode_schedule
	 */
	public function test_private_session_is_omitted_for_an_administrator(): void {
		$private_id = self::factory()->post->create( array(
			'post_type'  => 'wcb_session',
			'post_status' => 'private',
			'post_title' => 'Unannounced Keynote',
			'meta_input' => array(
				'_wcpt_session_time' => 1786788000,
				'_wcpt_session_type' => 'session',
			),
		) );
		wp_set_object_terms( $private_id, array( self::$track_id ), 'wcb_track' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$html = $this->render_schedule();

		$this->assertStringContainsString( 'Opening', $html );
		$this->assertStringNotContainsString( 'Unannounced Keynote', $html );

		wp_set_current_user( 0 );
		wp_delete_post( $private_id, true );
	}
}
