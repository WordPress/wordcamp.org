<?php
/**
 * Block Name: WordPress Event List
 * Description: List of WordPress Events.
 *
 * @package wporg
 */

namespace WordPressdotorg\Theme\Events_2023\WordPress_Event_List;
use WP_Block;
use function WordPressdotorg\MU_Plugins\Google_Map\{ get_events, schedule_filter_cron };

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
		dirname( dirname( __DIR__ ) ) . '/build/event-list',
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
	$facets = array(
		'search'  => sanitize_text_field( get_query_var( 's' ) ?? '' ),
		'type'    => sanitize_text_field( get_query_var( 'event_type' ) ?? '' ),
		'format'  => sanitize_text_field( get_query_var( 'format_type' ) ?? '' ),
		'month'   => absint( get_query_var( 'month' ) ?? 0 ),
		'country' => sanitize_text_field( get_query_var( 'country' ) ?? '' ),
	);

	$events = get_events( $attributes['events'], 0, 0, $facets );
	schedule_filter_cron( $attributes['events'], 0, 0, $facets );

	// Get all the filters that are currently applied.
	$filtered_events = array_slice( filter_events( $events ), 0, 10);

	// loop through events output markup using gutenberg blocks
	$content = '<ul class="wporg-marker-list__container">';

	foreach ( $filtered_events as $event ) {
		$content .= '<li class="wporg-marker-list-item">';
		$content .= '<h3 class="wporg-marker-list-item__title"><a class="external-link" href="' . esc_url( $event->url ) . ' ">' . esc_html( $event->title ) . '</a></h3>';
		$content .= '<div class="wporg-marker-list-item__location">' . esc_html( $event->location ) . '</div>';
		$content .= '<div class="wporg-marker-list-item__date-time">' . esc_html( $event->timestamp ) . '</div>';
		$content .= '</li>';
	}

	$content .= '</ul>';

	$wrapper_attributes = get_block_wrapper_attributes( array(
		'class' => 'wp-block-wporg-google-map',
	) );
	return sprintf(
		'<div %1$s>%2$s</div>',
		$wrapper_attributes,
		do_blocks( $content )
	);
}

/**
 * Get a list of the currently-applied filters.
 *
 * @param boolean $include_search Whether the result should include the search term.
 *
 * @return array
 */
function filter_events( $events ) {
	global $wp_query;

	$taxes = array(
		'map_type' => 'map_type',
	);
	$terms = array();

	// Get the terms
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
		// Assuming each event has a 'type' property
		if ( isset($event->type) && in_array($event->type, $terms) ) {
			$filtered_events[] = $event;
		}
	}

	return $filtered_events;
}
