<?php
/**
 * Block Name: Link Wrapper
 * Description: Link a set of blocks to a given page.
 *
 * @package wporg
 */

namespace WordPressdotorg\MU_Plugins\Link_Wrapper_Block;

use function WordPressdotorg\MU_Plugins\Helpers\register_assets_from_metadata;

add_action( 'init', __NAMESPACE__ . '\init' );

/**
 * Registers the block using the metadata loaded from the `block.json` file.
 * Behind the scenes, it registers also all assets so they can be enqueued
 * through the block editor in the corresponding context.
 *
 * @see https://developer.wordpress.org/reference/functions/register_block_type/
 */
function init() {
	register_block_type( __DIR__ . '/build' );
}
