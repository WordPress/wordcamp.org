<?php
/**
 * Block registration for the groups frontend mu-plugin.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\Blocks;

use GatherPress\Core\Blocks\Setup as Blocks_Setup;
use GatherPress\Core\Rsvp\Rsvp;

defined( 'WPINC' ) || die();

/**
 * Bootstrap block registration.
 */
function bootstrap(): void {
	add_action( 'init', __NAMESPACE__ . '\register_blocks' );
	add_filter( 'render_block_gatherpress/rsvp-count', __NAMESPACE__ . '\hide_empty_rsvp_count', 10, 2 );
}

/**
 * Register all blocks provided by this mu-plugin.
 */
function register_blocks(): void {
	register_block_type_from_metadata( dirname( __DIR__ ) . '/build/blocks/event-rsvp' );
	register_block_type_from_metadata( dirname( __DIR__ ) . '/build/blocks/event-manage' );
	register_block_type_from_metadata( dirname( __DIR__ ) . '/build/blocks/group-settings' );
	register_block_type_from_metadata( dirname( __DIR__ ) . '/build/blocks/group-membership' );
	register_block_type_from_metadata( dirname( __DIR__ ) . '/build/blocks/group-members' );
	register_block_type_from_metadata( dirname( __DIR__ ) . '/build/blocks/event-speakers' );
	register_block_type_from_metadata( dirname( __DIR__ ) . '/build/blocks/my-events' );
	register_block_type_from_metadata( dirname( __DIR__ ) . '/build/blocks/page-content' );
	register_block_type_from_metadata( dirname( __DIR__ ) . '/build/blocks/sponsors' );
}

/**
 * Hide the core `gatherpress/rsvp-count` block when its resolved count is 0.
 *
 * GatherPress's own block always renders (e.g. "0 Attendees"); our templates
 * rely on the block disappearing entirely for events with no RSVPs yet, so
 * we suppress the empty case here rather than reintroducing a duplicate
 * `event-rsvp-count` block.
 */
function hide_empty_rsvp_count( ?string $block_content, ?array $block ): ?string {
	if ( is_null( $block_content ) || is_null( $block )
		|| ! class_exists( Rsvp::class ) || ! class_exists( Blocks_Setup::class )
	) {
		return $block_content;
	}

	$post_id = Blocks_Setup::get_instance()->get_post_id( $block );
	if ( ! $post_id ) {
		return $block_content;
	}

	$status    = $block['attrs']['status'] ?? 'attending';
	$responses = ( new Rsvp( $post_id ) )->responses();
	$count     = (int) ( $responses[ $status ]['count'] ?? 0 );

	return $count > 0 ? $block_content : '';
}
