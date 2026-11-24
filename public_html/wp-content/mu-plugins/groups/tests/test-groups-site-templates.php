<?php

namespace WordCamp\Groups\Tests;

use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * The theme's block markup survives a Site Editor save unchanged.
 *
 * The editor saves a template by parsing and re-serializing it. Markup that
 * doesn't round-trip, like a group missing its closing comment, renders one
 * way from the theme file and another once an organizer saves it.
 *
 * @group groups
 */
class Test_Groups_Site_Templates extends WP_UnitTestCase {

	/**
	 * Every template and part serializes back to itself.
	 */
	public function test_markup_round_trips() {
		$files = glob( SUT_WP_CONTENT_DIR . 'themes/groups-site/{templates,parts}/*.html', GLOB_BRACE );

		$changed = array();

		foreach ( $files as $path ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a theme file from disk.
			$markup = file_get_contents( $path );

			if ( serialize_blocks( parse_blocks( $markup ) ) !== $markup ) {
				$changed[] = basename( dirname( $path ) ) . '/' . basename( $path );
			}
		}

		$this->assertNotEmpty( $files );
		$this->assertSame( array(), $changed, 'These change when the Site Editor saves them. Check for an unclosed or mismatched block comment.' );
	}
}
