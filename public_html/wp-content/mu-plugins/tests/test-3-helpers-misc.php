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

			'line breaks are collapsed by default' => array(
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
}
