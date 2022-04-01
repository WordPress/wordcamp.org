<?php
namespace WordCamp\Blocks\Variations\Query;

defined( 'WPINC' ) || die();

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
add_filter( 'wordcamp_blocks_script_data', __NAMESPACE__ . '\add_script_data' );
