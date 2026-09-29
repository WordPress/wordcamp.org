<?php

namespace WordCamp\Tests;

use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * Class Test_Importer_Tweaks
 *
 * @group mu-plugins
 * @group importer-tweaks
 *
 * @package WordCamp\Tests
 */
class Test_Importer_Tweaks extends WP_UnitTestCase {

	/**
	 * Run a meta key through the registered `import_post_meta_key` filters.
	 *
	 * @param string|false $key The meta key the importer read from the WXR file.
	 *
	 * @return string|false The key the importer would store, or false if it is skipped.
	 */
	private function filter_key( $key ) {
		return apply_filters( 'import_post_meta_key', $key, 1, array() );
	}

	/**
	 * Meta keys that name a file on disk are not imported.
	 *
	 * @dataProvider data_file_path_meta_keys
	 *
	 * @param string $key A meta key that stores a filesystem path.
	 */
	public function test_file_path_meta_is_skipped( $key ) {
		$this->assertFalse( $this->filter_key( $key ) );
	}

	/**
	 * Data provider for test_file_path_meta_is_skipped().
	 *
	 * @return array[]
	 */
	public function data_file_path_meta_keys() {
		return array(
			'attached file'       => array( '_wp_attached_file' ),
			'attachment metadata' => array( '_wp_attachment_metadata' ),
			'font face file'      => array( '_wp_font_face_file' ),
		);
	}

	/**
	 * Meta the importer needs is still imported.
	 *
	 * Featured images, page templates and nav menu items all use protected (`_`-prefixed) keys, so
	 * they would be lost if the filter went by that prefix instead of naming the keys it skips.
	 *
	 * @dataProvider data_importable_meta_keys
	 *
	 * @param string $key A meta key a normal import depends on.
	 */
	public function test_importable_meta_is_kept( $key ) {
		$this->assertSame( $key, $this->filter_key( $key ) );
	}

	/**
	 * Data provider for test_importable_meta_is_kept().
	 *
	 * @return array[]
	 */
	public function data_importable_meta_keys() {
		return array(
			'featured image'   => array( '_thumbnail_id' ),
			'page template'    => array( '_wp_page_template' ),
			'menu item type'   => array( '_menu_item_type' ),
			'menu item parent' => array( '_menu_item_menu_item_parent' ),
			'menu item object' => array( '_menu_item_object_id' ),
			'image alt text'   => array( '_wp_attachment_image_alt' ),
			'unprotected key'  => array( 'tix_coupon' ),
		);
	}

	/**
	 * Anything that isn't a string is passed through untouched.
	 *
	 * `false` is the case that matters, since it means an earlier filter already skipped the key.
	 * The others are here to pin the guard, which exists so that a filter returning something
	 * unexpected is handed on rather than swallowed.
	 *
	 * @dataProvider data_non_string_keys
	 *
	 * @param mixed $key A value another filter could have returned in place of the key.
	 */
	public function test_non_string_key_passes_through( $key ) {
		$this->assertSame( $key, $this->filter_key( $key ) );
	}

	/**
	 * Data provider for test_non_string_key_passes_through().
	 *
	 * @return array[]
	 */
	public function data_non_string_keys() {
		return array(
			'already skipped' => array( false ),
			'null'            => array( null ),
			'array'           => array( array( 'unexpected' ) ),
		);
	}
}
