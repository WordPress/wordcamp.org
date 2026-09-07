<?php

namespace WordCamp\Tests;

use WP_UnitTestCase;
use function WordCamp\Blocks\Components\render_item_title;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/blocks/source/components/item/controller.php';

/**
 * Tests for the title the listing blocks render.
 *
 * `render_item_title()` is the one component the Sponsors, Speakers, Sessions and
 * Organizers blocks all route their title through, and those blocks are rendered
 * inside post content, which core parses for shortcodes at `the_content` priority 11.
 *
 * @group blocks
 */
class Test_Blocks_Item extends WP_UnitTestCase {
	/**
	 * A title that would run as a shortcode if it were emitted verbatim.
	 *
	 * @var string
	 */
	const SHORTCODE_TITLE = 'Acme [caption width=1 caption=x]y[/caption] Co';

	/**
	 * The rendered title is not parsed as a shortcode.
	 *
	 * @covers \WordCamp\Blocks\Components\render_item_title
	 */
	public function test_title_is_rendered_as_text() {
		$output = render_item_title( self::SHORTCODE_TITLE );

		$this->assertStringContainsString( 'Acme', $output );
		$this->assertStringContainsString( 'Co', $output );
		$this->assertStringNotContainsString( '[', $output );
		$this->assertStringNotContainsString( ']', $output );
		$this->assertSame( $output, do_shortcode( $output ) );
	}

	/**
	 * The encoding holds through the `wp_kses_post()` pass the block views apply.
	 *
	 * @covers \WordCamp\Blocks\Components\render_item_title
	 */
	public function test_title_survives_the_kses_pass() {
		$output = wp_kses_post( render_item_title( self::SHORTCODE_TITLE, 'https://example.org/acme/' ) );

		$this->assertStringNotContainsString( '[', $output );
		$this->assertSame( $output, do_shortcode( $output ) );
		$this->assertStringContainsString( 'https://example.org/acme/', $output );
	}

	/**
	 * A title that a store-time sanitiser already encoded is not encoded a second time.
	 *
	 * Stored titles carry `&lt;` for a `<` the submitter typed, see `wcorg_sanitize_plain_text()`.
	 * `esc_html()` here would turn that into `&amp;lt;` and show the entity on the page.
	 *
	 * @covers \WordCamp\Blocks\Components\render_item_title
	 */
	public function test_existing_entities_are_not_double_encoded() {
		$output = render_item_title( 'Hall &lt; 100 &gt; seats' );

		$this->assertStringContainsString( 'Hall &lt; 100 &gt; seats', $output );
	}
}
