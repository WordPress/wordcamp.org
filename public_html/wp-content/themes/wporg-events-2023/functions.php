<?php

namespace WordPressdotorg\Events_2023;
defined( 'WPINC' ) || die();

require_once __DIR__ . '/inc/city-landing-pages.php';

// Block files
require_once __DIR__ . '/src/event-list/index.php';

add_action( 'after_setup_theme', __NAMESPACE__ . '\theme_support' );
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_assets' );
add_filter( 'wporg_block_navigation_menus', __NAMESPACE__ . '\add_site_navigation_menus' );
add_filter( 'wporg_query_filter_options_event_type', __NAMESPACE__ . '\get_event_type_options' );
add_filter( 'wporg_query_filter_options_format_type', __NAMESPACE__ . '\get_format_type_options' );
add_filter( 'wporg_query_filter_options_month', __NAMESPACE__ . '\get_month_options' );
add_action( 'wporg_query_filter_in_form', __NAMESPACE__ . '\inject_other_filters' );

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
 * Provide a list of local navigation menus.
 */
function add_site_navigation_menus( $menus ) {
	return array(
		'local-navigation' => array(
			array(
				'label' => __( 'All Events', 'wordcamporg' ),
				'url' => '/upcoming/',
			),
			array(
				'label' => __( 'Organize an Event', 'wordcamporg' ),
				'url' => '/organize-events/',
			),
		),
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
	$label    = sprintf(
		/* translators: The dropdown label for filtering, %s is the selected term count. */
		_n( 'Type <span>%s</span>', 'Type <span>%s</span>', $count, 'wporg' ),
		$count
	);

	return array(
		'label' => $label,
		'title' => __( 'Type', 'wporg' ),
		'key' => 'event_type',
		'action' => home_url( '/upcoming-events/' ),
		'options' => array(
			'meetup'   => 'Meetup',
			'wordcamp' => 'WordCamp',
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
	$label    = sprintf(
		/* translators: The dropdown label for filtering, %s is the selected term count. */
		_n( 'Format <span>%s</span>', 'Format <span>%s</span>', $count, 'wporg' ),
		$count
	);

	return array(
		'label' => $label,
		'title' => __( 'Format', 'wporg' ),
		'key' => 'format_type',
		'action' => home_url( '/upcoming-events/' ),
		'options' => array(
			'meetup'   => 'Online',
			'wordcamp' => 'In Person',
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
	$label    = sprintf(
		/* translators: The dropdown label for filtering, %s is the selected term count. */
		_n( 'Month <span>%s</span>', 'Month <span>%s</span>', $count, 'wporg' ),
		$count
	);

	return array(
		'label' => $label,
		'title' => __( 'Month', 'wporg' ),
		'key' => 'month',
		'action' => home_url( '/upcoming-events/' ),
		'options' => array(
			'01'   => 'January',
			'02'   => 'February',
		),
		'selected' => $selected,
	);
}

/**
 * Add in our custom query vars.
 */
function add_query_vars( $query_vars ) {
	$query_vars[] = 'event_type';
	$query_vars[] = 'format_type';
	$query_vars[] = 'month';
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

	$query_vars = array( 'event_type', 'format_type', 'month' );
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
