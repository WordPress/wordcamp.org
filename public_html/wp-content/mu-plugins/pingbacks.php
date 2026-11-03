<?php

namespace WordCamp\Pingbacks;

defined( 'WPINC' ) || die();

/*
 * Turn off pingbacks and trackbacks on every site.
 *
 * Camp sites get almost nothing from them besides spam, so this closes pings everywhere instead of relying on
 * each post's Discussion settings. Closing pings stops `pingback.ping` and `wp-trackback.php` from adding
 * anything, and also drops the `X-Pingback` header. The XML-RPC methods that list a post's existing pingbacks
 * and trackbacks don't check whether pings are open, so they're removed too. The rest of XML-RPC stays, because
 * Jetpack needs it.
 *
 * Pings are closed early, so later callbacks that only ever close them can skip their own checks.
 */
add_filter( 'pings_open', __NAMESPACE__ . '\close_pings', 9 );
add_filter( 'xmlrpc_methods', __NAMESPACE__ . '\remove_pingback_methods' );

/**
 * Close pings on every post.
 *
 * @return bool
 */
function close_pings() {
	return false;
}

/**
 * Remove the XML-RPC methods for sending and reading pingbacks and trackbacks.
 *
 * @param array $methods
 *
 * @return array
 */
function remove_pingback_methods( $methods ) {
	unset(
		$methods['pingback.ping'],
		$methods['pingback.extensions.getPingbacks'],
		$methods['mt.getTrackbackPings']
	);

	return $methods;
}
