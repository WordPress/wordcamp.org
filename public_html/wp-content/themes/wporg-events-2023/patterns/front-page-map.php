<?php

/**
 * Title: Front Page Map
 * Slug: wporg-events-2023/front-page-map
 * Inserter: no
 */

namespace WordPressdotorg\Events_2023;

defined( 'WPINC' ) || die();

if ( ! function_exists( 'get_all_upcoming_events' ) ) {
	return;
}

$map_options = array(
	'id'      => 'all-upcoming-events',
	'markers' => get_all_upcoming_events(),
);

?>

<!-- wp:wporg/google-map <?php echo wp_json_encode( $map_options ); ?> /-->
