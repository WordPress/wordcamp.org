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

use GatherPress\Core\Event\Event;
use WP_Admin_Bar;

use function WordCamp\Groups\Frontend\Capabilities\current_user_can_manage_events;

/**
 * The URL fragment that opens the create-event modal on the group's home
 * page. `event-modal-app.js` looks for the same value.
 */
const CREATE_EVENT_FRAGMENT = 'create-event';

/**
 * Bootstrap supplementary modal assets.
 */
function bootstrap(): void {
	add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_supplementary_assets' );
	add_filter( 'render_block_wporg/event-manage', __NAMESPACE__ . '\localize_block_script', 10, 2 );
	add_filter( 'render_block_wporg/group-settings', __NAMESPACE__ . '\localize_block_script', 10, 2 );

	// After core's `wp_admin_bar_new_content_menu()` (priority 70) adds the node.
	add_action( 'admin_bar_menu', __NAMESPACE__ . '\link_new_event_to_modal', 80 );
}

/**
 * Point the toolbar's + New → Event at the create-event modal.
 *
 * The modal is where organizers create events, with the venue and the
 * online-only option as plain, labelled fields. In the block editor the venue
 * picker sits in the venue block's settings, where it's easy to miss. + New →
 * Event is at the top of every page, so it's the way in organizers reach for
 * first, and it takes them to the modal on the group's home page (#2027).
 *
 * Only for users the modal serves. Anyone else who can create events, such as
 * a contributor, keeps the link to the editor, and Events → Add New in
 * wp-admin still opens it.
 *
 * @param WP_Admin_Bar $wp_admin_bar The toolbar.
 */
function link_new_event_to_modal( WP_Admin_Bar $wp_admin_bar ): void {
	$node_id = 'new-' . Event::POST_TYPE;

	if ( ! $wp_admin_bar->get_node( $node_id ) || ! current_user_can_manage_events() ) {
		return;
	}

	// An existing node is updated in place, keeping its title and position.
	$wp_admin_bar->add_node(
		array(
			'id'   => $node_id,
			'href' => home_url( '/#' . CREATE_EVENT_FRAGMENT ),
		)
	);
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
	wp_enqueue_style( 'wp-format-library' );
}

/**
 * Attach localized config when the block renders.
 *
 * @param string $content Block HTML.
 * @param array  $block   Parsed block data.
 * @return string Unmodified block HTML.
 */
function localize_block_script( string $content, array $block ): string {
	static $localized_handles = array();

	$handles = array(
		'wporg/event-manage'   => 'wporg-event-manage-view-script',
		'wporg/group-settings' => 'wporg-group-settings-view-script',
	);

	$block_name = (string) ( $block['blockName'] ?? '' );
	$handle     = $handles[ $block_name ] ?? '';

	if ( ! $handle || ! empty( $localized_handles[ $handle ] ) ) {
		return $content;
	}

	$config = array(
		'restNamespace'   => 'wporg-groups/v1',
		'siteEditorUrl'   => admin_url( 'site-editor.php' ),
		'minimumEventDate' => wp_date( 'Y-m-d' ),
	);

	if ( wp_localize_script( $handle, 'wporgGroupsEventModal', $config ) ) {
		$localized_handles[ $handle ] = true;
	}

	return $content;
}
