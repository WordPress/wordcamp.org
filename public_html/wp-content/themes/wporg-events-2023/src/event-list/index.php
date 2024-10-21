<?php
/**
 * Block Name: WordPress Event List
 * Description: List of WordPress Events.
 *
 * @package wporg
 */

namespace WordPressdotorg\Theme\Events_2023\WordPress_Event_List;

use WordPressdotorg\Events_2023;
use WP_Block;
use WordPressdotorg\MU_Plugins\Google_Map;

add_action( 'init', __NAMESPACE__ . '\init' );


/**
 * Registers the block using the metadata loaded from the `block.json` file.
 * Behind the scenes, it registers also all assets so they can be enqueued
 * through the block editor in the corresponding context.
 *
 * @see https://developer.wordpress.org/reference/functions/register_block_type/
 */
function init() {
	register_block_type(
		dirname( __DIR__, 2 ) . '/build/event-list',
		array(
			'render_callback' => __NAMESPACE__ . '\render',
		)
	);
}

/**
 * Render the block content.
 *
 * @param array    $attributes Block attributes.
 * @param string   $content    Block default content.
 * @param WP_Block $block      Block instance.
 *
 * @return string Returns the block markup.
 */
function render( $attributes, $content, $block ) {
	$facets = Events_2023\get_query_var_facets();
	$events = Google_Map\get_events( $attributes['events'], 0, 0, $facets );

	// Get all the filters that are currently applied.
	$filtered_events = array_slice( filter_events( $events ), 0, (int) $attributes['limit'] );

	// The results are not guaranteed to be in order, so sort them.
	usort( $filtered_events,
		function ( $a, $b ) {
			return $a->timestamp - $b->timestamp;
		}
	);

	if ( count( $filtered_events ) < 1 ) {
		return get_no_result_view();
	}

	// Prune to only the used properties, to reduce the size of the payload.
	$filtered_events = array_map(
		function ( $event ) {
			return array(
				'title'     => $event->title,
				'type'      => $event->type,
				'url'       => $event->url,
				'location'  => $event->location,
				'timestamp' => $event->timestamp,
			);
		},
		$filtered_events
	);

	$payload = array(
		'events'       => $filtered_events,
		'groupByMonth' => $attributes['groupByMonth'],
	);

	wp_add_inline_script(
		// `generate_block_asset_handle()` includes the index if `viewScript` is an array, so this is fragile.
		// There isn't a way to get it programmatically, though, so it just has to manually be kept in sync.
		'wporg-event-list-view-script-2',
		'globalEventsPayload = ' . wp_json_encode( $payload ) . ';',
		'before'
	);

	ob_start();

	?>

	<p class="wporg-marker-list__loading">
		Loading global events...
		<img
			src="<?php echo esc_url( includes_url( 'images/spinner-2x.gif' ) ); ?>"
			width="20"
			height="20"
			alt=""
		/>
	</p>

	<?php

	$content = ob_get_clean();

	$wrapper_attributes = get_block_wrapper_attributes();

	return sprintf(
		'<div %1$s>%2$s</div>',
		$wrapper_attributes,
		do_blocks( $content )
	);
}

/**
 * Get a list of the currently-applied filters.
 */
function filter_events( array $events ): array {
	global $wp_query;

	$taxes = array(
		'map_type' => 'map_type',
	);
	$terms = array();

	// Get the terms.
	foreach ( $taxes as $query_var => $taxonomy ) {

		if ( ! isset( $wp_query->query[ $query_var ] ) ) {
			continue;
		}

		$values = (array) $wp_query->query[ $query_var ];
		foreach ( $values as $value ) {
			$terms[] = $value;
		}
	}

	if ( empty( $terms ) ) {
		return $events;
	}

	$filtered_events = array();
	foreach ( $events as $event ) {
		// Assuming each event has a 'type' property.
		if ( isset( $event->type ) && in_array( $event->type, $terms ) ) {
			$filtered_events[] = $event;
		}
	}

	return $filtered_events;
}

/**
 * Returns a block driven view when no results are found.
 *
 * Ideally this would be a template part, but until we use WP_QUERY to get the events, we can't use template parts.
 *
 * @return string
 */
function get_no_result_view() {
	$content = '<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->';
	$content .= '<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50)">';
	$content .= sprintf( '<!-- wp:heading {"textAlign":"center","level":1,"fontSize":"heading-2"} --><h1 class="wp-block-heading has-text-align-center has-heading-2-font-size">%s</h1><!-- /wp:heading -->',
		esc_attr( 'No results found', 'wporg' )
	);
	$content .= sprintf(
		'<!-- wp:paragraph {"align":"center"} --><p class="has-text-align-center">%s</p><!-- /wp:paragraph -->',
		sprintf(
			wp_kses_post(
				/* translators: %s is the URL of the event archives. */
				__( 'View <a href="%s">upcoming events</a> or try a different search.', 'wporg' )
			),
			esc_url( home_url( '/upcoming-events/' ) )
		)
	);
	$content .= '</div><!-- /wp:group -->';

	return do_blocks( $content );
}
