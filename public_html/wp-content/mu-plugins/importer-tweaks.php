<?php
/**
 * Tweaks for the WordPress Importer.
 *
 * @package WordCamp\Importer_Tweaks
 */

namespace WordCamp\Importer_Tweaks;

defined( 'WPINC' ) || die();

add_filter( 'import_post_meta_key', __NAMESPACE__ . '\skip_file_path_meta' );

/**
 * Skip imported post meta that stores a filesystem path.
 *
 * A WXR file carries meta verbatim from the site that produced it, but the importer does not bring
 * the referenced files across, so a path value lands pointing at something that has nothing to do
 * with the imported post. The importer downloads attachments and rebuilds the two attachment keys
 * from the real file. Nothing rebuilds `_wp_font_face_file`, so an imported font face loses its file
 * binding rather than keeping a path that means nothing here.
 *
 * The importer's own `is_valid_meta_key()` skips the two attachment keys already. It has not picked
 * up `_wp_font_face_file`, which the font library added in 6.5, and the plugin is installed from the
 * latest published release rather than pinned here, so all three are listed.
 *
 * @param mixed $key The meta key, or whatever a lower-priority filter returned in its place.
 *
 * @return mixed The meta key, or false to skip it. Anything that isn't a string is left alone.
 */
function skip_file_path_meta( $key ) {
	if ( in_array( $key, file_path_meta_keys(), true ) ) {
		return false;
	}

	return $key;
}

/**
 * The post meta keys that store a filesystem path.
 *
 * Shared with the Jetpack importer, which writes meta on its own REST routes rather than through
 * `import_post_meta_key`, so both importers drop the same keys.
 *
 * @return string[]
 */
function file_path_meta_keys() {
	return array(
		'_wp_attached_file',
		'_wp_attachment_metadata',
		'_wp_font_face_file',
	);
}
