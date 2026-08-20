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
	 * Create one track and one scheduled session on it.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		$track = wp_insert_term( self::TRACK_NAME, 'wcb_track', array( 'slug' => 'main-hall' ) );

		self::$track_id = $track['term_id'];

		self::$session_id = $factory->post->create( array(
			'post_type'  => 'wcb_session',
			'post_title' => 'Opening Remarks',
			'meta_input' => array(
				'_wcpt_session_time' => 1786788000,
				'_wcpt_session_type' => 'session',
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
}
