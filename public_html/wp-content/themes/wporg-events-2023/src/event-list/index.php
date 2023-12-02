<?php
/**
 * Block Name: WordPress Event List
 * Description: List of WordPress Events.
 *
 * @package wporg
 */

namespace WordPressdotorg\Theme\Events_2023\WordPress_Event_List;

use function WordPressdotorg\MU_Plugins\Google_Map\{get_all_upcoming_events};

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

	// Get all the events
	$events = get_event_list();

	// Get all the filters that are currently applied.
	$filtered_events = array_slice( filter_events( $events ), 0, 10);

	// loop through events output markup using gutenberg blocks
	$content = '<ul>';

	foreach ( $filtered_events as $event ) {
		$content .= '<li>';
		$content .= '<div>' . $event->title . '</div>';
		$content .= '<div>' . $event->location . '</div>';
		$content .= '<div>' . $event->timestamp . '</div>';
		$content .= '</li>';
	}

	$content .= '</ul>';

	$wrapper_attributes = get_block_wrapper_attributes();
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

/**
 * Retrieves event list based on the filters.
 *
 * @return array
 */
function get_event_list(): array {
	return get_all_upcoming_events();
}
