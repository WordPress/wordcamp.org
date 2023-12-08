<?php

namespace WordPressdotorg\Events_2023;
defined( 'WPINC' ) || die();

require_once __DIR__ . '/inc/city-landing-pages.php';

// Block files.
require_once __DIR__ . '/src/event-list/index.php';

add_action( 'after_setup_theme', __NAMESPACE__ . '\theme_support' );
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_assets' );
add_action( 'wp_head', __NAMESPACE__ . '\add_social_meta_tags' );
add_filter( 'wporg_block_navigation_menus', __NAMESPACE__ . '\add_site_navigation_menus' );
add_filter( 'wporg_query_filter_options_format_type', __NAMESPACE__ . '\get_format_type_options' );
add_filter( 'wporg_query_filter_options_event_type', __NAMESPACE__ . '\get_event_type_options' );
add_filter( 'wporg_query_filter_options_month', __NAMESPACE__ . '\get_month_options' );
add_filter( 'wporg_query_filter_options_country', __NAMESPACE__ . '\get_country_options' );
add_action( 'wporg_query_filter_in_form', __NAMESPACE__ . '\inject_other_filters' );
add_filter( 'wporg_block_site_breadcrumbs', __NAMESPACE__ . '\update_site_breadcrumbs' );

add_filter( 'query_vars', __NAMESPACE__ . '\add_query_vars' );

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
		'wporg-events-2023-style',
		get_stylesheet_uri(),
		array( 'wporg-parent-2021-style', 'wporg-global-fonts' ),
		filemtime( __DIR__ . '/style.css' )
	);
}

/**
 * Add meta tags for richer social media integrations.
 */
function add_social_meta_tags() {
	$default_image = get_stylesheet_directory_uri() . '/images/social-image.png';
	$site_title    = function_exists( '\WordPressdotorg\site_brand' ) ? \WordPressdotorg\site_brand() : 'WordPress.org';
	$og_fields = array(
		'og:title'       => wp_get_document_title(),
		'og:description' => __( '[Replace with copy]', 'wporg' ),
		'og:site_name'   => $site_title,
		'og:type'        => 'website',
		'og:url'         => home_url( '/' ),
		'og:image'       => esc_url( $default_image ),
	);

	if ( is_tag() || is_category() ) {
		$og_fields['og:url'] = esc_url( get_term_link( get_queried_object_id() ) );
	} elseif ( is_single() ) {
		$og_fields['og:description'] = strip_tags( get_the_excerpt() );
		$og_fields['og:url']         = esc_url( get_permalink() );
		$og_fields['og:image']       = esc_url( get_site_screenshot_src( get_post() ) );
	}

	printf( '<meta name="twitter:card" content="summary_large_image">' . "\n" );
	printf( '<meta name="twitter:site" content="@WordPress">' . "\n" );
	printf( '<meta name="twitter:image" content="%s" />' . "\n", esc_url( $og_fields['og:image'] ) );

	foreach ( $og_fields as $property => $content ) {
		printf(
			'<meta property="%1$s" content="%2$s" />' . "\n",
			esc_attr( $property ),
			esc_attr( $content )
		);
	}

	if ( isset( $og_fields['og:description'] ) ) {
		printf(
			'<meta name="description" content="%1$s" />' . "\n",
			esc_attr( $og_fields['og:description'] )
		);
	}
}

/**
 * Provide a list of local navigation menus.
 */
function add_site_navigation_menus( $menus ) {
	return array(
		'local-navigation' => array(
			array(
				'label' => __( 'Upcoming events', 'wordcamporg' ),
				'url' => '/upcoming-events/',
			),
			array(
				'label' => __( 'Organize an event', 'wordcamporg' ),
				'url' => '/organize-events/',
			),
		),
	);
}

/**
 * Sets up our Query filter for country.
 *
 * @return array
 */
function get_country_options( array $options ): array {
	global $wp_query;
	$selected = isset( $wp_query->query['country'] ) ? (array) $wp_query->query['country'] : array();
	$count    = count( $selected );

	$countries = wcorg_get_countries();

	// Re-index to match the format expected by the query-filters block.
	$countries = array_combine( array_keys( $countries ), array_column( $countries, 'name' ) );

	$label = __( 'Country', 'wporg' );
	if ( $count > 0 ) {
		$label = sprintf(
			/* translators: The dropdown label for filtering, %s is the selected term count. */
			_n( 'Country <span>%s</span>', 'Country <span>%s</span>', $count, 'wporg' ),
			$count
		);
	}

	return array(
		'label' => $label,
		'title' => __( 'Country', 'wporg' ),
		'key' => 'country',
		'action' => is_search() ? '' : home_url( '/upcoming-events/' ),
		'options' => $countries,
		'selected' => $selected,
	);
}

/**
 * Sets up our Query filter for event_type.
 *
 * @return array
 */
