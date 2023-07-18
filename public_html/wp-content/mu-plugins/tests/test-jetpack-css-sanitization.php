<?php

namespace WordCamp\Tests;

use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * Class Test_Jetpack_CSS_Sanitization
 *
 * @group mu-plugins
 * @group jetpack-tweaks
 * @group jetpack-css
 *
 * @package WordCamp\Tests
 */
class Test_Jetpack_CSS_Sanitization extends WP_UnitTestCase {

	/**
	 * Test that no selector characters are encoded.
	 */
	public function test_selectors_not_encoded() {
		$input = <<<CSS
.class > p,
p ~ span,
h2 + p,
col || td,
.pseudo:visited,
.pseudo::before,
[title],
a[href="https://example.org"],
a[title*='an example'],
span[data-emoji~=🐈‍⬛]
span[attr$="한글"] {
	color: lightcoral;
}
CSS;

		$post = wp_update_custom_css_post( $input );
		$this->assertNotWPError( $post );

		$output = wp_get_custom_css();
		$this->assertEquals( $input, $output );
	}

	/**
	 * Test that HTML code is correctly stripped.
	 */
	public function test_html_not_allowed() {
		$input = <<<CSS
/* HTML-ish code should be stripped */
<class> p {
	color: lightcoral;
}
CSS;

		$expected = <<<CSS
/* HTML-ish code should be stripped */
p {
	color: lightcoral;
}
CSS;

		$post = wp_update_custom_css_post( $input );
		$this->assertNotWPError( $post );

		$output = wp_get_custom_css();
		$this->assertSame( $expected, $output );
	}

	/**
	 * Test that new properties are not stripped.
	 */
	public function test_properties_allowed() {
		$input = <<<CSS
p {
	-webkit-line-clamp: 3;
	-webkit-text-stroke: 4px navy;
	-webkit-text-fill-color: green;
	text-decoration-line: underline overline;
	text-decoration-color: #000;
	text-decoration-style: dotted;
	text-decoration-thickness: 1px;
}
CSS;

		$expected = <<<CSS
p {
	-webkit-line-clamp: 3;
	-webkit-text-stroke: 4px navy;
	-webkit-text-fill-color: green;
	text-decoration-line: underline overline;
	text-decoration-color: #000;
	text-decoration-style: dotted;
	text-decoration-thickness: 1px;
}
CSS;

		$post = wp_update_custom_css_post( $input );
		$this->assertNotWPError( $post );

		$output = wp_get_custom_css();
		$this->assertSame( $expected, $output );
	}
}
