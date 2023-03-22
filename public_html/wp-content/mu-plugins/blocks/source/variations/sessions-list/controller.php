<?php
namespace WordCamp\Blocks\Variations\Query;

defined( 'WPINC' ) || die();

/**
 * Actions and filters.
 */
add_filter( 'wordcamp_blocks_script_data', __NAMESPACE__ . '\add_script_data' );
add_filter( 'pre_render_block', __NAMESPACE__ . '\pre_render_block', 10, 2 );
add_filter( 'render_block', __NAMESPACE__ . '\post_render_block', 20, 2 );

/**
 * Enable the hook by adding a value to the script data.
 *
 * @param array $data
 *
 * @return array
 */
function add_script_data( array $data ) {
	$data['hook-query'] = true;

	return $data;
}

/**
 * Attach the query modifications when starting to render this query block.
 *
 * @param string|null $pre_render The pre-rendered content. Default null.
 * @param array       $block      The block being rendered.
 * @return array Updated query parameters.
 */
function pre_render_block( $pre_render, $block ) {
	if ( isset( $block['attrs']['namespace'] ) && 'wordcamp/sessions-query' === $block['attrs']['namespace'] ) {
		add_filter( 'query_loop_block_query_vars', __NAMESPACE__ . '\update_query_loop_vars' );
	}

	return $pre_render;
}

/**
 * Remove the query modifications when starting to render this query block.
 *
 * @param string $block_content The block content.
 * @param array  $block         The block being rendered.
 * @return array Updated query parameters.
 */
function post_render_block( $block_content, $block ) {
	if ( isset( $block['attrs']['namespace'] ) && 'wordcamp/sessions-query' === $block['attrs']['namespace'] ) {
		remove_filter( 'query_loop_block_query_vars', __NAMESPACE__ . '\update_query_loop_vars', 10, 2 );
	}

	return $block_content;
}

/**
 * Filter the query loop arguments when in a Sessions List variation.
 *
 * Only display regular sessions. Convert the "session_date" orderby value to real WP_Query args.
 *
 * @see WordCamp\Post_Types\REST_API\prepare_session_query_args.
 *
 * @param array $query Array containing parameters for `WP_Query` as parsed by the block context.
 * @return array Updated query parameters.
 */
function update_query_loop_vars( $query ) {
	if ( 'session_date' === $query['orderby'] ) {
		$query['meta_key'] = '_wcpt_session_time';
		$query['orderby']  = 'meta_value_num';
	}

	// Only show real sessions (not breaks, etc) in the Sessions List.
	$meta_query = array(
		'relation' => 'OR',
		array(
			'key' => '_wcpt_session_type',
			'compare' => 'NOT EXISTS',
		),
		array(
			'key' => '_wcpt_session_type',
			'value' => 'session',
		),
	);

	if ( isset( $query['meta_query'] ) && is_array( $query['meta_query'] ) ) {
		$query['meta_query'][] = $meta_query;
	} else {
		$query['meta_query'] = array( $meta_query );
	}

	return $query;
}