function get_event_type_options( array $options ): array {
	global $wp_query;
	$selected = isset( $wp_query->query['event_type'] ) ? (array) $wp_query->query['event_type'] : array();
	$count    = count( $selected );

	$label = __( 'Type', 'wporg' );
	if ( $count > 0 ) {
		$label = sprintf(
			/* translators: The dropdown label for filtering, %s is the selected term count. */
			_n( 'Type <span>%s</span>', 'Type <span>%s</span>', $count, 'wporg' ),
			$count
		);
	}

	return array(
		'label' => $label,
		'title' => __( 'Type', 'wporg' ),
		'key' => 'event_type',
		'action' => is_search() ? '' : home_url( '/upcoming-events/' ),
		'options' => array(
			'meetup'   => 'Meetup',
			'wordcamp' => 'WordCamp',
			'other'    => 'Other',
		),
		'selected' => $selected,
	);
}

/**
 * Sets up our Query filter for format_type.
 *
 * @return array
 */
function get_format_type_options( array $options ): array {
	global $wp_query;
	$selected = isset( $wp_query->query['format_type'] ) ? (array) $wp_query->query['format_type'] : array();
	$count    = count( $selected );

	$label = __( 'Format', 'wporg' );
	if ( $count > 0 ) {
		$label = sprintf(
			/* translators: The dropdown label for filtering, %s is the selected term count. */
			_n( 'Format <span>%s</span>', 'Format <span>%s</span>', $count, 'wporg' ),
			$count
		);
	}

	return array(
		'label' => $label,
		'title' => __( 'Format', 'wporg' ),
		'key' => 'format_type',
		'action' => is_search() ? '' : home_url( '/upcoming-events/' ),
		'options' => array(
			'online'    => 'Online',
			'in-person' => 'In Person',
		),
		'selected' => $selected,
	);
}

/**
 * Sets up our Query filter for month.
 *
 * @return array
 */
function get_month_options( array $options ): array {
	global $wp_query;
	$selected = isset( $wp_query->query['month'] ) ? (array) $wp_query->query['month'] : array();
	$count    = count( $selected );

	$label = __( 'Month', 'wporg' );
	if ( $count > 0 ) {
		$label = sprintf(
			/* translators: The dropdown label for filtering, %s is the selected term count. */
			_n( 'Month <span>%s</span>', 'Month <span>%s</span>', $count, 'wporg' ),
			$count
		);
	}

	$months = array();

	for ( $i = 1; $i <= 12; $i++ ) {
		$month = strtotime( "2023-$i-1" );
		$months[ gmdate( 'm', $month ) ] = gmdate( 'F', $month );
	}

	return array(
		'label' => $label,
		'title' => __( 'Month', 'wporg' ),
		'key' => 'month',
		'action' => is_search() ? '' : home_url( '/upcoming-events/' ),
		'options' => $months,
		'selected' => $selected,
	);
}

/**
 * Add in our custom query vars.
 */
function add_query_vars( $query_vars ) {
	$query_vars[] = 'format_type';
	$query_vars[] = 'event_type';
	$query_vars[] = 'month';
	$query_vars[] = 'country';

	return $query_vars;
}


/**
 * Add in the other existing filters as hidden inputs in the filter form.
 *
 * Enables combining filters by building up the correct URL on submit,
 * for example sites using a tag, a category, and matching a search term:
 *   ?tag[]=cuisine&cat[]=3&s=wordpress`
 *
 * @param string $key The key for the current filter.
 */
function inject_other_filters( $key ) {
	global $wp_query;

	$query_vars = array( 'event_type', 'format_type', 'month', 'country' );

	foreach ( $query_vars as $query_var ) {
		if ( ! isset( $wp_query->query[ $query_var ] ) ) {
			continue;
		}

		if ( $key === $query_var ) {
			continue;
		}

		$values = (array) $wp_query->query[ $query_var ];

		foreach ( $values as $value ) {
			printf( '<input type="hidden" name="%s[]" value="%s" />', esc_attr( $query_var ), esc_attr( $value ) );
		}
	}

	// Pass through search query.
	if ( isset( $wp_query->query['s'] ) ) {
		printf( '<input type="hidden" name="s" value="%s" />', esc_attr( $wp_query->query['s'] ) );
	}
}

/**
 * Update the breadcrumbs to the current page.
 */
function update_site_breadcrumbs( $breadcrumbs ) {
	// Build up the breadcrumbs from scratch.
	$breadcrumbs = array(
		array(
			'url' => home_url(),
			'title' => __( 'Home', 'wporg' ),
		),
	);

	if ( is_search() ) {
		$breadcrumbs[] = array(
			'url' => false,
			'title' => __( 'Search results', 'wporg' ),
		);
		return $breadcrumbs;
	}

	return $breadcrumbs;
}
