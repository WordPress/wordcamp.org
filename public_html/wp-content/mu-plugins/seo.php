<?php

namespace WordCamp\SEO;
defined( 'WPINC' ) || die();

// Hook in before `WordPressdotorg\SEO\Canonical::rel_canonical_link()`, so that callback can be removed.
add_action( 'wp_head', __NAMESPACE__ . '\canonical_link_past_home_pages_to_current_year', 9 );


/**
 * Add a `<link rel="canonical" ...` tag to the front page of past WordCamps, which points to the current year.
 *
 * This helps search engines know to direct queries for "WordCamp Seattle" to `seattle.wordcamp.org/2020`
 * instead of `seattle.wordcamp.org/2019`, even if `/2019` has a higher historic rank.
 */
function canonical_link_past_home_pages_to_current_year() {
	global $wpdb;

	// Only on the home page.
	if ( ! isset( $_SERVER['REQUEST_URI'] ) || '/' !== $_SERVER['REQUEST_URI'] ) {
		return;
	}

	$matches = array();

	// Match `year.city.wordcamp.org` pattern.
	// @todo add support for city.wordcamp.org/year-variant (e.g., seattle.wordcamp.org/2015-beginners/).
	if ( ! preg_match( '/^([0-9]{4})+\.((.+)\.wordcamp\.(lo|dev|test|org))$/i', $_SERVER['HTTP_HOST'], $matches ) ) {
		return;
	}

	$wordcamp = get_wordcamp_post();
	$end_date = $wordcamp->meta['End Date (YYYY-mm-dd)'][0] ?? false;

	/*
	 * In rare cases, the site for next year's camp will be created before this year's camp is over. When that
	 * happens, we should wait to add the canonical link until after the current year's camp is over.
	 *
	 * This won't prevent the link from being added to past years, but that edge case isn't significant enough
	 * to warrant the extra complexity.
	 */
	if ( $end_date && time() < ( (int) $end_date + DAY_IN_SECONDS ) ) {
		return;
	}

	$current_domain = $matches[0];
	$city_domain    = $matches[2];

	$latest_domain = $wpdb->get_var( $wpdb->prepare( "
		SELECT domain
		FROM $wpdb->blogs
		WHERE
			domain LIKE %s AND
			SUBSTR( domain, 1, 4 ) REGEXP '^-?[0-9]+$' -- exclude secondary language domains like fr.2013.ottawa.wordcamp.org
		ORDER BY domain
		DESC LIMIT 1;",
		"%.{$city_domain}"
	) );

	if ( $latest_domain !== $current_domain && $latest_domain ) {
		// Remove default canonical link, to avoid duplicates.
		// @todo: This will need to be updated if rel_canonical_link() is ever merged to Core.
		remove_action( 'wp_head', 'WordPressdotorg\SEO\Canonical\rel_canonical_link' );

		printf(
			'<link rel="canonical" href="%s" />' . "\n",
			esc_url( set_url_scheme( trailingslashit( $latest_domain ) ) )
		);
	}
}
