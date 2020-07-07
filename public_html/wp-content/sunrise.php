<?php

namespace WordCamp\Sunrise;
defined( 'WPINC' ) or die();


/*
 * Matches `2020-foo.narnia.wordcamp.org/`, with or without additional `REQUEST_URI` params.
 */
const PATTERN_YEAR_DOT_CITY_DOMAIN_PATH = '
	@ ^
	( \d{4} [\w-]* )           # Capture the year, plus any optional extra identifier.
	\.
	( [\w-]+ )                 # Capture the city.
	\.
	( wordcamp | buddycamp )   # Capture the second-level domain.
	\.
	( org | test )             # Capture the top level domain.
	/
	@ix
';

/*
 * Matches `narnia.wordcamp.org/2020-foo/`, with or without additional `REQUEST_URI` params.
 */
const PATTERN_CITY_SLASH_YEAR_DOMAIN_PATH = '
	@ ^
	( [\w-]+ )                 # Capture the city.
	\.
	( wordcamp | buddycamp )   # Capture the second-level domain.
	\.
	( org | test )             # Capture the top-level domain.
	( / \d{4} [\w-]* / )       # Capture the site path (the year, plus any optional extra identifier).
	@ix
';

/*
 * Matches a request URI like `/2020/2019/save-the-date-for-wordcamp-vancouver-2020/`.
 */
const PATTERN_CITY_SLASH_YEAR_REQUEST_URI_WITH_DUPLICATE_DATE = '
	@ ^
	( / \d{4} [\w-]* / )   # Capture the site path (the year, plus any optional extra identifier).

	(                      # Capture the `/%year%/%monthnum%/%day%/` permastruct tags.
		[0-9]{4} /         # The year is required.

		(?:                # The month and day are optional.
			[0-9]{2} /
		){0,2}
	)

	(.+)                   # Capture the slug.
	$ @ix
';


/**
 * Get the TLD for the current environment.
 *
 * @return string
 */
function get_top_level_domain() {
	return 'local' === WORDCAMP_ENVIRONMENT ? 'test' : 'org';
}

/**
 * Redirects from `year.city.wordcamp.org` to `city.wordcamp.org/year`.
 *
 * This is needed so that old external links will redirect to the current URL structure. New cities don't need to
 * be added to this list, only the ones that were migrated from the old structure to the new structure in July 2020.
 *
 * See https://make.wordpress.org/community/2020/03/03/proposal-for-wordcamp-sites-seo-fixes/
 *
 * @param string $domain
 * @param string $request_uri
 *
 * @return string
 */
function get_city_slash_year_url( $domain, $request_uri ) {
	$tld = get_top_level_domain();

	$redirect_cities = array(
		'seattle', 'testing', 'varna',
	);

	if ( ! preg_match( PATTERN_YEAR_DOT_CITY_DOMAIN_PATH, $domain . $request_uri, $matches ) ) {
		return false;
	}

	$year = $matches[1];
	$city = strtolower( $matches[2] );

	if ( ! in_array( $city, $redirect_cities ) ) {
		return false;
	}

	return sprintf( "https://%s.wordcamp.%s/%s%s", $city, $tld, $year, $request_uri );
}

/**
 * Redirects from city.wordcamp.org/year to year.city.wordcamp.org.
 *
 * This reverses the 2014 migration, so that sites use the year.city format again. Now that we've redoing the
 * migration, cities will be moved out of `$redirect_cities` until none remain.
 *
 * @param string $domain
 * @param string $request_uri
 *
 * @return string|false
 */
function unsubdomactories_redirects( $domain, $request_uri ) {
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

	$tld = 'local' === WORDCAMP_ENVIRONMENT ? 'test' : 'org';

	// Return if already on a 4th-level domain (e.g., 2020.narnia.wordcamp.org)
	if ( ! preg_match( "#^([a-z0-9-]+)\.wordcamp\.$tld$#i", $domain, $matches ) ) {
		return false;
	}

	$city = strtolower( $matches[1] );
	if ( ! in_array( $city, $redirect_cities, true ) ) {
		return false;
	}

	// If can't pick a year out of the path, return.
	// Extra alpha characters are included, for sites like `seattle.wordcamp.org/2015-beginners`.
	if ( ! preg_match( '#^/(\d{4}[a-z0-9-]*)#i', $request_uri, $matches ) ) {
		return false;
	}

	$year        = strtolower( $matches[1] );
	$pattern     = '#' . preg_quote( $year, '#' ) . '#';
	$path        = preg_replace( $pattern, '', $request_uri, 1 );
	$path        = str_replace( '//', '/', $path );
	$redirect_to = sprintf( "https://%s.%s.wordcamp.$tld%s", $year, $city, $path );

	return $redirect_to;
}

