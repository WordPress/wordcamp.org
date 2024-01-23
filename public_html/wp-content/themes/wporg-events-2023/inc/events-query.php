<?php

namespace WordPressdotorg\Events_2023;
use WP, WP_Query, WP_Post, WP_Block;
use WordPressdotorg\MU_Plugins\Google_Map;

defined( 'WPINC' ) || die();

// Match URLs like `{pagename}/filtered/{facets}`, e.g., `/upcoming-events/filtered/type/meetup/format/in-person/month/05/country/US/`.
// This intentionally doesn't have the starting/ending delimiters and flags, so that it can be used with
// `add_rewrite_rule()`.
const FILTERED_URL_PATTERN       = '([\w-]+)/filtered/(.+)';
const PRETTY_URL_VALUE_DELIMITER = '-';

// Misc.
add_action( 'init', __NAMESPACE__ . '\register_post_types' );
add_filter( 'posts_pre_query', __NAMESPACE__ . '\inject_events_into_query', 10, 2 );

// Query filters.
add_action( 'init', __NAMESPACE__ . '\add_rewrite_rules' );
add_filter( 'query_vars', __NAMESPACE__ . '\add_query_vars' );
add_action( 'parse_request', __NAMESPACE__ . '\set_query_vars_from_pretty_url' );
add_action( 'wp', __NAMESPACE__ . '\redirect_to_pretty_query_vars' );
add_action( 'wporg_query_filter_in_form', __NAMESPACE__ . '\inject_other_filters' );
add_filter( 'document_title_parts', __NAMESPACE__ . '\add_filters_to_page_title' );
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
 * Otherwise we'd have to write a custom block to display the data, and wouldn't be able to reuse existing
 * blocks.
 */
