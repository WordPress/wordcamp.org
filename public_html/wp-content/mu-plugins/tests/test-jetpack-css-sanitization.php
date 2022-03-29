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
	 * Test that the angle bracket is not encoded.
	 */
	public function test_gt_not_encoded() {
		$input = <<<CSS
/* Child combinator syntax */
.class > p {
	color: lightcoral;
}
CSS;

		$post = wp_update_custom_css_post( $input );
		$this->assertNotWPError( $post );

		$output = wp_get_custom_css();
		$this->assertEquals( $input, $output );
	}

	/**
	 * Test that HTML code is correcly stripped.
	 */
	public function test_html_not_allowed() {
		$input = <<<CSS
/* HTML-ish code should be stripped */
<class> p {
	color: lightcoral;
}
CSS;

		$post = wp_update_custom_css_post( $input );
		$this->assertNotWPError( $post );

		$output = wp_get_custom_css();
		$this->assertNotEquals( $input, $output );
	}
}
