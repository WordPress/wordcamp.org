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
require_once __DIR__ . '/inc/rest.php';
require_once __DIR__ . '/inc/modal.php';
require_once __DIR__ . '/inc/blocks.php';
require_once __DIR__ . '/inc/class-members-controller.php';

/**
 * Bootstrap the plugin.
 *
 * Only loads when GatherPress is active on the current site — without
 * GatherPress there's no event post type and no work to do.
 */
function bootstrap(): void {
	if ( ! class_exists( '\GatherPress\Core\Event' ) ) {
		return;
	}

	REST\bootstrap();
	Modal\bootstrap();
	Blocks\bootstrap();

	add_action( 'rest_api_init', function () {
		$controller = new Members\Members_Controller();
		$controller->register_routes();
	} );
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\bootstrap' );
