<?php

namespace WordCamp\Sunrise;
defined( 'WPINC' ) || die();

// phpcs:disable WordPress.WP.AlternativeFunctions.parse_url_parse_url -- It's not available this early.


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


/*
 * Allow legacy CLI scripts in local dev environments to override the server hostname.
 *
 * This makes it possible to run bin scripts in local environments that use different domain names (e.g., wordcamp.dev)
 * without having to swap the config values back and and forth.
 */
if ( 'cli' === php_sapi_name() && defined( 'CLI_HOSTNAME_OVERRIDE' ) ) {
	$_SERVER['HTTP_HOST'] = str_replace( 'wordcamp.org', CLI_HOSTNAME_OVERRIDE, $_SERVER['HTTP_HOST'] );
}

/*
 * This must be enabled for `get_corrected_root_relative_url()` to work for images. If we ever want to disable it,
 * we'll need to come up with another solution for that, (e.g., nginx rewrite rules).
 *
 * This needs to be applied outside of `main()` so that it takes effect in CLI environments, for consistency.
 */
add_filter(
	'pre_site_option_ms_files_rewriting',
	function() {
		return '1';
	}
);

// Redirecting would interfere with bin scripts, unit tests, etc.
if ( php_sapi_name() !== 'cli' ) {
	main();
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

	$status_code = 301;
	$redirect    = site_redirects( $domain, $_SERVER['REQUEST_URI'] );

	if ( ! $redirect ) {
		$redirect = get_city_slash_year_url( $domain, $_SERVER['REQUEST_URI'] );
	}

	/*
	 * This has to run before `get_canonical_year_url()`, because that function will redirect these requests to
	 * the latest site instead of the intended one, and would strip out the request URI.
	 */
	if ( ! $redirect ) {
		$redirect = get_corrected_root_relative_url( $domain, $path, $_SERVER['REQUEST_URI'], $_SERVER['HTTP_REFERER'] ?? '' );

		if ( $redirect ) {
			/*
			 * This isn't a permanent redirect, because the value changes based on the referrer. `europe.wordcamp.org/2019` and
			 * `europe.wordcamp.org/2020` might both have `/tickets` links that would both resolve to
			 * `europe.wordcamp.org/tickets`, but the request will be routed to a different site each time, based on the referrer.
			 */
			$status_code = 302;
		}
	}

	// Do this one last, because it sometimes executes a database query.
	if ( ! $redirect ) {
		$redirect = get_canonical_year_url( $domain, $path );
	}

	if ( ! $redirect ) {
		return;
	}

	header( 'Location: ' . $redirect, true, $status_code );
	die();
}

/**
 * Get the TLD for the current environment.
 *
 * @return string
 */
