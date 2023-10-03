<?php

namespace WordPressdotorg\Events_2023;
defined( 'WPINC' ) || die();

require_once __DIR__ . '/event-getters.php';

add_action( 'init', __NAMESPACE__ . '\register_blocks' );


/**
 * Register blocks.
 */
function register_blocks(): void {
	register_block_type(
		__DIR__ . '/build/block.json',
		array(
			'render_callback' => __NAMESPACE__ . '\render_events_landing_page',
		)
	);
}

/**
 * Render the output of the Events Landing Page block.
 */
function render_events_landing_page( array $block_attributes, string $content ): string {
	switch ( $block_attributes['events'] ) {
		case 'all-upcoming':
			$map_options = array(
				'id'      => 'all-upcoming-events',
				'markers' => get_all_upcoming_events(),
			);
			break;

		case 'city':
			// Can't use `$wp->request` because Gutenberg calls this on `init`, before `parse_request`.
			// @todo That was true when this was part of a pattern, but it isn't now that this is a block. We can
			// maybe pass `$wp->request` to `normalize_request_uri()` now, or maybe remove that function entirely.
			$request_uri = normalize_request_uri( $_SERVER['REQUEST_URI'], $_SERVER['QUERY_STRING'] );

			$map_options = array(
				'id'      => 'city-landing-page',
				'markers' => get_city_landing_page_events( $request_uri ),
			);
			break;

		default:
			return 'No events available';
	}

	return do_blocks( '<!-- wp:wporg/google-map '. wp_json_encode( $map_options ) .' /-->' );
}
