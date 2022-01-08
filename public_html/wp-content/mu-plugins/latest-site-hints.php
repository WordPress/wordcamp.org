<?php

namespace WordCamp\Latest_Site_Hints;
use function WordCamp\Sunrise\get_top_level_domain;

use const WordCamp\Sunrise\{ PATTERN_YEAR_DOT_CITY_DOMAIN_PATH, PATTERN_CITY_SLASH_YEAR_DOMAIN_PATH };

defined( 'WPINC' ) || die();

add_action( 'wp', __NAMESPACE__ . '\maybe_add_latest_site_hints' );

/**
 * If user or bot visits WordCamp site that has newer site for the same city,
 * add some hints for guiding them visit the latest site.
 */
function maybe_add_latest_site_hints() {
	// Check if there is newer site for the WordCamp.
	$latest_site = get_latest_site();
	if ( ! $latest_site ) {
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
		add_action( 'wp_body_open', __NAMESPACE__ . '\show_notification_about_latest_site' );
	}
}

/**
 * Add a `<link rel="canonical" ...` tag to the front page of past WordCamps, which points to the current year.
 *
 * This helps search engines know to direct queries for "WordCamp Seattle" to `seattle.wordcamp.org/2020`
 * instead of `seattle.wordcamp.org/2019`, even if `/2019` has a higher historic rank.
 */
function canonical_link_past_home_pages_to_current_year() {
	// We don't want to penalize historical content, we just want to boost the new site.
	if ( ! is_front_page() ) {
		return;
	}

	$latest_site = get_latest_site();
	if ( ! $latest_site ) {
		return;
	}

	// Remove default canonical link, to avoid duplicates.
	// @todo: This will need to be updated if rel_canonical_link() is ever merged to Core.
	remove_action( 'wp_head', 'WordPressdotorg\SEO\Canonical\rel_canonical_link' );

	printf(
		'<link rel="canonical" href="%s" />' . "\n",
		esc_url( set_url_scheme( trailingslashit( '//' . $latest_site->domain . $latest_site->path ) ) )
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

	$latest_site = get_latest_site();
	if ( ! $latest_site ) {
		return;
	}

	echo '<div id="wpadminbar" class="wordcamp-latest-site-notify">
		<p>' . esc_html( get_blog_details( $current_blog->blog_id )->blogname ) . ' is over. Check out <a href="' . esc_url( get_site_url( $latest_site->blog_id ) ) . '">' . esc_html( get_blog_details( $latest_site->blog_id )->blogname ) . '</a>!</p>
	</div>';
}

/**
 * Get the latest site WP_Site object.
 *
 * @return bool|object
 */
function get_latest_site() {
	global $current_blog;

	$latest_site_id = get_latest_site_id( $current_blog->domain, $current_blog->path );

	if ( ! $latest_site_id ) {
		return false;
	}

	$latest_site = get_site( $latest_site_id );

	if ( $latest_site->domain === $current_blog->domain ) {
		return false;
	}

	return $latest_site;
}

/**
 * Get the site id most recent camp in a given city.
 *
 * @param string $current_domain
 * @param string $current_path
 *
 * @return bool|integer
 */
function get_latest_site_id( $current_domain, $current_path ) {
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
			SELECT `blog_id`
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
			SELECT `blog_id`
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

	return $latest_site[0]->blog_id;
}