function get_top_level_domain() {
	return 'local' === WORDCAMP_ENVIRONMENT ? 'test' : 'org';
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

	$domain    = filter_var( $_SERVER['HTTP_HOST'], FILTER_VALIDATE_DOMAIN );
	$site_path = $is_slash_year_site ? $matches[4] : '/';

	return array(
		'domain' => $domain,
		'path'   => $site_path,
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
 * @return string|false
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
 * Centralized place to define domain-based redirects.
 *
 * Used by sunrise.php and WordCamp_Lets_Encrypt_Helper::rest_callback_domains.
 *
 * @return array
 */
function get_domain_redirects() {
	$tld     = get_top_level_domain();
	$central = "central.wordcamp.$tld";

	$redirects = array(
		// Central redirects.
		"bg.wordcamp.$tld"   => $central,
		"utah.wordcamp.$tld" => $central,

		// Language redirects.
		"ca.2014.mallorca.wordcamp.$tld" => "mallorca.wordcamp.$tld/2014-ca",
		"de.2014.mallorca.wordcamp.$tld" => "mallorca.wordcamp.$tld/2014-de",
		"es.2014.mallorca.wordcamp.$tld" => "mallorca.wordcamp.$tld/2014-es",
		"fr.2011.montreal.wordcamp.$tld" => "montreal.wordcamp.$tld/2011-fr",
		"fr.2012.montreal.wordcamp.$tld" => "montreal.wordcamp.$tld/2012-fr.",
		"fr.2013.montreal.wordcamp.$tld" => "montreal.wordcamp.$tld/2013-fr",
		"fr.2014.montreal.wordcamp.$tld" => "montreal.wordcamp.$tld/2014-fr",
		"2014.fr.montreal.wordcamp.$tld" => "montreal.wordcamp.$tld/2014-fr",
		"fr.2013.ottawa.wordcamp.$tld"   => "ottawa.wordcamp.$tld/2013-fr",

		// Year & name change redirects.
		"2006.wordcamp.$tld"                      => "sf.wordcamp.$tld/2006",
		"2007.wordcamp.$tld"                      => "sf.wordcamp.$tld/2007",
		"2012.torontodev.wordcamp.$tld"           => "toronto.wordcamp.$tld/2012-dev",
		"2013.windsor.wordcamp.$tld"              => "lancaster.wordcamp.$tld/2013",
		"2014.lima.wordcamp.$tld"                 => "peru.wordcamp.$tld/2014",
		"2014.london.wordcamp.$tld"               => "london.wordcamp.$tld/2015",
		"2016.pune.wordcamp.$tld"                 => "pune.wordcamp.$tld/2017",
		"2016.bristol.wordcamp.$tld"              => "bristol.wordcamp.$tld/2017",
		"2017.cusco.wordcamp.$tld"                => "cusco.wordcamp.$tld/2018",
		"2017.dayton.wordcamp.$tld"               => "dayton.wordcamp.$tld/2018",
		"2017.niagara.wordcamp.$tld"              => "niagara.wordcamp.$tld/2018",
		"2017.saintpetersburg.wordcamp.$tld"      => "saintpetersburg.wordcamp.$tld/2018",
		"2017.zilina.wordcamp.$tld"               => "zilina.wordcamp.$tld/2018",
		"2018.wurzburg.wordcamp.$tld"             => "wuerzburg.wordcamp.$tld/2018",
		"2019.lisbon.wordcamp.$tld"               => "lisboa.wordcamp.$tld/2019",
		"2018.kolkata.wordcamp.$tld"              => "kolkata.wordcamp.$tld/2019",
		"2018.montclair.wordcamp.$tld"            => "montclair.wordcamp.$tld/2019",
		"2018.pune.wordcamp.$tld"                 => "pune.wordcamp.$tld/2019",
		"2018.dc.wordcamp.$tld"                   => "dc.wordcamp.$tld/2019",
		"2019.sevilla.wordcamp.$tld"              => "sevilla.wordcamp.$tld/2019-developers",
		"2019.telaviv.wordcamp.$tld"              => "telaviv.wordcamp.$tld/2020",
		"2020-barcelona.publishers.wordcamp.$tld" => "barcelona.wordcamp.$tld/2020",
		"2020.losangeles.wordcamp.$tld"           => "2020.la.wordcamp.$tld",
		"2020.bucharest.wordcamp.$tld"            => "bucharest.wordcamp.$tld/2021",
		"philly.wordcamp.$tld"                    => "philadelphia.wordcamp.$tld",
		"2010.philly.wordcamp.$tld"               => "philadelphia.wordcamp.$tld/2010",
		"2011.philly.wordcamp.$tld"               => "philadelphia.wordcamp.$tld/2011",
		"2012.philly.wordcamp.$tld"               => "philadelphia.wordcamp.$tld/2012",
		"2014.philly.wordcamp.$tld"               => "philadelphia.wordcamp.$tld/2014",
		"2015.philly.wordcamp.$tld"               => "philadelphia.wordcamp.$tld/2015",
		"2017.philly.wordcamp.$tld"               => "philadelphia.wordcamp.$tld/2017",
		"2018.philly.wordcamp.$tld"               => "philadelphia.wordcamp.$tld/2018",
		"2019.philly.wordcamp.$tld"               => "philadelphia.wordcamp.$tld/2019",

		/*
		 * External domains.
		 *
		 * Unlike the others, these should keep the actual TLD in the array key, because they don't exist in the database.
		 */
		'wordcampsf.org' => "sf.wordcamp.$tld",
		'wordcampsf.com' => "sf.wordcamp.$tld",
	);

	// The array values are treated like a domain, and will be slashed by the caller.
	array_walk( $redirects, 'untrailingslashit' );

	return $redirects;
}

/**
 * Redirects from `year.city.wordcamp.org` to `city.wordcamp.org/year`.
 *
 * This is needed so that old external links will redirect to the current URL structure. New cities don't need to
 * be added to this list, only the ones that existed before the 2020 migration.
 *
 * See https://make.wordpress.org/community/2020/03/03/proposal-for-wordcamp-sites-seo-fixes/
 *
 * @param string $domain
 * @param string $request_uri
 *
 * @return string|false
 */
function get_city_slash_year_url( $domain, $request_uri ) {
	$tld = get_top_level_domain();

	$redirect_cities = array(
		/*
		 * These domains were created before the 2014 migration, and moved from `unsubdomactories_redirects()`
		 * during the 2020 migration.
		 */
		'barcelona', 'chicago', 'columbus', 'geneve', 'philly', 'philadelphia', 'publishers',
		'athens', 'atlanta', 'austin', 'brighton', 'europe', 'nyc', 'newyork', 'organizers', 'rhodeisland', 'sf',
		'cincinnati', 'dayton', 'denmark', 'finland', 'india', 'seattle', 'sunshinecoast', 'testing', 'varna',
		'denver', 'norway', 'russia', 'sofia', 'tokyo', 'toronto',
		'mexico', 'mexicocity', 'colombia', 'saopaulo', 'iloilo', 'lima', 'pokhara',
		'peoria', 'torino', 'aalborg', 'cebu', 'butwal', 'centroamerica', 'london', 'londonca',
		'portland', 'portlandme', 'miami', 'mallorca', 'montreal', 'ottawa', 'bristol', 'cusco', 'niagara',
		'saintpetersburg','zilina','wuerzburg', 'kolkata', 'montclair', 'telaviv', 'bucharest',
		'lancasterpa','lancaster', 'peru', 'pune', 'lisboa', 'sevilla',
		'us', 'dc', 'phoenix', 'slc', 'boston', 'orlando', 'melbourne',
		'oc', 'vegas', 'capetown', 'victoria', 'birmingham', 'birminghamuk', 'maine',
		'albuquerque', 'sacramento', 'calgary', 'porto', 'portoalegre', 'tampa',
		'seoul', 'paris', 'osaka', 'kansascity', 'curitiba', 'buffalo', 'baroda', 'sandiego', 'nepal', 'raleigh',
		'baltimore', 'sydney', 'providence', 'dfw', 'copenhagen', 'kansai',
		'biarritz', 'charleston', 'buenosaires', 'krakow', 'vienna', 'grandrapids', 'hamilton', 'minneapolis',
		'stlouis', 'edinburgh', 'winnipeg', 'northcanton', 'sanantonio', 'prague',
		'slovakia', 'salvador', 'maui', 'hamptonroads', 'houston', 'warsaw', 'belgrade', 'mumbai',
		'belohorizonte',  'switzerland', 'romania', 'saratoga', 'fayetteville',
		'bournemouth', 'hanoi',  'cologne', 'louisville', 'annarbor', 'manchester',
		'laspenitas', 'israel', 'ventura', 'vancouver', 'auckland', 'norrkoping', 'netherlands',
		'hamburg', 'nashville', 'connecticut', 'sheffield', 'wellington', 'omaha', 'milwaukee',
		'riodejaneiro', 'wroclaw', 'santarosa', 'edmonton', 'kenya',
		'malaga', 'lithuania', 'detroit', 'kobe', 'reno', 'indonesia', 'transylvania', 'nicaragua',
		'gdansk', 'bologna', 'milano', 'catania', 'modena', 'stockholm', 'jerusalem', 'philippines',
		'newzealand', 'cuttack', 'ponce', 'jabalpur', 'singapore', 'poznan', 'richmond', 'goldcoast', 'caguas',
		'savannah', 'ecuador', 'boulder', 'rdu', 'nc', 'lyon', 'scranton', 'brisbane', 'easttroy',
		'croatia', 'cantabria', 'greenville', 'jacksonville', 'nuremberg', 'berlin', 'memphis', 'jakarta',
		'pittsburgh', 'nola', 'neo', 'antwerp', 'helsinki', 'vernon', 'frankfurt', 'bilbao',
		'gdynia', 'lehighvalley', 'lahore', 'bratislava', 'okc', 'la', 'rochester', 'ogijima', 'asheville',


		// These domains were created after the 2014 URL migration was reverted, but before the 2020 migration.
		'rome', 'ahmedabad', 'alicante', 'asia', 'bangkok', 'bari', 'belfast', 'bengaluru', 'bern',
		'bharatpur', 'bhopal', 'bhubaneswar', 'biratnagar', 'bogota', 'boise', 'bordeaux', 'brno', 'buea',
		'bulawayo', 'bulgaria', 'caceres', 'cadiz', 'cali', 'cancun', 'cardiff', 'cartagena', 'charlotte',
		'chiclana', 'colombo', 'davao', 'delhi', 'denpasar', 'dhaka', 'douala', 'dublin', 'dusseldorf',
		'entebbe', 'floripa', 'nice', 'niigata', 'nijmegen', 'nis', 'noordnederland', 'nordic',
		'geneva', 'glasgow', 'granada', 'guadalajara', 'guayaquil', 'halifax', 'haneda', 'harare', 'hongkong',
		'ileife', 'irun', 'islamabad',  'jackson', 'johannesburg', 'jyvaskyla', 'kampala', 'kanpur',
		'karachi', 'kathmandu', 'kent', 'kigali', 'kochi', 'kosice', 'kotakinabalu', 'kualalumpur', 'kyiv',
		'kyoto', 'lagos', 'laspalmas', 'laspalmasgc', 'lausanne', 'lille', 'littlerock', 'lodz',
		'longbeach', 'lublin', 'madison', 'madrid', 'managua', 'manila', 'mannheim', 'marbella', 'marseille',
		'medellin', 'mombasa', 'montevideo', 'moscow', 'myrtlebeach', 'nagpur', 'nairobi', 'nashik', 'newcastle',

//		'oslo', 'osnabrueck', 'panamacity', 'perth', 'plovdiv', 'pontevedra', 'portharcourt', 'portmacquarie',
//		'portugal', 'puebla', 'puntarenas', 'quito', 'retreat', 'riga', 'riverside', 'rockford',
//		 'rotterdam', 'saigon', 'sancarlos', 'sanjose', 'santaclarita', 'santander', 'skopje', 'spain',
//		'split', 'stuttgart', 'taipei', 'tampere', 'thessaloniki', 'tulsa', 'turku', 'ubud', 'udaipur', 'utrecht',
//		'vadodara', 'valencia', 'valladolid', 'verona', 'virginiabeach', 'vrsac', 'waukesha', 'wilmington',
//		'zagreb', 'zaragoza', 'zurich',

		// Wait until event is over, then move to post-2014 list.
//		'italia',
	);

	if ( ! preg_match( PATTERN_YEAR_DOT_CITY_DOMAIN_PATH, $domain . $request_uri, $matches ) ) {
		return false;
	}

	$year = $matches[1];
	$city = strtolower( $matches[2] );

	if ( ! in_array( $city, $redirect_cities ) ) {
		return false;
	}

	return sprintf( 'https://%s.wordcamp.%s/%s%s', $city, $tld, $year, $request_uri );
}

/**
 * Get the intended URL of root-relative links created before the 2020 URL migration.
 *
 * With the old `{year}.{city}.wordcamp.org` URL format, organizers could create links like `/tickets` inside
 * posts, menu items, widgets, custom CSS, etc. Those would correctly resolve to
 * `{year}.{city}.wordcamp.org/tickets`, because the site was a _subdomain_ of their `{city}.wordcamp.org` domain.
 * Now that those sites have been migrated to _subdirectories_,  those root-relative links resolve to
 * `{city}.wordcamp.org/tickets`, which doesn't exist. We need to redirect those requests to
 * `{city}.wordcamp.org/{year}/tickets`.
 *
 * Changing the actual content in the database is complex and error-prone, so it's easier to catch the requests
 * here and redirect them. The HTTP referer gives us the information we need to know which site to redirect to.
 *
 * Note that this won't work for requests for CSS and JS files, since they don't pass through WP. There shouldn't
 * be any instances of that, though, because those links should all be generated programatically. This _does_ work
 * images, because Nginx routes those requests through `ms-files`. This will stop working for images if we ever
 * disable `ms_files_rewriting`.
 *
 * This doesn't handle the edge case of a site like `europe.wordcamp.org/2020` linking to `europe.wordcamp.org`
 * and intending that to redirect to the latest/canonical year. That's should be rare, though.
 *
 * For an end-to-end test, run:
 * `curl --silent --location --head 'https://vancouver.wordcamp.test/schedule/' -H 'Referer: https://vancouver.wordcamp.test/2016/' |grep location`
 * That should output: `https://vancouver.wordcamp.test/2016/schedule`.
 *
 * To see some examples of root-relative links in the database, run these commands:
 *
 * - Posts: `wp db search 'href="/' $( wp db tables '*_posts'    --all-tables --format=list ) --all-tables --table_column_once`
 * - CSS:   `wp db search "url('/"  $( wp db tables '*_posts'    --all-tables --format=list ) --all-tables --table_column_once`
 * - Menus: `wp db search '^/'      $( wp db tables '*_postmeta' --all-tables --format=list ) --all-tables --table_column_once --regex`
 *
 * Those contain some false-positives, and there are also additional cases in options, widgets, CPT postmeta, etc
 * that won't be found by those queries. It should give you a good idea, though.
 *
 * @param string $domain
 * @param string $path
 * @param string $request_uri
 * @param string $referer
 *
 * @return string|false
 */
function get_corrected_root_relative_url( $domain, $path, $request_uri, $referer ) {
	// Only requests to the root `{city}.wordcamp.org` sites are potentially broken.
	if ( preg_match( PATTERN_CITY_SLASH_YEAR_DOMAIN_PATH, $domain . $path ) ) {
		return false;
	}

	if ( preg_match( PATTERN_YEAR_DOT_CITY_DOMAIN_PATH, $domain . $path ) ) {
		return false;
	}

	if ( '/' !== $path ) {
		return false;
	}

	// Only requests from `{city}.wordcamp.org/{year}` sites are potentially broken.
	$referer_parts = parse_url( $referer );

	if ( ! isset( $referer_parts['host'], $referer_parts['path'] ) ) {
		return false;
	}

	$modified_referer = $referer_parts['host'] . $referer_parts['path'];

	/*
	 * Root-relative links would only be to the same domain. If a different WordCamp site was linking to this one,
	 * it would always contain the full host, path, etc.
	 */
	if ( $domain !== $referer_parts['host'] ) {
		return false;
	}

	if ( ! preg_match( PATTERN_CITY_SLASH_YEAR_DOMAIN_PATH, $modified_referer, $referer_matches ) ) {
		return false;
	}

	/*
	 * The situation only affects sites that were created before the 2020 URL migration. This check isn't precise,
	 * but it's close enough, and can be made more robust in the future if needed.
	 */
	$referer_site_path = $referer_matches[4];

	if ( (int) filter_var( $referer_site_path, FILTER_SANITIZE_NUMBER_INT ) >= 2021 ) {
		return false;
	}

	$is_file = false !== stripos( $request_uri, '/files/' ) && false !== stripos( basename( $request_uri ), '.' );

	$corrected_url = sprintf(
		'https://%s%s%s',
		untrailingslashit( $referer_parts['host'] ),
		untrailingslashit( $referer_site_path ),
		$is_file ? $request_uri : trailingslashit( $request_uri )
	);

	return $corrected_url;
}

/**
 * Get the URL of the newest site for a given city.
 *
 * For example, `seattle.wordcamp.org` -> `seattle.wordcamp.org/2020`.
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

	$tld       = get_top_level_domain();
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
			'path'   => $path,
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
		case "europe.wordcamp.$tld":
			if ( time() <= strtotime( '2020-06-07' ) ) {
				return "https://europe.wordcamp.$tld/2020/";
			}
			break;

		case "us.wordcamp.$tld":
			if ( time() <= strtotime( '2019-11-30' ) ) {
				return "https://us.wordcamp.$tld/2019";
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
