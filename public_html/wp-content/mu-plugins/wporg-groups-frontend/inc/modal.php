<?php
/**
 * Supplementary asset loading for the event modal.
 *
 * The modal itself is rendered by the `wporg/event-manage` block which
 * handles its own script/style enqueuing via block.json. This file loads
 * assets that the block system does not handle automatically:
 *
 *   - wp_enqueue_media() for the featured-image picker.
 *   - wp-components stylesheet for the Modal/TextControl components.
 *   - Localized config data for the JS app.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\Modal;

defined( 'WPINC' ) || die();

use function WordCamp\Groups\Frontend\Capabilities\current_user_can_manage_events;

/**
 * Bootstrap supplementary modal assets.
 */
function bootstrap(): void {
	add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_supplementary_assets' );
	add_filter( 'render_block_wporg/event-manage', __NAMESPACE__ . '\localize_block_script', 10, 2 );
	add_filter( 'render_block_wporg/group-settings', __NAMESPACE__ . '\localize_block_script', 10, 2 );
}

/**
 * Enqueue media library and component styles needed by the event modal.
 */
function enqueue_supplementary_assets(): void {
	if ( ! current_user_can_manage_events() ) {
		return;
	}

	wp_enqueue_media();
	wp_enqueue_style( 'wp-components' );
	wp_enqueue_style( 'wp-block-editor' );
}

/**
 * Attach localized config when the block renders.
 *
 * @param string $content Block HTML.
 * @return string Unmodified block HTML.
 */
function localize_block_script( string $content, array $block = array() ): string {
	static $done = false;
	if ( $done ) {
		return $content;
	}
	$done = true;

	// Localize for both old (event-manage) and new (group-settings) blocks.
	$config = array( 'restNamespace' => 'wporg-groups/v1' );
	wp_localize_script( 'wporg-event-manage-view-script', 'wporgGroupsEventModal', $config );
	wp_localize_script( 'wporg-group-settings-view-script', 'wporgGroupsEventModal', $config );

	return $content;
}
