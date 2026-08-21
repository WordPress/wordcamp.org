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
			'width'  => 1024,
			'height' => 768,
			'sizes'  => array( 'thumbnail', 'medium' ),
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Building a fixture of the exact WXR-style serialized value the importer receives.
		$serialized = serialize( $original );
		$meta       = $this->filter_meta( '/jetpack/v4/import/posts', array( '_wp_attachment_metadata' => $serialized ) );
		$value      = maybe_unserialize( $meta['_wp_attachment_metadata'] );

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
}
