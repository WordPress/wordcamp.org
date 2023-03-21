<?php
namespace WordCamp\Blocks\Variations\Query;

defined( 'WPINC' ) || die();

/**
 * Actions and filters.
 */
add_filter( 'wordcamp_blocks_script_data', __NAMESPACE__ . '\add_script_data' );
add_filter( 'query_loop_block_query_vars', __NAMESPACE__ . '\update_query_loop_vars' );

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
 * Filter the query loop arguments.
 *
 * Convert the "session_date" orderby value to real WP_Query args.
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

	return $query;
}
