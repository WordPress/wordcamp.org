<?php
/**
 * Test stubs for cross-plugin functions the REST code touches but this suite doesn't load.
 */

namespace WordCamp\Theme_Templates;

defined( 'WPINC' ) || die();

if ( ! function_exists( __NAMESPACE__ . '\\site_supports_block_templates' ) ) {
	/**
	 * Stand in for the mu-plugin function a `the_content` filter calls during REST responses.
	 *
	 * @return bool
	 */
	function site_supports_block_templates() {
		return false;
	}
}
