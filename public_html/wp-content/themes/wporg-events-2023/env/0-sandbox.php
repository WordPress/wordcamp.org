<?php
/**
 * These are stubs for closed source code, or things that only apply to local environments.
 */

defined( 'WPINC' ) || die();

require_once WPMU_PLUGIN_DIR . '/wporg-mu-plugins/mu-plugins/loader.php';

// Google Maps API Key as used in the wporg/google-map block.
add_filter(
	'wporg_google_map_apikey',
	function () {
		return WORDCAMP_DEV_GOOGLE_MAPS_API_KEY;
	}
);

/**
 * Get the list of countries.
 */
function wcorg_get_countries() {
	return array();
}
