<?php

namespace WordCamp\Helpers\Misc\Tests;
use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * @group mu-plugins
 * @group helpers
 * @group helpers-misc
 */
class Test_Helpers_Misc extends WP_UnitTestCase {
	/**
	 * A value that `sanitize_text_field()` leaves alone but `wp_kses()` would read as an element.
	 *
	 * The space after `<` is the point: `strip_tags()` wants a letter, `/`, `!` or `?` there, kses
	 * does not. The quotes are in it because `wptexturize()` skips the contents of a `code` element,
	 * so they would otherwise reach the page uncurled.
	 */
	const SPACED_TAG = 'Portland< code >" data-x="< /code >Oregon';

	/**
	 * @covers ::wcorg_sanitize_plain_text
	 *
	 * @dataProvider data_wcorg_sanitize_plain_text
	 */
	public function test_wcorg_sanitize_plain_text( $value, $expected ) {
		$this->assertSame( $expected, wcorg_sanitize_plain_text( $value ) );
	}

	/**
	 * Test cases for test_wcorg_sanitize_plain_text().
	 *
	 * @return array
	 */
	public function data_wcorg_sanitize_plain_text() {
		return array(
			'ordinary text is untouched' => array(
				'WordCamp Narnia',
				'WordCamp Narnia',
			),

			'real elements are stripped' => array(
				'<code>Narnia</code>',
				'Narnia',
			),

			'a space after the angle bracket does not carry an element through' => array(
				self::SPACED_TAG,
				'Portland&lt; code >" data-x="&lt; /code >Oregon',
			),

			'an element outside the kses allow-list is handled the same way' => array(
				'< pre >Narnia< /pre >',
				'&lt; pre >Narnia&lt; /pre >',
			),

			// `wp_kses( $value, array() )` deletes the whole `<...>` span here, returning `Hall  seats`.
			'an angle bracket pair does not swallow the text between it' => array(
				'Hall < 100 > seats',
				'Hall &lt; 100 > seats',
			),

			// Already-encoded input must not be decoded back into something kses can rebuild.
			'entity-encoded angle brackets are left encoded' => array(
				'&lt; code &gt;Narnia',
				'&lt; code &gt;Narnia',
			),

			'numeric character references are not decoded' => array(
				'&#60; code >Narnia',
				'&#60; code >Narnia',
			),

			'ampersands are left for the kses pass to normalize' => array(
				'Smith & Sons Hall',
				'Smith & Sons Hall',
			),

			'line breaks are collapsed' => array(
				"Narnia\nHall",
				'Narnia Hall',
			),

			'arrays are sanitized recursively' => array(
				array(
					'a' => '< code >x',
					'b' => array( '< code >y' ),
				),
				array(
					'a' => '&lt; code >x',
					'b' => array( '&lt; code >y' ),
				),
			),

			'non-string scalars are cast' => array(
				42,
				'42',
			),

			// `strip_tags()` alone deletes from an unterminated `<` to the end of the string.
			'an unterminated angle bracket does not truncate the value' => array(
				'Rated <A best',
				'Rated &lt;A best',
			),

			// `sanitize_text_field()` would delete these, quietly breaking a pasted URL.
			'percent-encoded sequences are preserved' => array(
				'See https://example.org/My%20Notes.pdf',
				'See https://example.org/My%20Notes.pdf',
			),

			// The wrapper is no less forgiving than the `sanitize_*_field()` it replaces.
			'a value that is neither array nor scalar becomes an empty string' => array(
				new \stdClass(),
				'',
			),
		);
	}

	/**
	 * Sanitizing an already-sanitized value must not change it again.
	 *
	 * @covers ::wcorg_sanitize_plain_text
	 */
	public function test_wcorg_sanitize_plain_text_is_idempotent() {
		$once = wcorg_sanitize_plain_text( self::SPACED_TAG );

		$this->assertSame( $once, wcorg_sanitize_plain_text( $once ) );
	}

