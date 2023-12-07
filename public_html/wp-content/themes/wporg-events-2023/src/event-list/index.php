<?php
/**
 * Block Name: WordPress Event List
 * Description: List of WordPress Events.
 *
 * @package wporg
 */

namespace WordPressdotorg\Theme\Events_2023\WordPress_Event_List;
use WP_Block;
use function WordPressdotorg\MU_Plugins\Google_Map\{ get_events, schedule_filter_cron };

add_action( 'init', __NAMESPACE__ . '\init' );

/**
 * Registers the block using the metadata loaded from the `block.json` file.
 * Behind the scenes, it registers also all assets so they can be enqueued
 * through the block editor in the corresponding context.
 *
 * @see https://developer.wordpress.org/reference/functions/register_block_type/
 */
function init() {
	register_block_type(
		dirname( dirname( __DIR__ ) ) . '/build/event-list',
		array(
			'render_callback' => __NAMESPACE__ . '\render',
		)
	);
}

/**
 * Render the block content.
 *
 * @param array    $attributes Block attributes.
 * @param string   $content    Block default content.
 * @param WP_Block $block      Block instance.
 *
 * @return string Returns the block markup.
 */
function render( $attributes, $content, $block ) {
	$facets = get_clean_query_facets();
	$events = get_events( $attributes['events'], 0, 0, $facets );
	schedule_filter_cron( $attributes['events'], 0, 0, $facets );

	// Get all the filters that are currently applied.
	$filtered_events = array_slice( filter_events( $events ), 0, (int) $attributes['limit'] );

	// The results are not guaranteed to be in order, so sort them.
	usort( $filtered_events,
		function ( $a, $b ) {
			return $a->timestamp - $b->timestamp;
		}
	);

	if ( count( $filtered_events ) < 1 ) {
		return get_no_result_view();
	}

	if ( (bool) $attributes['groupByMonth'] ) {
		// Group events by month year.
		$grouped_events = array();
		foreach ( $filtered_events as $event ) {
			$event_month_year                      = gmdate( 'F Y', esc_html( $event->timestamp ) );
			$grouped_events[ $event_month_year ][] = $event;
		}

		$content = '';
		foreach ( $grouped_events as $month_year => $events ) {
			$content .= get_section_title( $month_year );
			$content .= get_list_markup( $events );
		}
	} else {
		$content = get_list_markup( $filtered_events );
	}

	$wrapper_attributes = get_block_wrapper_attributes();
	return sprintf(
		'<div %1$s>%2$s</div>',
		$wrapper_attributes,
		do_blocks( $content )
	);
}

/**
 * Returns the event-list markup.
 *
 * @param array $events Array of events.
 *
 * @return string
 */
function get_list_markup( $events ) {
	$block_markup = '<ul class="wporg-marker-list__container">';

	foreach ( $events as $event ) {
		$block_markup .= '<li class="wporg-marker-list-item">';
		$block_markup .= '<h3 class="wporg-marker-list-item__title"><a class="external-link" href="' . esc_url( $event->url ) . '">' . esc_html( $event->title ) . '</a></h3>';
		$block_markup .= '<div class="wporg-marker-list-item__location">' . ucfirst( esc_html( $event->location ) ). '</div>';
		$block_markup .= sprintf(
			'<time class="wporg-marker-list-item__date-time" date-time="%1$s" title="%1$s"><span class="wporg-google-map__date">%2$s</span><span class="wporg-google-map__time">%3$s</span></time>',
			gmdate( 'c', esc_html( $event->timestamp ) ),
			gmdate( 'l, M j', esc_html( $event->timestamp ) ),
			esc_html( gmdate('H:i', $event->timestamp) . ' UTC' ),
		);
		$block_markup .= '</li>';
	}

	$block_markup .= '</ul>';

	return $block_markup;
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

	array_filter( $facets ); // Remove empty.

	return $facets;
}

/**
 * Get a list of the currently-applied filters.
 *
 * @return array
 */
function filter_events( array $events ): array {
	global $wp_query;

	$taxes = array(
		'map_type' => 'map_type',
	);
	$terms = array();

	// Get the terms.
	foreach ( $taxes as $query_var => $taxonomy ) {

		if ( ! isset( $wp_query->query[ $query_var ] ) ) {
			continue;
		}

		$values = (array) $wp_query->query[ $query_var ];
		foreach ( $values as $value ) {
			$terms[] = $value;
		}
	}

	if ( empty( $terms ) ) {
		return $events;
	}

	$filtered_events = array();
	foreach ( $events as $event ) {
		// Assuming each event has a 'type' property.
		if ( isset($event->type) && in_array($event->type, $terms) ) {
			$filtered_events[] = $event;
		}
	}

	return $filtered_events;
}

/**
 * Returns core heading block markup for the date groups.
 *
 * @param string $heading_text Heading text.
 *
 * @return string
 */
function get_section_title( $heading_text ) {
	$block_markup  = '<!-- wp:heading {"style":{"elements":{"link":{"color":{"text":"var:preset|color|charcoal-1"}}},"typography":{"fontStyle":"normal","fontWeight":"700"},"spacing":{"margin":{"top":"var:preset|spacing|30","bottom":"var:preset|spacing|20"}}},"textColor":"charcoal-1","fontSize":"medium","fontFamily":"inter"} -->';
	$block_markup .= sprintf(
		'<h2 class="wp-block-heading has-charcoal-1-color has-text-color has-link-color has-inter-font-family has-medium-font-size" style="margin-top:var(--wp--preset--spacing--30);margin-bottom:var(--wp--preset--spacing--20);font-style:normal;font-weight:700">%s</h2>',
		esc_html( $heading_text )
	);
	$block_markup .= '<!-- /wp:heading -->';

	return $block_markup;
}

/**
 * Returns a block driven view when no results are found.
 *
 * Ideally this would be a template part, but until we use WP_QUERY to get the events, we can't use template parts.
 *
 * @return string
 */
function get_no_result_view() {
	$content = '<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->';
	$content .= '<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50)">';
	$content .= sprintf( '<!-- wp:heading {"textAlign":"center","level":1,"fontSize":"heading-2"} --><h1 class="wp-block-heading has-text-align-center has-heading-2-font-size">%s</h1><!-- /wp:heading -->',
		esc_attr( 'No results found', 'wporg' )
	);
	$content .= sprintf(
		'<!-- wp:paragraph {"align":"center"} --><p class="has-text-align-center">%s</p><!-- /wp:paragraph -->',
		sprintf(
			wp_kses_post(
			/* translators: %s is url of the event archives. */
			__( 'View <a href="%s">upcoming events</a> or try a different search.', 'wporg' ) ),
		esc_url( home_url( '/upcoming-events/' ) ) )
	);
	$content .= '</div><!-- /wp:group -->';

	return do_blocks( $content );
}
