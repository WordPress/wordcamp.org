<?php

namespace WordPressdotorg\Events_2023;

defined( 'WPINC' ) || die();

add_filter( 'query_vars', __NAMESPACE__ . '\add_query_vars' );
add_action( 'wporg_query_filter_in_form', __NAMESPACE__ . '\inject_other_filters' );
add_filter( 'wporg_query_filter_options_format_type', __NAMESPACE__ . '\get_format_type_options' );
add_filter( 'wporg_query_filter_options_event_type', __NAMESPACE__ . '\get_event_type_options' );
add_filter( 'wporg_query_filter_options_month', __NAMESPACE__ . '\get_month_options' );
add_filter( 'wporg_query_filter_options_country', __NAMESPACE__ . '\get_country_options' );


+/**
 * Get the query var facts and sanitize them.
 *
 * The query-filters block will provide the values as strings in some cases, but arrays in others.
 *
 * This converts them to the keys that the Google Map block uses.
 */
function get_clean_query_facets(): array {
	$search  = (array) get_query_var( 's' ) ?? array();
	$search  = sanitize_text_field( $search[0] ?? '' );

	$type    = (array) get_query_var( 'event_type' ) ?? array();
	$type    = sanitize_text_field( $type[0] ?? '' );

	$format  = (array) get_query_var( 'format_type' ) ?? array();
	$format  = sanitize_text_field( $format[0] ?? '' );

	$month   = (array) get_query_var( 'month' ) ?? array();
	$month   = absint( $month[0] ?? 0 );

	$country = (array) get_query_var( 'country' ) ?? array();
	$country = sanitize_text_field( $country[0] ?? '' );

	$facets = compact( 'search', 'type', 'format', 'month', 'country' );
	$facets = array_filter( $facets ); // Remove empty.

	return $facets;
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
