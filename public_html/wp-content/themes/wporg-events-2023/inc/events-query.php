<?php

namespace WordPressdotorg\Events_2023;
use WP_Query, WP_Post, WP_Block;
use WordPressdotorg\MU_Plugins\Google_Map;

defined( 'WPINC' ) || die();

// Misc.
add_action( 'init', __NAMESPACE__ . '\register_post_types' );
add_filter( 'posts_pre_query', __NAMESPACE__ . '\inject_events_into_query', 10, 2 );

// Query filters.
add_filter( 'query_vars', __NAMESPACE__ . '\add_query_vars' );
add_action( 'wporg_query_filter_in_form', __NAMESPACE__ . '\inject_other_filters' );
add_filter( 'wporg_query_total_label', __NAMESPACE__ . '\update_query_total_label', 10, 3 );
add_filter( 'wporg_query_filter_options_format_type', __NAMESPACE__ . '\get_format_type_options' );
add_filter( 'wporg_query_filter_options_event_type', __NAMESPACE__ . '\get_event_type_options' );
add_filter( 'wporg_query_filter_options_month', __NAMESPACE__ . '\get_month_options' );
add_filter( 'wporg_query_filter_options_country', __NAMESPACE__ . '\get_country_options' );


/**
 * Register custom post types.
 */
function register_post_types() {
	$args = array(
		'description'       => 'wporg events',
		'public'            => true,
		'show_ui'           => false,
		'show_in_menu'      => false,
		'show_in_nav_menus' => false,
		'supports'          => array( 'title', 'custom-fields' ),
		'has_archive'       => true,
		'show_in_rest'      => true,
	);

	return register_post_type( 'wporg_events', $args );
}

/**
 * Inject rows from the `wporg_events` database table into `WP_Query` SELECT results.
 *
 * This allow us to use blocks like `wp:query`, `wp:post-title`, `wporg/query-filters` etc in templates.
 * Otherwise we'd have to write a custom block to display the data, and wouldn't be ablet to reuse existing
 * blocks.
 */
function inject_events_into_query( $posts, WP_Query $query ) {
	if ( 'wporg_events' !== $query->get( 'post_type' ) ) {
		return $posts;
	}

	global $wp;

	$posts  = array();
	$facets = get_clean_query_facets();
	$events = Google_Map\get_events( 'all-upcoming', 0, 0, $facets );

	// Simulate an ID that won't collide with a real post.
	// It can't be a negative number, because some Core functions pass the ID through `absint()`.
	// It has to be numeric for a similar reason.
	$newest_post = get_posts( array(
		'post_type'      => 'any',
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'orderby'        => 'ID',
		'order'          => 'DESC',
		'fields'         => 'ids',
	) );
	$id_gap = $newest_post[0] + 10000;

	foreach ( $events as $event ) {
		$post = (object) array(
			'ID'             => $id_gap + $event->id,
			'post_title'     => $event->title,
			'post_status'    => 'publish',
			'post_name'      => 'wporg-event-' . $event->id,
			'guid'           => $event->url,
			'post_type'      => 'wporg_event',

			// This makes Core create a new post object, rather than trying to get an instance.
			// See https://github.com/WordPress/WordPress/blob/7926dbb4d5392c870ccbc3ec6019c002feed904c/wp-includes/post.php#L1030-L1033.
			'filter' => 'raw',
		);

		// add time block as a dependency, to make sure always enqueued

		$meta = array(
			'location'  => array( ucfirst( $event->location ) ),
			'timestamp' => array( $event->timestamp ),
			'latitude'  => array( $event->latitude ),
			'longitude' => array( $event->longitude ),
			'meetup'    => array( $event->meetup ),
			'type'      => array( $event->type ),
			'tz_offset' => array( $event->tz_offset ),
		);

		// need a permalink filter that returns the guid? probably

		$post_object = new WP_Post( $post );

		wp_cache_add( $post->ID, $post_object, 'posts' );
		wp_cache_add( $post->ID, $meta, 'post_meta' );

		$posts[] = $post_object;
	}

	if ( $posts ) {
		$query->post              = $posts[0];
		$query->queried_object    = $posts[0];
		$query->queried_object_id = $posts[0]->ID;
	}

	$query->posts                = $posts;
	$query->found_posts          = count( $posts );
	$query->post_count           = count( $posts );
	$query->max_num_pages        = 1;
	$query->is_archive           = true;
	$query->is_post_type_archive = true;

	// Update global `$post` etc.
	$wp->register_globals();

	return $posts;
}

/**
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
 * Change the default "items" label to match the query content.
 */
function update_query_total_label( string $label, int $found_posts, WP_Block $block ): string {
	if ( 'wporg_events' === $block->context['query']['postType'] ) {
		/* translators: %s: the event count. */
		$label = _n( '%s event', '%s events', $found_posts, 'wordcamporg' );
	}

	return $label;
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
