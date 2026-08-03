<?php
/**
 * Integrates GatherPress Recurring Events with the Groups RSVP block.
 *
 * @package WordPressdotorg\GatherPress_Recurring_Events
 */

namespace WordCamp\Groups;

use WordPressdotorg\GatherPress_Recurring_Events\Context;

defined( 'WPINC' ) || die();

add_action(
	'plugins_loaded',
	static function (): void {
		if ( class_exists( Context::class ) ) {
			add_filter( 'render_block_wporg/event-rsvp', array( Context::class, 'render_rsvp_block' ), 50 );
		}
	},
	30
);
