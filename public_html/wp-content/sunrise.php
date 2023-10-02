<?php

namespace WordCamp\Sunrise;
defined( 'WPINC' ) || die();


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
 * Matches a URL path like '/vancouver/2023/diversity-day/`.
 *
 * These are used by the `events.wordpress.org` network.
 */
const PATTERN_CITY_YEAR_TYPE_PATH = '
	@ ^
	/
	( [\w-]+ )    # Capture the city.
	/
	( \d{4} )     # Capture the year.
	/
	( [\w-]+ )    # Capture the event type.
	/?
	@ix
';

/**
 * Load the sunrise file for the current network.
 */
function load_network_sunrise() {
	switch ( SITE_ID_CURRENT_SITE ) {
		case EVENTS_NETWORK_ID:
			require __DIR__ . '/sunrise-events.php';
			break;

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


load_network_sunrise();
