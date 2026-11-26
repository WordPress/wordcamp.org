<?php
/**
 * Stand-in for `WordPressdotorg\Profiles\api()`, which lives in the private
 * WordPress.org mu-plugins. Like the real one, it POSTs to Profiles and
 * returns the response, so tests answer it through `pre_http_request`.
 *
 * @package WordCamp\Groups
 */

namespace WordPressdotorg\Profiles;

if ( ! function_exists( __NAMESPACE__ . '\api' ) ) {
	/**
	 * POST to the Profiles activity handler.
	 *
	 * @param array $args Request body.
	 * @return array|\WP_Error
	 */
	function api( array $args ) {
		return wp_remote_post(
			'https://profiles.wordpress.org/wp-admin/admin-ajax.php',
			array( 'body' => $args )
		);
	}
}