/**
 * Redirect `/year-foo/%year%/%monthnum%/%day%/%postname%/` permalinks to `/%postname%/`.
 *
 * `year-foo` is the _site_ slug, while `%year%` is part of the _post_ slug. This makes sure that URLs on old sites
 * won't have two years in them after the migration, which would look confusing.
 *
 * See https://make.wordpress.org/community/2014/12/18/while-working-on-the-new-url-structure-project/.
 *
 * Be aware that this does create a situation where posts and pages can have conflicting slugs, see
 * https://core.trac.wordpress.org/ticket/13459.
 */
function redirect_duplicate_year_permalinks_to_post_slug() {
	$current_blog_details = get_blog_details( null, false );

	$redirect_url = get_post_slug_url_without_duplicate_dates(
		is_404(),
		get_option( 'permalink_structure' ),
		$current_blog_details->domain,
		$current_blog_details->path,
		$_SERVER['REQUEST_URI']
	);

	if ( ! $redirect_url ) {
		return;
	}

	wp_safe_redirect( esc_url_raw( $redirect_url ), 301 );
	die();
}

/**
 * Build the redirect URL for a duplicate-date URL.
 *
 * See `redirect_duplicate_year_permalinks_to_post_slug()`.
 *
 * @param bool   $is_404
 * @param string $permalink_structure
 * @param string $domain
 * @param string $path
 * @param string $request_uri
 *
 * @return bool|string
 */
function get_post_slug_url_without_duplicate_dates( $is_404, $permalink_structure, $domain, $path, $request_uri ) {
	if ( ! $is_404 ) {
		return false;
	}

	if ( '/%postname%/' !== $permalink_structure ) {
		return false;
	}

	if ( ! preg_match( PATTERN_CITY_SLASH_YEAR_DOMAIN_PATH, $domain . $path ) ) {
		return false;
	}

	if ( ! preg_match( PATTERN_CITY_SLASH_YEAR_REQUEST_URI_WITH_DUPLICATE_DATE, $request_uri, $matches ) ) {
		return false;
	}

	return sprintf(
		'https://%s%s%s',
		$domain,
		$path,
		$matches[3]
	);
}

/**
 * Get the URL of the newest site for a given city.
 *
 * e.g., seattle.wordcamp.org -> seattle.wordcamp.org/2020
 *
 * Redirecting the city root to this URL makes it easier for attendees to find the correct site.
 *
 * @param string $domain
 * @param string $path
 *
 * @return string|false
 */
