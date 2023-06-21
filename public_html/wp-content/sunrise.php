<?php

namespace WordCamp\Sunrise;
defined( 'WPINC' ) || die();

load_network_sunrise();


/**
 * Load the sunrise file for the current network.
 */
function load_network_sunrise() {
	switch ( SITE_ID_CURRENT_SITE ) {
		case WORDCAMP_NETWORK_ID:
		default:
			require __DIR__ . '/sunrise-wordcamp.php';
			break;
	}
}

/**
 * Get the TLD for the current environment.
 *
 * @return string
 */
function get_top_level_domain() {
	return 'local' === WORDCAMP_ENVIRONMENT ? 'test' : 'org';
}
