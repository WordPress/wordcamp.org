<?php

namespace WordCamp\Site_URL_History;

defined( 'WPINC' ) || die();

add_action( 'wp_update_site', __NAMESPACE__ . '\store_old_url_on_rename', 10, 2 );

/**
 * Store the old home URL in blogmeta when a site's domain or path changes.
 *
 * This allows sunrise.php to redirect requests for old URLs to the new location.
 *
 * @param WP_Site $new_site The site object after the update.
 * @param WP_Site $old_site The site object before the update.
 */
function store_old_url_on_rename( $new_site, $old_site ) {
	if ( $new_site->domain === $old_site->domain && $new_site->path === $old_site->path ) {
		return;
	}

	$old_home_url = 'https://' . $old_site->domain . $old_site->path;

	// Store the old URL. Multiple renames accumulate multiple entries.
	$old_urls = get_site_meta( $new_site->id, 'old_home_url' );

	if ( ! in_array( $old_home_url, $old_urls, true ) ) {
		add_site_meta( $new_site->id, 'old_home_url', $old_home_url );
	}
}
