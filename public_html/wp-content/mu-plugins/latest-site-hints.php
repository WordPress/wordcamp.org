<?php

namespace WordCamp\Latest_Site_Hints;
use const WordCamp\Sunrise\{ PATTERN_YEAR_DOT_CITY_DOMAIN_PATH, PATTERN_CITY_SLASH_YEAR_DOMAIN_PATH, PATTERN_CITY_YEAR_TYPE_PATH };

defined( 'WPINC' ) || die();

add_action( 'wp', __NAMESPACE__ . '\maybe_add_latest_site_hints' );

/**
 * If user or bot visits WordCamp site that has newer site for the same city,
 * add some hints for guiding them visit the latest site.
 */
function maybe_add_latest_site_hints() {
	global $current_blog;

	$latest_domain = get_latest_home_url( $current_blog->domain, $current_blog->path );

	// Check latest domain against current, in case there is newer site for the WordCamp.
	if ( ! $latest_domain || trailingslashit( get_site_url() ) === $latest_domain ) {
		return;
	}

	// Allow the banner to be skipped if necessary.
	if ( wcorg_skip_feature( 'latest-site-hint' ) ) {
		return;
	}

	// Hook in before `WordPressdotorg\SEO\Canonical::rel_canonical_link()`, so that callback can be removed.
	add_action( 'wp_head', __NAMESPACE__ . '\canonical_link_past_home_pages_to_current_year', 9 );

	// Add a banner with a link to the latest WordCamp.
	add_action( 'wp_head', __NAMESPACE__ . '\add_notification_styles' );
	add_action( 'wp_footer', __NAMESPACE__ . '\show_notification_about_latest_site' );

	// Close comments on past sites to prevent spam.
	add_filter( 'comments_open', '__return_false' );
	add_filter( 'pings_open', '__return_false' );
}

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
	if ( ! $latest_domain || trailingslashit( get_site_url() ) === $latest_domain ) {
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
 * Simple styles for the notification.
 */
function add_notification_styles() { ?>
  <style type="text/css">
		html:not(#specificity-hack) {
			/* 44 = 10px x2 for padding, 24px for line height. */
			margin-top: calc(44px + var(--wp-admin--admin-bar--height, 0px)) !important;
		}

		.wordcamp-latest-site-notify {
			background: #1d2327;
			text-align: center;
			padding: 10px 20px;
			font-size: 16px;
			line-height: 1.5;
			position: fixed;
			top: var(--wp-admin--admin-bar--height, 0);
			left: 0;
			width: 100%;
			z-index: 99998;
		}

		@media screen and (max-width: 600px) {
			.wordcamp-latest-site-notify {
				position: absolute;
			}
		}

		.wordcamp-latest-site-notify p,
		.wordcamp-latest-site-notify a {
			color: #f0f0f1;
			margin: 0;
		}

		.wordcamp-latest-site-notify a {
			font-weight: 600;
		}

		.wordcamp-latest-site-notify a:hover,
		.wordcamp-latest-site-notify a:active {
			color: #72aee6;
		}
  </style>
<?php }

/**
 * Show the actual notification containing link to latest site to user.
 */
function show_notification_about_latest_site() {
	global $current_blog;

	$latest_domain = get_latest_home_url( $current_blog->domain, $current_blog->path );

	// Check if there is newer site for the WordCamp.
	if ( ! $latest_domain || $latest_domain === $current_blog->domain ) {
		return;
	}

	echo '<div class="wordcamp-latest-site-notify"><p>' .
		wp_kses_post( wp_sprintf(
			// translators: %1$s is the name of the WordCamp, %2$s is the URL of the next edition.
			__( '%1$s is over. Check out <a href="%2$s">the next edition</a>!', 'wordcamporg' ),
			esc_html( get_blog_details( $current_blog->blog_id )->blogname ),
			esc_url( $latest_domain )
		) ) .
	'</p></div>';
}

/**
 * Get the home URL of the most recent event in a given city.
 *
 * For WordCamps, this is just the most recent WordCamp in the city. For NextGen events, it's the most recent event in that city with the same type.
 *
 * For example:
 * - `narnia.wordcamp.org/2023/` -> `narnia.wordcamp.org/2024/`
 * - `events.wordpress.org/narnia/2023/training/` -> `events.wordpress.org/narnia/2024/training/`
 *
 * @param string $current_domain
 * @param string $current_path
 *
 * @return bool|string
 */
function get_latest_home_url( $current_domain, $current_path ) {
	/*
	 * `maybe_add_latest_site_hints()`, `canonical_link_past_home_pages_to_current_year()`, and
	 * `show_notification_about_latest_site()` each call this during the same request, so memoize the result
	 * to avoid repeating the database work.
	 */
	static $cache = array();
	$cache_key = $current_domain . '|' . $current_path;

	if ( ! array_key_exists( $cache_key, $cache ) ) {
		$cache[ $cache_key ] = determine_latest_home_url( $current_domain, $current_path );
	}

	return $cache[ $cache_key ];
}

/**
 * Resolve the home URL of the most recent relevant event in a city.
 *
 * See `get_latest_home_url()`, which memoizes this.
 *
 * @param string $current_domain
 * @param string $current_path
 *
 * @return bool|string
 */
function determine_latest_home_url( $current_domain, $current_path ) {
	global $wpdb;

	/**
	 * In rare cases, the site for next year's camp will be created before this year's camp is over. When that
	 * happens, we should wait to add the canonical link until after the current year's camp is over.
	 *
	 * This won't prevent the link from being added to past years, but that edge case isn't significant enough
	 * to warrant the extra complexity.
	 *
	 * The current site's end date is mirrored to blogmeta by `WordCamp\Schedule_Meta`. See also
	 * `WordCamp\Sunrise\get_canonical_year_url()`.
	 */
	$current_end_date = absint( get_site_meta( get_current_blog_id(), '_wc_event_end', true ) );

	if ( $current_end_date && time() < $current_end_date + DAY_IN_SECONDS ) {
		return false;
	}

	/*
	 * Each candidate query joins the `_wc_event_end` blogmeta written by `WordCamp\Schedule_Meta`, which is
	 * present only for live, scheduled editions. Candidates are ordered newest-first and capped, then we pick
	 * the current edition (see `get_current_edition()`) so the banner skips empty placeholder sites for a
	 * future edition while a real one is still upcoming or only recently finished.
	 */
	if ( preg_match( PATTERN_YEAR_DOT_CITY_DOMAIN_PATH, $current_domain . $current_path ) ) {
		// Remove the year prefix.
		$city_domain = substr(
			$current_domain,
			strpos( $current_domain, '.' ) + 1
		);

		$query = $wpdb->prepare( "
			SELECT blog.domain, blog.path, event_end.meta_value AS event_end
			FROM `$wpdb->blogs` AS blog
			LEFT JOIN `$wpdb->blogmeta` AS event_end
				ON event_end.blog_id = blog.blog_id
				AND event_end.meta_key = '_wc_event_end'
			WHERE
				( blog.public AND NOT blog.deleted ) AND -- don't send visitors to sites that shouldn't receive traffic
				blog.domain LIKE %s AND
				SUBSTR( blog.domain, 1, 4 ) REGEXP '^-?[0-9]+$' -- exclude secondary language domains like 2013-fr.ottawa.wordcamp.org
			ORDER BY blog.domain DESC
			LIMIT 10",
			'%.' . $city_domain
		);

	} elseif ( preg_match( PATTERN_CITY_SLASH_YEAR_DOMAIN_PATH, $current_domain . $current_path ) ) {
		$query = $wpdb->prepare( "
			SELECT blog.domain, blog.path, event_end.meta_value AS event_end
			FROM `$wpdb->blogs` AS blog
			LEFT JOIN `$wpdb->blogmeta` AS event_end
				ON event_end.blog_id = blog.blog_id
				AND event_end.meta_key = '_wc_event_end'
			WHERE
				( blog.public AND NOT blog.deleted ) AND -- don't send visitors to sites that shouldn't receive traffic
				blog.domain = %s
			ORDER BY blog.domain, blog.path DESC
			LIMIT 10",
			$current_domain
		);

	} elseif ( preg_match( PATTERN_CITY_YEAR_TYPE_PATH, $current_path, $matches ) ) {
		$city        = $matches[1] ?? '';
		$type        = $matches[3] ?? '';
		$latest_path = "/$city/%%/$type/";

		$query = $wpdb->prepare( "
			SELECT blog.domain, blog.path, event_end.meta_value AS event_end
			FROM `$wpdb->blogs` AS blog
			LEFT JOIN `$wpdb->blogmeta` AS event_end
				ON event_end.blog_id = blog.blog_id
				AND event_end.meta_key = '_wc_event_end'
			WHERE
				( blog.public AND NOT blog.deleted ) AND -- don't send visitors to sites that shouldn't receive traffic
				blog.domain = %s AND
				blog.path LIKE %s
			ORDER BY blog.path DESC
			LIMIT 10",
			$current_domain,
			$latest_path
		);

	} else {
		return false;
	}

	$candidate_sites = $wpdb->get_results( $query ); // phpcs:ignore -- Prepared above.

	if ( ! $candidate_sites ) {
		return false;
	}

	$latest_site = get_current_edition( $candidate_sites );

	return set_url_scheme( trailingslashit( '//' . $latest_site->domain . $latest_site->path ) );
}

/**
 * Pick the edition a visitor should be sent to from a newest-first list of a city's sites.
 *
 * Returns the newest edition that's upcoming or only finished within the last month -- skipping any newer,
 * not-yet-scheduled placeholder sites in front of it -- and otherwise the newest site, placeholder or not.
 * This mirrors `WordCamp\Sunrise\get_current_edition_site()`, but operates on already-fetched rows.
 *
 * @param object[] $candidate_sites Rows with `domain`, `path`, and an `event_end` timestamp (empty for
 *                                   unscheduled placeholders), ordered newest-first.
 *
 * @return object The chosen site row.
 */
function get_current_edition( array $candidate_sites ) {
	foreach ( $candidate_sites as $site ) {
		$event_end = absint( $site->event_end );

		if ( $event_end && time() <= $event_end + MONTH_IN_SECONDS ) {
			return $site;
		}
	}

	return $candidate_sites[0];
}
