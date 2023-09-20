<?php

/**
 * Title: City Landing Page Map
 * Slug: wporg-events-2023/city-landing-page-map
 * Inserter: no
 */

namespace WordPressdotorg\Events_2023;

defined( 'WPINC' ) || die();

if ( ! function_exists( __NAMESPACE__ . '\get_city_landing_page_events' ) ) {
	return;
}

// Can't use $wp->request because Gutenberg calls this on `init`, before `parse_request`.
$request_uri = str_replace( '?' . $_SERVER['QUERY_STRING'], '', $_SERVER['REQUEST_URI'] );

$map_options = array(
	'id'      => 'city-landing-page',
	'markers' => get_city_landing_page_events( $request_uri ),
);

?>

<!-- wp:wporg/google-map <?php echo wp_json_encode( $map_options ); ?> /-->
