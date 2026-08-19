<?php
/**
 * Plugin Name: WordPress Groups Frontend
 * Description: Front-end management UI for WordPress Group sites on events.wordpress.org. Lets organizers create and edit GatherPress events without touching wp-admin, via a React modal that talks to a REST API.
 * Author:      WordPress.org Meta Team
 * License:     GPL-2.0-or-later
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend;

defined( 'WPINC' ) || die();

const VERSION = '0.2.0';

require_once __DIR__ . '/inc/capabilities.php';
require_once __DIR__ . '/inc/defaults.php';
require_once __DIR__ . '/inc/group-location.php';
require_once __DIR__ . '/inc/rsvp-labels.php';
require_once __DIR__ . '/inc/rsvp-questions.php';
require_once __DIR__ . '/inc/rest.php';
require_once __DIR__ . '/inc/modal.php';
require_once __DIR__ . '/inc/blocks.php';
require_once __DIR__ . '/inc/my-events.php';
require_once __DIR__ . '/inc/class-members-controller.php';
require_once __DIR__ . '/inc/class-ownership-transfer-controller.php';
require_once __DIR__ . '/inc/notifications.php';
require_once __DIR__ . '/inc/sponsors.php';

/**
 * Bootstrap the plugin.
 *
 * Only loads when GatherPress is active on the current site — without
 * GatherPress there's no event post type and no work to do.
 */
function bootstrap(): void {
	// Sponsors are network-level data with no connection to events, and the
	// network root site where they're edited doesn't run GatherPress at all,
	// so this has to be registered before the guard below.
	Sponsors\bootstrap();

	if ( ! class_exists( '\GatherPress\Core\Event\Event' ) ) {
		return;
	}

	Blocks\bootstrap();
	REST\bootstrap();
	RSVP_Questions\bootstrap();
	Modal\bootstrap();
	Notifications\bootstrap();

	add_action(
		'rest_api_init',
		function () {
			$controller = new Members\Members_Controller();
			$controller->register_routes();

			$ownership_transfer_controller = new Ownership_Transfer\Ownership_Transfer_Controller();
			$ownership_transfer_controller->register_routes();
		}
	);
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\bootstrap' );
