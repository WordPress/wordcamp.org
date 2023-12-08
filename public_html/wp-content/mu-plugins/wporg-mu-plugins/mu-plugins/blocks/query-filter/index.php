<?php
/**
 * Block Name: Query Filters
 * Description: Display a set of filters to update the current query.
 *
 * @package wporg
 */

namespace WordPressdotorg\MU_Plugins\Filters_Block;

add_action( 'init', __NAMESPACE__ . '\init' );

/**
 * Registers the block using the metadata loaded from the `block.json` file.
 * Behind the scenes, it registers also all assets so they can be enqueued
 * through the block editor in the corresponding context.
 *
 * @see https://developer.wordpress.org/reference/functions/register_block_type/
 */
function init() {
	global $wp;
	$wp->add_query_var( 'category' );

	register_block_type( __DIR__ . '/build' );
}
