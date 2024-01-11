<?php

namespace WordPressdotorg\Flagship_Landing;
use WordCamp\Sunrise, WordCamp_Loader;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/src/timeline/index.php';

add_action( 'after_setup_theme', __NAMESPACE__ . '\theme_support' );
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_assets' );
add_filter( 'wporg_block_navigation_menus', __NAMESPACE__ . '\add_site_navigation_menus' );
add_filter( 'pre_option_blogname', __NAMESPACE__ . '\set_site_title', 10, 2 );
add_filter( 'pre_option_blogdescription', __NAMESPACE__ . '\set_site_tagline', 10, 2 );

add_filter( 'jetpack_open_graph_image_default', __NAMESPACE__ . '\filter_social_media_image' );
add_filter( 'jetpack_open_graph_tags', __NAMESPACE__ . '\add_meta_description' );
add_filter( 'jetpack_open_graph_output', __NAMESPACE__ . '\add_generic_meta_description' );


/**
 * Register theme supports.
 */
function theme_support() {
	add_editor_style( 'editor.css' );
}

/**
 * Enqueue scripts and styles.
 */
function enqueue_assets() {
	wp_enqueue_style(
		'wporg-flagship-landing-style',
		get_stylesheet_uri(),
		array( 'wporg-parent-2021-style', 'wporg-global-fonts' ),
		filemtime( __DIR__ . '/style.css' )
	);
}

/**
 * Provide a list of local navigation menus.
 */
function add_site_navigation_menus( $menus ) {
	$contact_page = get_page_by_path( 'contact' );
	$wordcamp     = get_wordcamp_post();
	$hashtag      = ltrim( $wordcamp->meta['WordCamp Hashtag'][0], '#' ); // It'll break the URL.

	$header_menu = array(
		array(
			'label' => __( 'Contact', 'wordcamporg' ),
			'url'   => $contact_page ? get_permalink( $contact_page ) : 'mailto:' . get_bloginfo( 'admin_email' ),
		),

		array(
			'label' => __( 'WordCamp Central', 'wordcamporg' ),
			'url' => 'https://central.wordcamp.org/',
		),
	);

	if ( $hashtag ) {
		$header_menu[] = array(
			'label' => '#' . $hashtag,
			'url'   => 'https://twitter.com/hashtag/' . $hashtag,
		);
	}

	return array( 'header' => $header_menu );
}

/**
 * Set the year-less title for the series of flagship events.
 */
function set_site_title( string $output, string $show ): string {
	// Don't modify the title when it's being saved on the Settings page, etc.
	if ( ! is_front_page() ) {
		return $output;
	}

	$wordcamp = get_wordcamp_post();

	if ( ! $wordcamp ) {
		return $output;
	}

	$name = $wordcamp->post_title;
	$year = get_wordcamp_year( $wordcamp );

	$output = trim( str_replace( $year, '', $name ) );

	return $output;
}

/**
 * Get the year that the given WordCamp happenend in.
 */
function get_wordcamp_year( object $wordcamp ): int {
	$start_date = (int) $wordcamp->meta['Start Date (YYYY-mm-dd)'][0] ?? false;

	if ( $start_date ) {
		$year = gmdate( 'Y', $start_date );

	} else {
		$parsed_url = wp_parse_url( $wordcamp->meta['URL'][0] );

		preg_match(
			Sunrise\PATTERN_CITY_SLASH_YEAR_DOMAIN_PATH,
			$parsed_url['host'] . $parsed_url['path'],
			$matches
		);

		$year = preg_replace( '/[^0-9]/', '', $matches[4] );

	}

	return $year;
}

/**
 * Set the "Established" tagline for the series of flagship events.
 */
function set_site_tagline( string $output, string $show ): string {
	// Don't modify the description when it's being saved on the Settings page, etc.
	if ( ! is_front_page() ) {
		return $output;
	}

	$events     = get_flagship_events();
	$first_year = PHP_INT_MAX;

	foreach ( $events as $event ) {
		$event_year = get_wordcamp_year( $event );

		if ( ! $event_year ) {
			continue;
		}

		if ( $event_year < $first_year ) {
			$first_year = $event_year;
		}
	}

	if ( PHP_INT_MAX !== $first_year ) {
		$output = 'Est. ' . $first_year;
	}

	return $output;
}

/**
 * Get all of the events for the current flagship site.
 */
function get_flagship_events(): array {
	global $domain;

	$third_level_domain = explode( '.', $domain )[0];

	require_once WP_PLUGIN_DIR . '/wcpt/wcpt-event/class-event-loader.php';
	require_once WP_PLUGIN_DIR . '/wcpt/wcpt-wordcamp/wordcamp-loader.php';

	switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
	$events = get_wordcamps( array(
		'post_status' => 'any', // See note below.

		'meta_query' => array(
			array(
				'key'     => 'URL',
				'value'   => "^https?://$third_level_domain\.",
				'compare' => 'REGEXP',
			),
		),
	) );
	restore_current_blog();

	// The statuses that we want aren't registered since we're not on Central and `switch_to_blog()` doesn't load
	// plugins. That means the query above will ignore the `post_status` argument, so we need to manually throw
	// out the ones we don't want.
	$desired_statuses = array_merge(
		WordCamp_Loader::get_public_post_statuses(),
		array( 'wcpt-cancelled' )
	);

	$events = array_filter(
		$events,
		function ( $event ) use ( $desired_statuses ) {
			return in_array( $event->post_status, $desired_statuses, true );
		}
	);

	// The default post ID sorting usually matches the year, but some early camps were backfilled
	// after the `wordcamp` post type was created, so their IDs are out of order.
	usort(
		$events,
		function ( $wordcamp_a, $wordcamp_b ) {
			$year_a = get_wordcamp_year( $wordcamp_a );
			$year_b = get_wordcamp_year( $wordcamp_b );

			return $year_a <=> $year_b;
		}
	);

	return $events;
}

/**
 * Replace the default social image.
 */
function filter_social_media_image() {
	return get_stylesheet_directory_uri() . '/images/social-image.png';
	// @todo add this once design is finalized
}

/**
 * Add the og:description via JetPack meta tags so it can be translated.
 */
function add_meta_description( $tags ) {
	$tags['og:description'] = __( 'Find upcoming WordPress events near you and around the world. Join your local WordPress community at a meetup or WordCamp, or learn how to organize an event for your city.', 'wporg' );
	return $tags;
}

/**
 * Use the og:description tag and output an additional meta description for SEO.
 *
 * Jetpack uses property="thing" as template for its Meta tags,
 * because it's built to output OG Tags. However, we we want to add a general tag here.
 *
 * @filter jetpack_open_graph_output
 * @uses str_replace
 */
function add_generic_meta_description( $og_tag ) {
	if ( false !== strpos( $og_tag, 'property="og:description"' ) ) {
		// Replace property="og:description" by name="description".
		$og_tag .= str_replace( 'property="og:description"', 'name="description"', $og_tag );
	}

	return $og_tag;
}