function get_canonical_year_url( $domain, $path ) {
	global $wpdb;

	$cache_key = 'current_blog_' . $domain;

	/**
	 * Read blog details from the cache key and set one for the current
	 * domain if exists to prevent lookups by core later.
	 */
	$current_blog = wp_cache_get( $cache_key, 'site-options' );

	if ( $current_blog ) {
		return false;
	}

	$current_blog = get_blog_details(
		array(
			'domain' => $domain,
			'path'   => $path
		),
		false
	);

	if ( $current_blog ) {
		wp_cache_set( $cache_key, $current_blog, 'site-options' );

		return false;
	}

	// Return early if not a third- or fourth-level domain, e.g., city.wordcamp.org, year.city.wordcamp.org.
	$domain_parts = explode( '.', $domain );

	if ( 2 >= count( $domain_parts ) ) {
		return false;
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

	$latest = $wpdb->get_row( $wpdb->prepare( "
		SELECT `domain`, `path`
		FROM $wpdb->blogs
		WHERE
			domain = %s OR -- Match city/year format.
			domain LIKE %s -- Match year.city format.
		ORDER BY path DESC, domain DESC
		LIMIT 1;",
		$domain,
		$like
	) );

	return $latest ? 'https://' . $latest->domain . $latest->path : false;
}

/**
 * Centralized place to define domain-based redirects.
 *
 * Used by sunrise.php and WordCamp_Lets_Encrypt_Helper::rest_callback_domains.
 *
 * @return array
 */
function get_domain_redirects() {
	$tld     = get_top_level_domain();
	$central = "central.wordcamp.$tld";

	return array(
		// Central redirects.
		"bg.wordcamp.$tld"                        => $central,
		"denmark.wordcamp.$tld"                   => $central,
		"finland.wordcamp.$tld"                   => $central,
		"india.wordcamp.$tld"                     => $central,
		"utah.wordcamp.$tld"                      => $central,

		// Language redirects.
		"ca.2014.mallorca.wordcamp.$tld"          => "2014-ca.mallorca.wordcamp.$tld",
		"de.2014.mallorca.wordcamp.$tld"          => "2014-de.mallorca.wordcamp.$tld",
		"es.2014.mallorca.wordcamp.$tld"          => "2014-es.mallorca.wordcamp.$tld",
		"fr.2011.montreal.wordcamp.$tld"          => "2011-fr.montreal.wordcamp.$tld",
		"fr.2012.montreal.wordcamp.$tld"          => "2012-fr.montreal.wordcamp.$tld",
		"fr.2013.montreal.wordcamp.$tld"          => "2013-fr.montreal.wordcamp.$tld",
		"fr.2014.montreal.wordcamp.$tld"          => "2014-fr.montreal.wordcamp.$tld",
		"2014.fr.montreal.wordcamp.$tld"          => "2014-fr.montreal.wordcamp.$tld",
		"fr.2013.ottawa.wordcamp.$tld"            => "2013-fr.ottawa.wordcamp.$tld",

		// Year & name change redirects.
		"2006.wordcamp.$tld"                      => "2006.sf.wordcamp.$tld",
		"2007.wordcamp.$tld"                      => "2007.sf.wordcamp.$tld",
		"2012.torontodev.wordcamp.$tld"           => "2012-dev.toronto.wordcamp.$tld",
		"2013.windsor.wordcamp.$tld"              => "2013.lancaster.wordcamp.$tld",
		"2014.lima.wordcamp.$tld"                 => "2014.peru.wordcamp.$tld",
		"2014.london.wordcamp.$tld"               => "2015.london.wordcamp.$tld",
		"2016.pune.wordcamp.$tld"                 => "2017.pune.wordcamp.$tld",
		"2016.bristol.wordcamp.$tld"              => "2017.bristol.wordcamp.$tld",
		"2017.cusco.wordcamp.$tld"                => "2018.cusco.wordcamp.$tld",
		"2017.dayton.wordcamp.$tld"               => "2018.dayton.wordcamp.$tld",
		"2017.niagara.wordcamp.$tld"              => "2018.niagara.wordcamp.$tld",
		"2017.saintpetersburg.wordcamp.$tld"      => "2018.saintpetersburg.wordcamp.$tld",
		"2017.zilina.wordcamp.$tld"               => "2018.zilina.wordcamp.$tld",
		"2018.wurzburg.wordcamp.$tld"             => "2018.wuerzburg.wordcamp.$tld",
		"2019.lisbon.wordcamp.$tld"               => "2019.lisboa.wordcamp.$tld",
		"2018.kolkata.wordcamp.$tld"              => "2019.kolkata.wordcamp.$tld",
		"2018.montclair.wordcamp.$tld"            => "2019.montclair.wordcamp.$tld",
		"2018.pune.wordcamp.$tld"                 => "2019.pune.wordcamp.$tld",
		"2018.dc.wordcamp.$tld"                   => "2019.dc.wordcamp.$tld",
		"2019.sevilla.wordcamp.$tld"              => "2019-developers.sevilla.wordcamp.$tld",
		"2019.telaviv.wordcamp.$tld"              => "2020.telaviv.wordcamp.$tld",
		"2020-barcelona.publishers.wordcamp.$tld" => "2020.barcelona.wordcamp.$tld",
		"2020.losangeles.wordcamp.$tld"           => "2020.la.wordcamp.$tld",
		"2020.bucharest.wordcamp.$tld"            => "2021.bucharest.wordcamp.$tld",

		/*
		 * External domains.
		 *
		 * Unlike the others, these should keep the actual TLD in the array key, because they don't exist in the database.
		 */
		"wordcampsf.org"                          => "sf.wordcamp.$tld",
		"wordcampsf.com"                          => "sf.wordcamp.$tld",

		// Temporary redirects.
		"2018.philly.wordcamp.$tld"               => "2018.philadelphia.wordcamp.$tld", // TODO Eventually rename `philadelphia` sites to `philly` for consistency across years, then setup permanent redirects to `philly`.
		"2019.philly.wordcamp.$tld"               => "2019.philadelphia.wordcamp.$tld",
		"philly.wordcamp.$tld"                    => "2019.philadelphia.wordcamp.$tld",
	);
}

/**
 * Get redirect URLs for root site requests and for hardcoded redirects.
 *
 * @todo Split this into two functions because these aren't related to each other.
 *
 * @param string $domain
 * @param string $request_uri
 *
 * @return string
 */
function site_redirects( $domain, $request_uri ) {
	$tld              = get_top_level_domain();
	$domain_redirects = get_domain_redirects();
	$redirect         = false;

	// If it's a front end request to the root site, redirect to Central.
	// todo This could be simplified, see https://core.trac.wordpress.org/ticket/42061#comment:15.
	if ( in_array( $domain, array( "wordcamp.$tld", "buddycamp.$tld" ), true )
		 && ! is_network_admin()
		 && ! is_admin()
		 && ! preg_match( '/^\/(?:wp\-admin|wp\-login|wp\-cron|wp\-json|xmlrpc)\.php/i', $request_uri )
	) {
		$redirect = sprintf( '%s%s', NOBLOGREDIRECT, $request_uri );

	} elseif ( isset( $domain_redirects[ $domain ] ) ) {
		$new_url = $domain_redirects[ $domain ];

		// Central has a different content structure than other WordCamp sites, so don't include the request URI
		// if that's where we're going.
		if ( "central.wordcamp.$tld" !== $new_url ) {
			$new_url .= $request_uri;
		}

		$redirect = "https://$new_url";
	}

	return $redirect;
}

/**
 * Parse the `$wpdb->blogs` `domain` and `path` out of the requested URL.
 *
 * This is only an educated guess, and cannot work in all cases (e.g., `central.wordcamp.org/2020` (year archive
 * page). It should only be used in situations where WP functions/globals aren't available yet, and should be
 * verified in whatever context you use it, e.g., with a database query. That's not done here because it wouldn't
 * be performant, and this is good enough to short-circuit the need for that in most situations.
 *
 * @return array
 */
function guess_requested_domain_path() {
	$request_path = trailingslashit( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );

	$is_slash_year_site = preg_match(
		PATTERN_CITY_SLASH_YEAR_DOMAIN_PATH,
		$_SERVER['HTTP_HOST'] . $request_path,
		$matches
	);

	$domain = filter_var( $_SERVER['HTTP_HOST'], FILTER_VALIDATE_DOMAIN );
	$site_path = $is_slash_year_site ? $matches[4] : '/';

	return array( 'domain' => $domain, 'path' => $site_path );
}

/**
 * Preempt `ms_load_current_site_and_network()` in order to set the correct site.
 */
function main() {
	list(
		'domain' => $domain,
		'path'   => $path
	) = guess_requested_domain_path();

	add_action( 'template_redirect', __NAMESPACE__ . '\redirect_duplicate_year_permalinks_to_post_slug' );

	$redirect = site_redirects( $domain, $_SERVER['REQUEST_URI'] );

	if ( ! $redirect ) {
		$redirect = get_city_slash_year_url( $domain, $_SERVER['REQUEST_URI'] );
	}

	if ( ! $redirect ) {
		$redirect = unsubdomactories_redirects( $domain, $_SERVER['REQUEST_URI'] );
	}

	if ( ! $redirect ) {
		$redirect = get_canonical_year_url( $domain, $path );
	}

	if ( ! $redirect ) {
		return;
	}

	header( 'Location: ' . $redirect, true, 301 );
	die();
}


// Redirecting would interfere with bin scripts, unit tests, etc.
if ( php_sapi_name() !== 'cli' ) {
	main();
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
