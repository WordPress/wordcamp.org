<?php

namespace WordCamp\Latest_Site_Hints;
use function WordCamp\Sunrise\get_flagship_canonical_url;
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

	/*
	 * Add a banner linking to the latest WordCamp. It prints in the normal document flow at
	 * `wp_body_open`; the `wp_footer` hook is a fallback (a bottom-anchored bar) for the request
	 * where `wp_body_open` never fires.
	 */
	add_action( 'wp_head', __NAMESPACE__ . '\add_notification_styles' );
	add_action( 'wp_body_open', __NAMESPACE__ . '\show_notification_in_flow' );
	add_action( 'wp_footer', __NAMESPACE__ . '\show_notification_overlay' );

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
 * Print the notification's styles in the document `<head>`.
 *
 * One stylesheet covers both variants of the banner:
 *
 * - In-flow (default): printed at `wp_body_open`, it sits in the normal document flow as a sticky
 *   bar at the top of the page, so layout reserves exactly as much room as the text needs however
 *   many lines it wraps to.
 * - Overlay (fallback): printed at `wp_footer` when `wp_body_open` never fired. It can't join the
 *   already-painted flow without shifting content, so it's anchored to the bottom of the viewport
 *   instead — `position: fixed` reserves no space and causes no layout shift. The doubled class
 *   keeps the modifier ahead of the base `position` rules wherever in the document it lands.
 */
function add_notification_styles() { ?>
  <style type="text/css">
		.wordcamp-latest-site-notify {
			box-sizing: border-box;
			background: #1d2327;
			text-align: center;
			padding: 10px 20px;
			font-size: 16px;
			line-height: 1.5;
			position: sticky;
			top: var(--wp-admin--admin-bar--height, 0);
			width: 100%;
			z-index: 99998;
		}

		@media screen and (max-width: 600px) {
			.wordcamp-latest-site-notify {
				/* Scroll away with the page instead of permanently taking up small-screen space. */
				position: static;
			}
		}

		.wordcamp-latest-site-notify.wordcamp-latest-site-notify--overlay {
			position: fixed;
			top: auto;
			bottom: 0;
			left: 0;
			right: 0;
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
 * Print the banner in the normal document flow at `wp_body_open`.
 */
function show_notification_in_flow() {
	show_notification_about_latest_site( false );
}

/**
 * Print the banner as a bottom-anchored overlay at `wp_footer`.
 *
 * This is the fallback for requests where `wp_body_open` never fired, like a theme that doesn't
 * call `wp_body_open()` or the offline/500 template. It self-suppresses when the in-flow banner
 * has already printed this request.
 */
function show_notification_overlay() {
	show_notification_about_latest_site( true );
}

/**
 * Show a notification linking to the latest site for this city.
 *
 * Prints at most once per request, guarded by a static flag: in the normal document flow when the
 * theme fired `wp_body_open`, otherwise as a bottom-anchored overlay at `wp_footer`. The static
 * guard also means a theme that fires `wp_body_open` more than once still yields a single banner.
 *
 * @param bool $is_overlay Whether to render the bottom-anchored `wp_footer` fallback variant rather
 *                         than the in-flow banner.
 */
function show_notification_about_latest_site( $is_overlay = false ) {
	global $current_blog;
	static $printed = false;

	if ( $printed ) {
		return;
	}

	$latest_domain = get_latest_home_url( $current_blog->domain, $current_blog->path );

	// Check if there is newer site for the WordCamp.
	if ( ! $latest_domain || $latest_domain === $current_blog->domain ) {
		return;
	}

	$printed = true;

	echo '<div class="wordcamp-latest-site-notify' . ( $is_overlay ? ' wordcamp-latest-site-notify--overlay' : '' ) . '"><p>' .
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

	/*
	 * Flagship camps create next year's site (and sometimes the one after) before the current edition is
	 * over, so the query below would otherwise link to an event that hasn't happened yet. Until then, stay
	 * on the current edition. The shared list of dates lives with the redirect logic in sunrise.
	 */
	$flagship_url = get_flagship_canonical_url( $current_domain );

	if ( $flagship_url ) {
		return $flagship_url;
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
