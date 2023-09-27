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

// Can't use `$wp->request` because Gutenberg calls this on `init`, before `parse_request`.
$request_uri = normalize_request_uri( $_SERVER['REQUEST_URI'], $_SERVER['QUERY_STRING'] );

$map_options = array(
	'id'      => 'city-landing-page',
	'markers' => get_city_landing_page_events( $request_uri ),
);

?>

<!-- wp:wporg/google-map <?php echo wp_json_encode( $map_options ); ?> /-->
