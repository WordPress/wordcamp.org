<?php

/**
 * Title: City Landing Page Map
 * Slug: wporg-events-2023/city-landing-page-map
 * Inserter: no
 */

namespace WordPressdotorg\Events_2023;

defined( 'WPINC' ) || die();

if ( ! function_exists( 'get_city_landing_page_events' ) ) {
	return;
}

$map_options = array(
	'id' => 'city-landing-page',

	// Can't use $wp->request because Gutenberg calls this on `init`, before `parse_request`.
	'markers' => get_city_landing_page_events( $_SERVER['REQUEST_URI'] ),
);

?>

<!-- wp:wporg/google-map <?php echo wp_json_encode( $map_options ); ?> /-->
