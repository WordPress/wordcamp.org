<?php

namespace WordCamp\SEO;
use function WordCamp\Sunrise\get_top_level_domain;

use const WordCamp\Sunrise\{ PATTERN_YEAR_DOT_CITY_DOMAIN_PATH, PATTERN_CITY_SLASH_YEAR_DOMAIN_PATH };

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
	global $current_blog;

	// We don't want to penalize historical content, we just want to boost the new site.
	if ( ! is_front_page() ) {
		return;
	}

	$latest_domain = get_latest_home_url( $current_blog->domain, $current_blog->path );

	// Nothing to do. `wporg-seo` will still print the standard canonical link.
	if ( ! $latest_domain || $latest_domain === $current_blog->domain ) {
		return;
	}

	// Remove default canonical link, to avoid duplicates.
	// @todo: This will need to be updated if rel_canonical_link() is ever merged to Core.
	remove_action( 'wp_head', 'WordPressdotorg\SEO\Canonical\rel_canonical_link' );

	printf(
		'<link rel="canonical" href="%s" />' . "\n",
		esc_url( $latest_domain )
	);
}

/**
 * Get the home URL of the most recent camp in a given city.
 *
 * @param string $current_domain
 * @param string $current_path
 *
 * @return bool|string
 */
function get_latest_home_url( $current_domain, $current_path ) {
	global $wpdb;

	$tld      = get_top_level_domain();
	$wordcamp = get_wordcamp_post();
	$end_date = absint( $wordcamp->meta['End Date (YYYY-mm-dd)'][0] ?? 0 );

	/*
	 * In rare cases, the site for next year's camp will be created before this year's camp is over. When that
	 * happens, we should wait to add the canonical link until after the current year's camp is over.
	 *
	 * This won't prevent the link from being added to past years, but that edge case isn't significant enough
	 * to warrant the extra complexity.
	 */
	if ( $end_date && time() < ( (int) $end_date + DAY_IN_SECONDS ) ) {
		return false;
	}

	if ( preg_match( PATTERN_YEAR_DOT_CITY_DOMAIN_PATH, $current_domain . $current_path ) ) {
		// Remove the year prefix.
		$city_domain = substr(
			$current_domain,
			strpos( $current_domain, '.' ) + 1
		);

		$query = $wpdb->prepare( "
			SELECT `domain`, `path`
			FROM `$wpdb->blogs`
			WHERE
				`domain` LIKE %s AND
				SUBSTR( domain, 1, 4 ) REGEXP '^-?[0-9]+$' -- exclude secondary language domains like 2013-fr.ottawa.wordcamp.org
			ORDER BY `domain` DESC
			LIMIT 1",
			'%.' . $city_domain
		);

	} elseif ( preg_match( PATTERN_CITY_SLASH_YEAR_DOMAIN_PATH, $current_domain . $current_path ) ) {
		$query = $wpdb->prepare( "
			SELECT `domain`, `path`
			FROM `$wpdb->blogs`
			WHERE `domain` = %s
			ORDER BY `domain`, `path` DESC
			LIMIT 1",
			$current_domain
		);
	} else {
		return false;
	}

	$latest_site = $wpdb->get_results( $query ); // phpcs:ignore -- Prepared above.

	if ( ! $latest_site ) {
		return false;
	}

	return set_url_scheme( trailingslashit( '//' . $latest_site[0]->domain . $latest_site[0]->path ) );
}
