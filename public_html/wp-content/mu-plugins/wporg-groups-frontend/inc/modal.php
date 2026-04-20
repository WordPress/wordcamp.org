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
}

/**
 * Enqueue media library and component styles needed by the event modal.
 *
 * Only loads for users who can manage events. The block's viewScript
 * handles the main JS and its wp-* dependencies automatically.
 */
function enqueue_supplementary_assets(): void {
	if ( ! current_user_can_manage_events() ) {
		return;
	}

	wp_enqueue_media();
	wp_enqueue_style( 'wp-components' );

	wp_localize_script(
		'wporg-event-manage-view-script',
		'wporgGroupsEventModal',
		array(
			'restNamespace' => 'wporg-groups/v1',
		)
	);
}
