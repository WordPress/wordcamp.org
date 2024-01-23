<?php

/*
 * Customizations related to HTTPS.
 */

/**
 * WP Super Cache puts http and https requests in the same bucket which
 * generates mixed content warnings and generate breakage all around. The
 * following makes sure only HTTPS requests are cached.
 *
 * This is currently superseded by wp-super-cache-plugins/http-https-redirect.php,
 * but this is intentionally kept in place just to be safe.
 */
add_action( 'init', function() {
        if ( ! is_ssl() )
                define( 'DONOTCACHEPAGE', true );
});

/**
 * Force HTTPS on all WordCamp.org sites that support it.
 *
 * This is currently superseded by wp-super-cache-plugins/http-https-redirect.php,
 * but this is intentionally kept in place just to be safe.
 */
function wcorg_force_ssl() {
	if ( 'cli' === php_sapi_name() || is_ssl() || headers_sent() ) {
		return;
	}

	// Our SSL certificate covers only *.wordcamp.org but year.city.wordcamp.org rediercts should still work.
	// if ( ! preg_match( '#^(?:[^.]+\.)?wordcamp\.org$#i', $_SERVER['HTTP_HOST'] ) )
	//	return;

	header( 'Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], true, 301 );
	exit;
}

add_action( 'muplugins_loaded', 'wcorg_force_ssl' );
