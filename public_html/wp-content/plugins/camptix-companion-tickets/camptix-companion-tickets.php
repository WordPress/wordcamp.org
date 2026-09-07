<?php
/**
 * Plugin Name: CampTix - Activity Tickets
 * Description: Lets confirmed event-ticket holders self-register for free, capacity-limited activity tickets (e.g. Contributor Day, Social Dinner), gated to ticket-holders and linked to their main ticket.
 * Version:     1.0.0
 * Author:      WordCamp.org
 * License:     GPLv2 or later
 */

defined( 'WPINC' ) || die();

/**
 * Register this addon with CampTix.
 *
 * Runs on `camptix_load_addons`, which fires before `camptix_init`, so the
 * `register_addon()` call inside the addon file is in time.
 */
function camptix_companion_tickets_register() {
	require_once plugin_dir_path( __FILE__ ) . 'addons/companion-tickets.php';
}
add_action( 'camptix_load_addons', 'camptix_companion_tickets_register' );
