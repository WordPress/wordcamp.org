<?php
/**
 * Plugin Name: GatherPress Recurring Events
 * Description: Adds storage-efficient recurring occurrences to GatherPress events.
 * Version: 0.1.0
 * Requires PHP: 8.1
 * Requires Plugins: gatherpress
 * Text Domain: wordcamporg
 *
 * @package WordPressdotorg\GatherPress_Recurring_Events
 */

namespace WordPressdotorg\GatherPress_Recurring_Events;

defined( 'WPINC' ) || die();

const VERSION = '0.1.0';
const DIR     = __DIR__;
const FILE    = __FILE__;

require_once DIR . '/includes/class-database.php';
require_once DIR . '/includes/class-rule.php';
require_once DIR . '/includes/class-occurrences.php';
require_once DIR . '/includes/class-context.php';
require_once DIR . '/includes/class-comments.php';
require_once DIR . '/includes/class-rest-api.php';
require_once DIR . '/includes/class-admin.php';
require_once DIR . '/includes/class-query.php';
require_once DIR . '/includes/class-plugin.php';

register_deactivation_hook( FILE, array( Plugin::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		if ( class_exists( 'GatherPress\\Core\\Event\\Event' ) ) {
			Plugin::get_instance()->register();
		}
	},
	20
);
