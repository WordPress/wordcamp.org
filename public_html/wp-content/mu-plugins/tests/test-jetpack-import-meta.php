<?php

namespace WordCamp\Tests;

use WP_UnitTestCase;
use WP_REST_Request;
use function WordCamp\Jetpack_Tweaks\Import_Meta\normalize_import_meta;
use function WordCamp\Jetpack_Tweaks\Import_Meta\decode_without_objects;

defined( 'WPINC' ) || die();

/**
 * Class Test_Jetpack_Import_Meta
 *
 * @group mu-plugins
 * @group jetpack-tweaks
 * @group jetpack-import
 *
 * @package WordCamp\Tests
 */
class Test_Jetpack_Import_Meta extends WP_UnitTestCase {

	/**
	 * Build a request for the given route with the given `meta` param, and run it through the filter.
	 *
	 * @param string $route The REST route.
	 * @param array  $meta  The meta param.
	 *
	 * @return array The meta param after filtering.
	 */
	private function filter_meta( $route, $meta ) {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_param( 'meta', $meta );

		normalize_import_meta( null, array(), $request );

		return $request->get_param( 'meta' );
	}

	/**
	 * A serialized object in a meta value must not survive as an object.
	 */
	public function test_serialized_object_is_neutralized() {
		$payload = 'O:8:"stdClass":1:{s:3:"foo";s:3:"bar";}';

		$meta = $this->filter_meta( '/jetpack/v4/import/posts', array( 'x' => $payload ) );

		$this->assertObjectFree( $meta['x'] );
		// The importer runs maybe_unserialize() next; confirm that still yields no object.
		$this->assertObjectFree( maybe_unserialize( $meta['x'] ) );
	}

	/**
	 * An object nested inside a serialized array must also be removed.
	 */
	public function test_nested_serialized_object_is_neutralized() {
		$payload = 'a:2:{s:4:"safe";s:2:"ok";s:3:"bad";O:8:"stdClass":0:{}}';

		$meta  = $this->filter_meta( '/jetpack/v4/import/posts', array( 'x' => $payload ) );
		$value = maybe_unserialize( $meta['x'] );

		$this->assertIsArray( $value );
		$this->assertSame( 'ok', $value['safe'] );
		$this->assertObjectFree( $value );
	}

	/**
	 * A legitimately-serialized array of scalars must round-trip unchanged.
	 */
	public function test_serialized_array_round_trips() {
		$original = array(
			'question' => 'Shirt size',
			'options'  => array( 'S', 'M', 'L' ),
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Building a fixture of the exact WXR-style serialized value the importer receives.
		$serialized = serialize( $original );
		$meta       = $this->filter_meta( '/jetpack/v4/import/posts', array( 'tix_questions' => $serialized ) );
		$value      = maybe_unserialize( $meta['tix_questions'] );

		$this->assertSame( $original, $value );
	}

	/**
	 * Plain (non-serialized) values must be left untouched.
	 */
	public function test_plain_values_untouched() {
		$meta = $this->filter_meta(
			'/jetpack/v4/import/posts',
			array(
				'_edit_last' => '1',
				'note'       => 'a plain string',
			)
		);

		$this->assertSame( '1', $meta['_edit_last'] );
		$this->assertSame( 'a plain string', $meta['note'] );
	}

	/**
	 * Requests to routes outside the import namespace must not be modified.
	 */
	public function test_non_import_routes_untouched() {
		$payload = 'O:8:"stdClass":0:{}';

		$meta = $this->filter_meta( '/wp/v2/posts', array( 'x' => $payload ) );

		$this->assertSame( $payload, $meta['x'] );
	}

	/**
	 * The pages route is covered as well as posts.
	 */
	public function test_pages_route_is_covered() {
		$payload = 'O:8:"stdClass":0:{}';

		$meta = $this->filter_meta( '/jetpack/v4/import/pages', array( 'x' => $payload ) );

		$this->assertObjectFree( maybe_unserialize( $meta['x'] ) );
	}

	/**
	 * Scalars and arrays of scalars are left alone by decode_without_objects().
	 */
	public function test_decode_without_objects_passthrough() {
		$this->assertSame( 'draft', decode_without_objects( 'draft' ) );
		$this->assertSame( 5, decode_without_objects( 5 ) );
		$this->assertSame( array( 'a' => 'b' ), decode_without_objects( array( 'a' => 'b' ) ) );
	}

	/**
	 * Assert that a value contains no objects at any depth.
	 *
	 * @param mixed $value The value to check.
	 */
	private function assertObjectFree( $value ) {
		if ( is_object( $value ) ) {
			$this->fail( 'Value is an object: ' . get_class( $value ) );
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				$this->assertObjectFree( $item );
			}
		}

		$this->assertTrue( true );
	}

	/**
	 * Meta that names a file on disk is not imported.
	 *
	 * These routes write meta directly rather than through `import_post_meta_key`, so they drop
	 * the same keys the WXR importer does.
	 *
	 * @dataProvider data_file_path_meta_keys
	 *
	 * @param string $key A meta key that stores a filesystem path.
	 */
	public function test_file_path_meta_is_dropped( $key ) {
		$meta = $this->filter_meta( '/jetpack/v4/import/posts', array( $key => '../../../evil.txt' ) );

		$this->assertArrayNotHasKey( $key, $meta );
	}

	/**
	 * Data provider for test_file_path_meta_is_dropped().
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
	 * Dropping a file path key leaves the rest of the meta alone.
	 */
	public function test_other_meta_survives_the_drop() {
		$meta = $this->filter_meta(
			'/jetpack/v4/import/posts',
			array(
				'_wp_font_face_file' => '../../../evil.txt',
				'_thumbnail_id'      => '4242',
				'tix_coupon'         => 'early-bird',
			)
		);

		$this->assertArrayNotHasKey( '_wp_font_face_file', $meta );
		$this->assertSame( '4242', $meta['_thumbnail_id'] );
		$this->assertSame( 'early-bird', $meta['tix_coupon'] );
	}

	/**
	 * Routes outside the importer are left alone.
	 */
	public function test_other_routes_are_untouched() {
		$meta = $this->filter_meta( '/wp/v2/posts', array( '_wp_font_face_file' => 'font.ttf' ) );

		$this->assertSame( 'font.ttf', $meta['_wp_font_face_file'] );
	}
}
