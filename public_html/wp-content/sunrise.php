<?php

namespace WordCamp\Sunrise;
defined( 'WPINC' ) or die();

/**
 * Redirects from city.wordcamp.org/year to year.city.wordcamp.org
 */
function unsubdomactories_redirects() {
	$redirect_cities = array(
		'russia', 'sf', 'london', 'austin', 'tokyo', 'portland', 'europe', 'philly', 'sofia', 'miami',
		'montreal', 'newyork', 'phoenix', 'slc', 'chicago', 'boston', 'norway', 'orlando', 'dallas', 'melbourne',
		'oc', 'la', 'vegas', 'capetown', 'victoria', 'birmingham', 'birminghamuk', 'ottawa', 'maine',
		'albuquerque', 'sacramento', 'toronto', 'calgary', 'porto', 'barcelona', 'tampa', 'sevilla', 'finland',
		'seoul', 'paris', 'osaka', 'kansascity', 'curitiba', 'buffalo', 'baroda', 'sandiego', 'nepal', 'raleigh',
		'baltimore', 'sydney', 'providence', 'nyc', 'dfw', 'dayton', 'copenhagen', 'denmark', 'lisboa', 'kansai',
		'biarritz', 'charleston', 'buenosaires', 'krakow', 'vienna', 'grandrapids', 'hamilton', 'minneapolis',
		'atlanta', 'stlouis', 'edinburgh', 'winnipeg', 'northcanton', 'portoalegre', 'sanantonio', 'prague',
		'denver', 'slovakia', 'salvador', 'maui', 'hamptonroads', 'houston', 'warsaw', 'belgrade', 'mumbai',
		'belohorizonte', 'lancasterpa', 'switzerland', 'romania', 'columbus', 'saratoga', 'fayetteville',
		'bournemouth', 'hanoi', 'saopaulo', 'cologne', 'louisville', 'mallorca', 'annarbor', 'manchester',
		'laspenitas', 'israel', 'ventura', 'vancouver', 'peru', 'auckland', 'norrkoping', 'netherlands',
		'hamburg', 'nashville', 'connecticut', 'sheffield', 'wellington', 'omaha', 'milwaukee', 'lima',
		'brighton', 'asheville', 'riodejaneiro', 'wroclaw', 'santarosa', 'edmonton', 'lancaster', 'kenya',
		'malaga', 'lithuania', 'detroit', 'kobe', 'reno', 'indonesia', 'transylvania', 'mexico', 'nicaragua',
		'gdansk', 'bologna', 'milano', 'catania', 'modena', 'stockholm', 'pune', 'jerusalem', 'philippines',
		'newzealand', 'cuttack', 'ponce', 'jabalpur', 'singapore', 'poznan', 'richmond', 'goldcoast', 'caguas',
		'savannah', 'ecuador', 'boulder', 'rdu', 'nc', 'lyon', 'scranton', 'brisbane', 'easttroy', 'rhodeisland',
		'croatia', 'cantabria', 'greenville', 'jacksonville', 'nuremberg', 'berlin', 'memphis', 'jakarta',
		'pittsburgh', 'nola', 'neo', 'antwerp', 'helsinki', 'vernon', 'frankfurt', 'torino', 'bilbao', 'peoria',
		'sunshinecoast', 'gdynia', 'lehighvalley', 'lahore', 'bratislava', 'rochester', 'cincinnati', 'okc',
	);

	$domain = $_SERVER['HTTP_HOST'];
	$tld    = 'local' === WORDCAMP_ENVIRONMENT ? 'test' : 'org';

	// Return if already on a 4th-level domain (e.g., 2020.narnia.wordcamp.org)
	if ( ! preg_match( "#^([a-z0-9-]+)\.wordcamp\.$tld$#i", $domain, $matches ) ) {
		return;
	}

	$city = strtolower( $matches[1] );
	if ( ! in_array( $city, $redirect_cities, true ) ) {
		return;
	}

	// If can't pick a year out of the path, return.
	// Extra alpha characters are included, for sites like `seattle.wordcamp.org/2015-beginners`.
	$path = $_SERVER['REQUEST_URI'];
	if ( ! preg_match( '#^/(\d{4}[a-z0-9-]*)#i', $path, $matches ) ) {
		return;
	}

	$year        = strtolower( $matches[1] );
	$pattern     = '#' . preg_quote( $year, '#' ) . '#';
	$path        = preg_replace( $pattern, '', $path, 1 );
	$path        = str_replace( '//', '/', $path );
	$redirect_to = sprintf( "https://%s.%s.wordcamp.$tld%s", $year, $city, $path );

	header( 'Location: ' . $redirect_to, true, 301 );
	die();
}

