<?php

namespace WordCamp\Latest_Site_Hints;
use function WordCamp\Sunrise\get_top_level_domain;
use const WordCamp\Sunrise\{ PATTERN_YEAR_DOT_CITY_DOMAIN_PATH, PATTERN_CITY_SLASH_YEAR_DOMAIN_PATH };

defined( 'WPINC' ) || die();

if ( EVENTS_NETWORK_ID === SITE_ID_CURRENT_SITE ) {
	// @todo Remove this once https://github.com/WordPress/wordcamp.org/issues/906 is fixed.
	// If it's needed on the Events network, the constants above will need to be moved to `sunrise.php`, or
	// defined in `sunrise-events.php` with a pattern designed for Events sites.
	return;
}

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

	// Hook in before `WordPressdotorg\SEO\Canonical::rel_canonical_link()`, so that callback can be removed.
	add_action( 'wp_head', __NAMESPACE__ . '\canonical_link_past_home_pages_to_current_year', 9 );

	/**
	 * Show notification about newer WordCamp. Logged in users most probably know that already,
	 * so no need to bother them (and we also misuse the body.admin-bar class).
	 */
	if ( ! is_user_logged_in() ) {
		add_filter( 'body_class',   __NAMESPACE__ . '\add_notification_classes_to_body' );
		add_action( 'wp_head',      __NAMESPACE__ . '\add_notification_styles' );
		add_action( 'wp_footer', __NAMESPACE__ . '\show_notification_about_latest_site' );
	}
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
 * If notification is shown, misuse body.admin-bar class as most of the WordCamp themes already that that into
 * account. This makes its a whole lot easier to place the notification on top of contens.
 */
function add_notification_classes_to_body( $classes ) {
	$classes[] = 'admin-bar'; // Add this as most of the current WordCamp themes already take the adminbar into account.
	return $classes;
}

/**
 * Simple styles for the notification.
 */
function add_notification_styles() { ?>
  <style type="text/css">
		html {
		  margin-top: 35px !important;
		}

		.wordcamp-latest-site-notify {
		  background: #1d2327;
		  text-align: center;
		  padding-top: 5px;
		  font-size: 15px;
		  height: 35px;
		  position: fixed;
		  top: 0;
		  left: 0;
		  width: 100%;
		  min-width: 600px;
		  z-index: 99999;
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

	echo '<div id="wpadminbar" class="wordcamp-latest-site-notify"><p>' .
		wp_sprintf( '%s is over. Check out <a href="%s">the next edition</a>!', esc_html( get_blog_details( $current_blog->blog_id )->blogname ), esc_url( $latest_domain ) ) .
	'</p></div>';
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
	} else {
		return false;
	}

  $latest_site = $wpdb->get_results( $query ); // phpcs:ignore -- Prepared above.

	if ( ! $latest_site ) {
		return false;
	}

	return set_url_scheme( trailingslashit( '//' . $latest_site[0]->domain . $latest_site[0]->path ) );
}
