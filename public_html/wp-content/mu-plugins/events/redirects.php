<?php

namespace Events\Redirects;

defined( 'WPINC' ) || die();

add_action( 'init', __NAMESPACE__ . '\redirect_to_make_community' );

/**
 * Redirects the root URL of the "events.wordpress.org" to "https://make.wordpress.org/community/events".
 */
function redirect_to_make_community() {
	if ( wp_using_themes() && ! defined( 'REST_REQUEST' ) ) {
		$request_uri = $_SERVER['REQUEST_URI'];

		if ( '/' === $request_uri ) {
			$new_url = 'https://make.wordpress.org/community/events';
			wp_redirect( $new_url );
			exit;
		}
	}
}