/**
 * WordCamp.org Canonical Redirects
 *
 * If site does not exist in the network, will look for the latest xxxx.city.wordpress.org and redirect.
 * Allows URLs such as sf.wordcamp.org always link to the latest (or most recent) WordCamp.
 */
function canonical_years_redirect() {
	global $wpdb;

	$domain    = filter_var( $_SERVER['HTTP_HOST'], FILTER_VALIDATE_DOMAIN );
	$cache_key = 'current_blog_' . $domain;

	/**
	 * Read blog details from the cache key and set one for the current
	 * domain if exists to prevent lookups by core later.
	 */
	$current_blog = wp_cache_get( $cache_key, 'site-options' );

	if ( ! $current_blog ) {
		$current_blog = get_blog_details(
			array(
				'domain' => $domain,
				'path'   => '/',
			),
			false
		);

		if ( $current_blog ) {
			wp_cache_set( $cache_key, $current_blog, 'site-options' );
		} else {
			// Return early if not a third- or fourth-level domain, e.g., city.wordcamp.org, year.city.wordcamp.org.
			$domain_parts = explode( '.', $domain );

			if ( 2 >= count( $domain_parts ) ) {
				return;
			}

			// Default clause for retrieving the most recent year for a city.
			$like = "%.{$domain}";

			// Special cases where the redirect shouldn't go to next year's camp until this year's camp is over.
			switch ( $domain ) {
				case 'europe.wordcamp.org':
					if ( time() <= strtotime( '2020-06-07' ) ) {
						$like = '2020.europe.wordcamp.org';
					}
					break;

				case 'us.wordcamp.org':
					if ( time() <= strtotime( '2019-11-30' ) ) {
						$like = '2019.us.wordcamp.org';
					}
					break;
			}

			// Search for year.city.wordcamp.org.
			$latest = $wpdb->get_row( $wpdb->prepare( "
				SELECT `domain`, `path`
				FROM $wpdb->blogs
				WHERE domain LIKE %s
				ORDER BY domain DESC
				LIMIT 1;",
				$like
			) );

			if ( $latest ) {
				header( 'Location: https://' . $latest->domain . $latest->path, true, 301 );
				die();
			}
		}
	}
}

/**
 * Centralized place to define domain-based redirects.
 *
 * Used by sunrise.php and WordCamp_Lets_Encrypt_Helper::rest_callback_domains.
 *
 * @return array
 */
