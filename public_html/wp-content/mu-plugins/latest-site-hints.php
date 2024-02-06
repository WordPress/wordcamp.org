<?php

namespace WordCamp\Latest_Site_Hints;
use function WordCamp\Sunrise\get_top_level_domain;
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

	// Check to see if they simply differ by an identifier.
	if (
		str_contains( $latest_domain . get_site_url(), '-' ) &&
		preg_replace( '/(\d{4})-[^/]+/', '$1', trailingslashit( get_site_url() ) ) === preg_replace( '/(\d{4})-[^/]+/', '$1', $latest_domain )
	) {
		return;
	}

	// Hook in before `WordPressdotorg\SEO\Canonical::rel_canonical_link()`, so that callback can be removed.
	add_action( 'wp_head', __NAMESPACE__ . '\canonical_link_past_home_pages_to_current_year', 9 );

	// Add a banner with a link to the latest WordCamp.
	add_action( 'wp_head', __NAMESPACE__ . '\add_notification_styles' );
	add_action( 'wp_footer', __NAMESPACE__ . '\show_notification_about_latest_site' );
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
	if ( ! $latest_domain ) {
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
	if ( ! $latest_domain ) {
		return;
	}

	echo '<div class="wordcamp-latest-site-notify"><p>' .
		wp_sprintf( '%s is over. Check out <a href="%s">the next edition</a>!', esc_html( get_blog_details( $current_blog->blog_id )->blogname ), esc_url( $latest_domain ) ) .
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
	global $wpdb;

	$wordcamp = get_wordcamp_post();
	$end_date = absint( $wordcamp->meta['End Date (YYYY-mm-dd)'][0] ?? 0 );

	/**
	 * In rare cases, the site for next year's camp will be created before this year's camp is over. When that
	 * happens, we should wait to add the canonical link until after the current year's camp is over.
	 *
	 * This won't prevent the link from being added to past years, but that edge case isn't significant enough
	 * to warrant the extra complexity.
	 *
	 * See also `WordCamp\Sunrise\get_canonical_year_url()`.
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

	} elseif ( preg_match( PATTERN_CITY_YEAR_TYPE_PATH, $current_path, $matches ) ) {
		$city        = $matches[1] ?? '';
		$type        = $matches[3] ?? '';
		$latest_path = "/$city/%%/$type/";

		$query = $wpdb->prepare( "
			SELECT `domain`, `path`
			FROM `$wpdb->blogs`
			WHERE
				`domain` = %s AND
				`path` LIKE %s
			ORDER BY `path` DESC
			LIMIT 1",
			$current_domain,
			$latest_path
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
