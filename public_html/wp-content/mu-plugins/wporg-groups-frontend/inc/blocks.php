<?php
/**
 * Block registration for the groups frontend mu-plugin.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\Blocks;

defined( 'WPINC' ) || die();

/**
 * Bootstrap block registration.
 */
function bootstrap(): void {
	add_action( 'init', __NAMESPACE__ . '\register_blocks' );
}

/**
 * Register all blocks provided by this mu-plugin.
 */
function register_blocks(): void {
	register_block_type_from_metadata( dirname( __DIR__ ) . '/build/blocks/event-rsvp' );
	register_block_type_from_metadata( dirname( __DIR__ ) . '/build/blocks/event-manage' );
	register_block_type_from_metadata( dirname( __DIR__ ) . '/build/blocks/group-membership' );
	register_block_type_from_metadata( dirname( __DIR__ ) . '/build/blocks/group-members' );
}