function get_domain_redirects() {
	$central = 'central.wordcamp.org';

	return array(
		// Central redirects.
		'bg.wordcamp.org'                        => $central,
		'denmark.wordcamp.org'                   => $central,
		'finland.wordcamp.org'                   => $central,
		'india.wordcamp.org'                     => $central,
		'utah.wordcamp.org'                      => $central,

		// Language redirects.
		'ca.2014.mallorca.wordcamp.org'          => '2014-ca.mallorca.wordcamp.org',
		'de.2014.mallorca.wordcamp.org'          => '2014-de.mallorca.wordcamp.org',
		'es.2014.mallorca.wordcamp.org'          => '2014-es.mallorca.wordcamp.org',
		'fr.2011.montreal.wordcamp.org'          => '2011-fr.montreal.wordcamp.org',
		'fr.2012.montreal.wordcamp.org'          => '2012-fr.montreal.wordcamp.org',
		'fr.2013.montreal.wordcamp.org'          => '2013-fr.montreal.wordcamp.org',
		'fr.2014.montreal.wordcamp.org'          => '2014-fr.montreal.wordcamp.org',
		'2014.fr.montreal.wordcamp.org'          => '2014-fr.montreal.wordcamp.org',
		'fr.2013.ottawa.wordcamp.org'            => '2013-fr.ottawa.wordcamp.org',

		// Year & name change redirects.
		'2006.wordcamp.org'                      => '2006.sf.wordcamp.org',
		'2007.wordcamp.org'                      => '2007.sf.wordcamp.org',
		'2012.torontodev.wordcamp.org'           => '2012-dev.toronto.wordcamp.org',
		'2013.windsor.wordcamp.org'              => '2013.lancaster.wordcamp.org',
		'2014.lima.wordcamp.org'                 => '2014.peru.wordcamp.org',
		'2014.london.wordcamp.org'               => '2015.london.wordcamp.org',
		'2016.pune.wordcamp.org'                 => '2017.pune.wordcamp.org',
		'2016.bristol.wordcamp.org'              => '2017.bristol.wordcamp.org',
		'2017.cusco.wordcamp.org'                => '2018.cusco.wordcamp.org',
		'2017.dayton.wordcamp.org'               => '2018.dayton.wordcamp.org',
		'2017.niagara.wordcamp.org'              => '2018.niagara.wordcamp.org',
		'2017.saintpetersburg.wordcamp.org'      => '2018.saintpetersburg.wordcamp.org',
		'2017.zilina.wordcamp.org'               => '2018.zilina.wordcamp.org',
		'2018.wurzburg.wordcamp.org'             => '2018.wuerzburg.wordcamp.org',
		'2019.lisbon.wordcamp.org'               => '2019.lisboa.wordcamp.org',
		'2018.kolkata.wordcamp.org'              => '2019.kolkata.wordcamp.org',
		'2018.montclair.wordcamp.org'            => '2019.montclair.wordcamp.org',
		'2018.pune.wordcamp.org'                 => '2019.pune.wordcamp.org',
		'2018.dc.wordcamp.org'                   => '2019.dc.wordcamp.org',
		'2019.sevilla.wordcamp.org'              => '2019-developers.sevilla.wordcamp.org',
		'2019.telaviv.wordcamp.org'              => '2020.telaviv.wordcamp.org',
		'2020-barcelona.publishers.wordcamp.org' => '2020.barcelona.wordcamp.org',
		'2020.losangeles.wordcamp.org'           => '2020.la.wordcamp.org',
		'2020.bucharest.wordcamp.org'            => '2021.bucharest.wordcamp.org',

		// Misc redirects.
		'wordcampsf.org'                         => 'sf.wordcamp.org',
		'wordcampsf.com'                         => 'sf.wordcamp.org',

		// Temporary redirects.
		'2018.philly.wordcamp.org'               => '2018.philadelphia.wordcamp.org', // TODO Eventually rename `philadelphia` sites to `philly` for consistency across years, then setup permanent redirects to `philly`.
		'2019.philly.wordcamp.org'               => '2019.philadelphia.wordcamp.org',
		'philly.wordcamp.org'                    => '2019.philadelphia.wordcamp.org',
	);
}

/**
 * WordCamp.org Redirects
 *
 * General non-pattern redirects in the network.
 */
function site_redirects() {
	$domain           = filter_var( $_SERVER['HTTP_HOST'], FILTER_VALIDATE_DOMAIN );
	$domain_redirects = get_domain_redirects();
	$redirect         = false;

	if ( in_array( $domain, array( 'wordcamp.org', 'wordcamp.test', 'buddycamp.org', 'buddycamp.test' ), true )
		 && ! is_network_admin()
		 && ! is_admin()
		 && ! preg_match( '/^\/(?:wp\-admin|wp\-login|wp\-cron|wp\-json|xmlrpc)\.php/i', $_SERVER['REQUEST_URI'] )
	) {
		$redirect = sprintf( '%s%s', NOBLOGREDIRECT, $_SERVER['REQUEST_URI'] );
	} elseif ( isset( $domain_redirects[ $domain ] ) ) {
		$new_url = $domain_redirects[ $domain ];

		// Central has a different content structure than other WordCamp sites, so don't include the request URI
		// if that's where we're going.
		if ( 'central.wordcamp.org' !== $new_url ) {
			$new_url .= $_SERVER['REQUEST_URI'];
		}

		$redirect = "https://$new_url";
	}

	if ( $redirect ) {
		header( 'Location: ' . $redirect );
		die();
	}
}

if ( php_sapi_name() !== 'cli' ) {
	site_redirects();
	unsubdomactories_redirects();
	canonical_years_redirect();
}

/*
 * Allow CLI scripts in local dev environments to override the server hostname.
 *
 * This makes it possible to run bin scripts in local environments that use different domain names (e.g., wordcamp.dev)
 * without having to swap the config values back and and forth.
 */
if ( 'cli' === php_sapi_name() && defined( 'CLI_HOSTNAME_OVERRIDE' ) ) {
	$_SERVER['HTTP_HOST'] = str_replace( 'wordcamp.org', CLI_HOSTNAME_OVERRIDE, $_SERVER['HTTP_HOST'] );
}