	/**
	 * The point of the helper: nothing comes out that `wp_kses()` can read back as an element.
	 *
	 * `wp_filter_kses()` is what core registers on `title_save_pre` for users without
	 * `unfiltered_html`, so this is the transformation a stored title actually goes through.
	 *
	 * @covers ::wcorg_sanitize_plain_text
	 */
	public function test_wcorg_sanitize_plain_text_survives_the_kses_pass() {
		$values = array(
			self::SPACED_TAG,
			'< pre >Narnia< /pre >',
			'< a href="https://example.org" >Narnia< /a >',

			// Pre-encoded forms, in case anything upstream encodes before this runs.
			'&lt; code &gt;Narnia',
			'&#60; code >Narnia',
			'&amp;lt; code >Narnia',

			// A NUL between the bracket and the tag name.
			"< \0code >Narnia",
		);

		foreach ( $values as $value ) {
			$stored = wp_filter_kses( wcorg_sanitize_plain_text( $value ) );

			$this->assertFalse(
				( new \WP_HTML_Tag_Processor( $stored ) )->next_tag(),
				"Stored value read back as markup: $value"
			);
		}
	}

	/**
	 * `sanitize_text_field()` on its own does not hold, which is the reason this helper exists.
	 *
	 * Pinning that here means the suite notices if a future refactor drops the helper from a handler
	 * and goes back to the sanitiser alone.
	 *
	 * @covers ::wcorg_sanitize_plain_text
	 */
	public function test_sanitize_text_field_alone_is_not_enough() {
		$this->assertStringContainsString( '<code>', wp_filter_kses( sanitize_text_field( self::SPACED_TAG ) ) );
		$this->assertStringNotContainsString( '<code>', wp_filter_kses( wcorg_sanitize_plain_text( self::SPACED_TAG ) ) );
	}

	/**
	 * @covers ::wcorg_escape_shortcodes
	 *
	 * @dataProvider data_wcorg_escape_shortcodes
	 */
	public function test_wcorg_escape_shortcodes( $value, $expected ) {
		$this->assertSame( $expected, wcorg_escape_shortcodes( $value ) );
	}

	/**
	 * Data provider for `test_wcorg_escape_shortcodes()`.
	 *
	 * @return array
	 */
	public function data_wcorg_escape_shortcodes() {
		return array(
			'text without delimiters is untouched' => array(
				'Portland, Oregon',
				'Portland, Oregon',
			),
			'both delimiters are encoded'          => array(
				'[camptix_private]hello[/camptix_private]',
				'&#91;camptix_private&#93;hello&#91;/camptix_private&#93;',
			),
			'an unpaired delimiter still counts'   => array(
				'Rated [A best',
				'Rated &#91;A best',
			),
			'arrays are handled recursively'       => array(
				array(
					'a' => '[x]',
					'b' => 'y',
				),
				array(
					'a' => '&#91;x&#93;',
					'b' => 'y',
				),
			),
			'non-scalars become an empty string'   => array(
				new \stdClass(),
				'',
			),
			'integers keep their digits'           => array(
				42,
				'42',
			),
		);
	}

	/**
	 * Escaping an already-escaped value must not change it again.
	 *
	 * The write paths apply this on top of `wcorg_sanitize_plain_text()`, and some of them run
	 * twice over the same value, so a second pass has to be a no-op.
	 *
	 * @covers ::wcorg_escape_shortcodes
	 */
	public function test_wcorg_escape_shortcodes_is_idempotent() {
		$once = wcorg_escape_shortcodes( '[camptix_private]hello[/camptix_private]' );

		$this->assertSame( $once, wcorg_escape_shortcodes( $once ) );
	}

	/**
	 * The point of the helper: what comes out is not parsed as a shortcode.
	 *
	 * `wp_kses_post()` rewrites `&#91;` to the equivalent `&#091;`, so the assertion is that no
	 * delimiter survives rather than that a particular entity does.
	 *
	 * @covers ::wcorg_escape_shortcodes
	 */
	public function test_wcorg_escape_shortcodes_survives_the_kses_pass() {
		$escaped = wp_kses_post( wcorg_escape_shortcodes( '[caption width=1 caption=x]y[/caption]' ) );

		$this->assertStringNotContainsString( '[', $escaped );
		$this->assertStringNotContainsString( ']', $escaped );
		$this->assertSame( $escaped, do_shortcode( $escaped ) );
	}

	/**
	 * The stored form decodes back to what the submitter typed.
	 *
	 * The read sites that pre-fill a text input decode with `html_entity_decode()`, so the
	 * round trip has to hold for the delimiters too.
	 *
	 * @covers ::wcorg_escape_shortcodes
	 */
	public function test_wcorg_escape_shortcodes_round_trips() {
		$value = 'Rated [A best';

		$this->assertSame( $value, html_entity_decode( wcorg_escape_shortcodes( $value ) ) );
	}
}
