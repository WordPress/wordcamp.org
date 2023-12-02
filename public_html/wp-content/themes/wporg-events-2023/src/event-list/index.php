<?php
/**
 * Block Name: WordPress Event List
 * Description: List of WordPress Events.
 *
 * @package wporg
 */

namespace WordPressdotorg\Theme\Events_2023\WordPress_Event_List;

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

	// Get all the filters that are currently applied.
	$filters = get_applied_filter_list();

	// Get all the events with relevant filters
	$events = get_event_list( $filters );

	// loop through events output markup using gutenberg blocks
	$content = '';

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
function get_applied_filter_list( $include_search = true ) {
	global $wp_query;

	$taxes = [
		'map_type' => 'map_type',
	];
	$terms = array();

	foreach ( $taxes as $query_var => $taxonomy ) {

		if ( ! isset( $wp_query->query[ $query_var ] ) ) {
			continue;
		}

		$values = (array) $wp_query->query[ $query_var ];
		foreach ( $values as $value ) {
			$terms[] = $value;
		}
	}

	var_dump( $terms );
	
	// retrieve all the relevant filters from the query;

	return array();
}

/**
 * Retrieves event list based on the filters.
 * 
 * @return array
 */
function get_event_list( $filters ): array {
	global $wpdb;

	// Prepared statement for the query.

	return array();
}