function inject_events_into_query( $posts, WP_Query $query ) {
	if ( 'wporg_events' !== $query->get( 'post_type' ) ) {
		return $posts;
	}

	global $wp;

	$posts  = array();
	$facets = get_query_var_facets();
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

		$meta = array(
			'location'  => array( ucfirst( $event->location ) ),
			'timestamp' => array( $event->timestamp ),
			'latitude'  => array( $event->latitude ),
			'longitude' => array( $event->longitude ),
			'meetup'    => array( $event->meetup ),
			'type'      => array( $event->type ),
			'tz_offset' => array( $event->tz_offset ),
		);

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
 * Get facets from the query vars.
 *
 * The query-filters block will provide the values as strings in some cases, but arrays in others.
 *
 * This converts them to the keys that the Google Map block uses. The map block will sanitize/validate them.
 */
function get_query_var_facets(): array {
	global $wp;

	// This needs to be retrieved from `$wp->query_vars`, not `$wp_query->query_vars`. Otherwise the previously
	// applied facet will get wiped out when a new request is submitted with an additional facet.
	$pretty_facets = $wp->query_vars['event_facets'] ?? '';

	// The query-filters form submission has key-value pairs in the URL, but then that request is redirected to a
	// "pretty" URL. This function needs to handle both types of requests.
	// @see redirect_to_pretty_query_vars().
	if ( $pretty_facets ) {
		preg_match_all( '#([\w\-]+/[\w\-,]+)#', $pretty_facets, $matches );

		foreach ( (array) $matches[0] as $match ) {
			$parts      = explode( '/', $match );
			$var_key    = $parts[0];

			// We need a delimiter to separate the values, but Google discourages using commas, colons, or
			// anything else in URLs, so we're use a dash like they want. We also need a dash in some of the
			// values themselves, like `in-person`. Luckily `in-person` is the only value that needs one at
			// the moment, so the easiest thing is to just make an exception.
			// @link https://developers.google.com/search/blog/2014/02/faceted-navigation-best-and-5-of-worst#worst-practice-1:-non-standard-url-encoding-for-parameters,-like-commas-or-brackets,-instead-of-key=value-pairs.
			if ( 'in-person' === $parts[1] ) {
				$var_values = array( 'in-person' );
			} else {
				$var_values = explode( PRETTY_URL_VALUE_DELIMITER, $parts[1] );
			}

			$facets[ $var_key ] = $var_values;
		}

		$facets['search'] = get_query_var( 's', '' );

	} else {
		$facets = array(
			'search'  => (string) get_query_var( 's', '' ),
			'type'    => (array) get_query_var( 'event_type', array() ),
			'format'  => (array) get_query_var( 'format_type', array() ),
			'month'   => (array) get_query_var( 'month', array() ),
			'country' => (array) get_query_var( 'country', array() ),
		);
	}

	$facets = array_filter( $facets ); // Remove empty values.

	return $facets;
}

/**
 * Register rewrite rules.
 */
function add_rewrite_rules(): void {
	// The regex can't explicitly match each facet because they're all optional, so the `$matches` indices aren't
	// predictable. Instead, this just matches all the facets into a single var, and they'll be parsed out of that
	// into individual facets later.
	// @see set_query_vars_from_pretty_url().
	add_rewrite_rule( FILTERED_URL_PATTERN, 'index.php?pagename=$matches[1]&event_facets=$matches[2]', 'top' );
}

/**
 * Add in our custom query vars.
 */
function add_query_vars( array $query_vars ): array {
	// This holds the combined facets.
	// @see `add_rewrite_rules()`.
	$query_vars[] = 'event_facets';

	// These are the individual query vars that will be populated from `event_facets`.
	// @see `set_query_vars_from_pretty_url()`.
	$query_vars[] = 'format_type';
	$query_vars[] = 'event_type';
	$query_vars[] = 'month';
	$query_vars[] = 'country';

	return $query_vars;
}

/**
 * Set the individual query vars from the combined `event_facets` query var.
 *
 * @see add_rewrite_rules()
 * @see add_query_vars()
 */
function set_query_vars_from_pretty_url( WP $wp ): void {
	$facets = get_query_var_facets();

	foreach ( $facets as $key => $value ) {
		if ( 'format' === $key ) {
			$key = 'format_type';
		}

		if ( 'type' === $key ) {
			$key = 'event_type';
		}

		// Set it on `WP` because this is the main request. `WP_Query` will populate itself from this.
		$wp->set_query_var( $key, $value );
	}
}

/**
 * Redirect URLs with facet query vars to the pretty version of the URL.
 *
 * The `query-filter` block sets the query vars as <input> fields, so the browser creates an key-value pair in the
 * URL, like `/upcoming-events/?month%5B%5D=02&month%5B%5D=03&event_type%5B%5D=wordcamp&event_type%5B%5D=other&format_type%5B%5D=in-person&country%5B%5D=US`.
 * This converts that to a "pretty" URL, like `/upcoming-events/filtered/type/wordcamp-other/format/in-person/month/02-03/country/US/`.
 */
function redirect_to_pretty_query_vars(): void {
	global $wp;

	if ( preg_match( '#' . FILTERED_URL_PATTERN . '#', $wp->request ) ) {
		return;
	}

	if ( is_search() ) {
		return;
	}

	$facets = get_query_var_facets();

	if ( empty( $facets ) ) {
		return;
	}

	// Ensure a consistent order for the facets and their values, so that URLs build from this array are consistent.
	// @link https://developers.google.com/search/blog/2014/02/faceted-navigation-best-and-5-of-worst#existing-sites.
	$facets = sort_facets( $facets );
	$url    = get_permalink() . 'filtered/';

	foreach ( $facets as $key => $values ) {
		$values = implode( PRETTY_URL_VALUE_DELIMITER, (array) $values );
		$url    .= trailingslashit( $key . '/' . $values );
	}

	wp_safe_redirect( trailingslashit( $url ) );
	exit;
}

/**
 * Sort the facets and their values alphabetically, to ensure a consistent order.
 */
function sort_facets( array $facets ): array {
	ksort( $facets );

	array_walk(
		$facets,
		function ( &$facet ) {
			sort( $facet );
		}
	);

	return $facets;
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
function inject_other_filters( string $key ): void {
	global $wp_query;

	$query_vars = array( 'event_type', 'format_type', 'month', 'country' );

	foreach ( $query_vars as $query_var ) {
		if ( $key === $query_var ) {
			continue;
		}

		if ( ! get_query_var( $query_var ) ) {
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
 * Append facets to the page title.
 *
 * @param array $parts {
 *     The document title parts.
 *
 *     @type string $title   Title of the viewed page.
 *     @type string $page    Optional. Page number if paginated.
 *     @type string $tagline Optional. Site description when on home page.
 *     @type string $site    Optional. Site title when not on home page.
 * }
 */
function add_filters_to_page_title( array $parts ): array {
	$facets = get_query_var_facets();

	// Search titles are handles by Core, and already include the query.
	unset( $facets['search'] );

	$facets      = array_filter( $facets ); // Remove empty.
	$facets      = sort_facets( $facets );
	$extra_terms = array();

	foreach ( $facets as $facet => $values ) {
		$values = (array) $values;

		switch ( $facet ) {
			case 'type':
			case 'format':
				$values = array_map(
					function ( $name ) {
						if ( 'wordcamp' === $name ) {
							return 'WordCamp';
						} else {
							return ucwords( $name );
						}
					},
					$values
				);

				break;

			case 'month':
				$values = array_map(
					function ( $month_number ) {
						return gmdate( 'F', strtotime( "2024-$month_number-01" ) );
					},
					$values
				);

				break;

			case 'country':
				$countries = wcorg_get_countries();

				$values = array_filter(
					$values,
					function ( $country_code ) use ( $countries ) {
						return isset( $countries[ $country_code ] );
					}
				);

				$values = array_map(
					function ( $country_code ) use ( $countries ) {
						return $countries[ $country_code ]['name'];
					},
					$values
				);

				break;
		}

		$extra_terms = array_merge( $extra_terms, $values );
	}

	if ( $extra_terms ) {
		$parts['title'] .= ' filtered by: ' . implode( ', ', $extra_terms );
	}

	return $parts;
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
 * Build the `action` attribute for the `query-filters` form.
 */
function build_form_action_url(): string {
	if ( is_search() ) {
		$url = home_url();
	} elseif ( is_front_page() ) {
		$url = home_url( 'upcoming-events' );
	} else {
		$url = get_permalink();
	}

	return $url;
}

/**
 * Sets up our Query filter for format_type.
 *
 * @return array
 */
function get_format_type_options( array $options ): array {
	$facets   = get_query_var_facets();
	$selected = $facets['format'] ?? array();
	$count    = count( $selected );
	$label    = __( 'Format', 'wporg' );

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
		'action' => build_form_action_url(),
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
	$facets   = get_query_var_facets();
	$selected = $facets['type'] ?? array();
	$count    = count( $selected );
	$label    = __( 'Type', 'wporg' );

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
		'action' => build_form_action_url(),
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
	$facets   = get_query_var_facets();
	$selected = $facets['month'] ?? array();
	$count    = count( $selected );
	$label    = __( 'Month', 'wporg' );

	if ( $count > 0 ) {
		$label = sprintf(
			/* translators: The dropdown label for filtering, %s is the selected term count. */
			_n( 'Month <span>%s</span>', 'Month <span>%s</span>', $count, 'wporg' ),
			$count
		);
	}

	$months = array();

	for ( $i = 1; $i <= 12; $i++ ) {
		$month                           = strtotime( "2023-$i-1" );
		$months[ gmdate( 'm', $month ) ] = gmdate( 'F', $month );
	}

	return array(
		'label' => $label,
		'title' => __( 'Month', 'wporg' ),
		'key' => 'month',
		'action' => build_form_action_url(),
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
	$facets    = get_query_var_facets();
	$selected  = $facets['country'] ?? array();
	$count     = count( $selected );
	$countries = wcorg_get_countries();
	$label     = __( 'Country', 'wporg' );

	// Re-index to match the format expected by the query-filters block. e.g., `DE` => `Germany`.
	$countries = array_combine(
		array_keys( $countries ),
		array_column( $countries, 'name' )
	);

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
		'action' => build_form_action_url(),
		'options' => $countries,
		'selected' => $selected,
	);
}
