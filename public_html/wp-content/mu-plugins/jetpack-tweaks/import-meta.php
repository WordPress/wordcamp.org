<?php
/**
 * Normalize the post meta the Jetpack Unified Importer receives.
 *
 * The importer recreates post meta from an export and runs `maybe_unserialize()` on each value so
 * that values a WXR export stored serialized are restored. Imported meta is always plain data
 * (scalars and arrays), so we decode any serialized value ourselves -- without instantiating
 * classes -- and hand the plain result back to the importer. Normal imports keep working, and the
 * importer only ever receives plain data from the request.
 *
 * The same routes also drop the meta keys that store a filesystem path, which the WXR importer
 * drops through `import_post_meta_key`. These routes write meta directly, so they need their own
 * pass over the same list.
 *
 * @package WordCamp\Jetpack_Tweaks
 */

namespace WordCamp\Jetpack_Tweaks\Import_Meta;

use WP_REST_Request;
use function WordCamp\Importer_Tweaks\file_path_meta_keys;

defined( 'WPINC' ) || die();

add_filter( 'rest_request_before_callbacks', __NAMESPACE__ . '\normalize_import_meta', 10, 3 );

/**
 * Drop file path meta and replace serialized values on the Jetpack import routes.
 *
 * @param mixed $response The response so far. Returned unchanged.
 * @param array $handler  The matched route handler.
 * @param mixed $request  The current request object.
 *
 * @return mixed The unchanged $response.
 */
function normalize_import_meta( $response, $handler, $request ) {
	if ( ! $request instanceof WP_REST_Request ) {
		return $response;
	}

	$route = $request->get_route();

	if ( ! is_string( $route ) || 0 !== strpos( $route, '/jetpack/v4/import/' ) ) {
		return $response;
	}

	$metas = $request->get_param( 'meta' );

	if ( ! is_array( $metas ) ) {
		return $response;
	}

	$changed = false;

	$file_path_meta = file_path_meta_keys();

	foreach ( $metas as $key => $value ) {
		if ( in_array( $key, $file_path_meta, true ) ) {
			unset( $metas[ $key ] );
			$changed = true;
			continue;
		}

		$clean = decode_without_objects( $value );

		if ( $clean !== $value ) {
			$metas[ $key ] = $clean;
			$changed       = true;
		}
	}

	if ( $changed ) {
		$request->set_param( 'meta', $metas );
	}

	return $response;
}

/**
 * Decode a possibly-serialized value without instantiating any class.
 *
 * Values that aren't serialized strings are returned untouched. Serialized values are decoded with
 * class instantiation disabled and any leftover objects removed, so callers receive plain data only.
 *
 * @param mixed $value Raw value from the request.
 *
 * @return mixed A plain (object-free) value.
 */
function decode_without_objects( $value ) {
	if ( ! is_string( $value ) || ! is_serialized( $value ) ) {
		return $value;
	}

	// Decoding with `allowed_classes => false` is exactly what keeps this safe: no class is ever instantiated.
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
	$decoded = @unserialize( trim( $value ), array( 'allowed_classes' => false ) );

	// `b:0;` legitimately decodes to false; anything else that fails to decode is dropped.
	if ( false === $decoded && 'b:0;' !== trim( $value ) ) {
		return '';
	}

	return strip_objects( $decoded );
}

/**
 * Recursively remove any objects from a decoded value.
 *
 * @param mixed $value Decoded value.
 *
 * @return mixed The value with any objects replaced by null.
 */
function strip_objects( $value ) {
	if ( is_object( $value ) ) {
		return null;
	}

	if ( is_array( $value ) ) {
		foreach ( $value as $key => $item ) {
			$value[ $key ] = strip_objects( $item );
		}
	}

	return $value;
}
