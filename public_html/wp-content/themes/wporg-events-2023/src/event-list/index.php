<?php
/**
 * Block Name: WordPress Event List
 * Description: List of WordPress Events.
 *
 * @package wporg
 */

namespace WordPressdotorg\Theme\Events_2023\WordPress_Event_List;

use WordPressdotorg\Events_2023;
use WP_Block;
use WordPressdotorg\MU_Plugins\Google_Map;
use WP_Community_Events;

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
		dirname( __DIR__, 2 ) . '/build/event-list',
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
	if ( 'nearby' === $attributes['events'] ) {
		$content = get_nearby_events_markup();
	} else {
		$content = get_global_events_markup( $attributes['events'], $attributes['limit'], $attributes['groupByMonth'] );
	}

	$extra_attributes = array(
		'id' => $attributes['id'] ?? '',
		'class' => 'wporg-event-list__filter-' . $attributes['events']
	);
	$wrapper_attributes = get_block_wrapper_attributes( $extra_attributes );

	return sprintf(
		'<div %1$s>%2$s</div>',
		$wrapper_attributes,
		do_blocks( $content )
	);
}

/**
 * Get markup for a list of global events.
 */
function get_global_events_markup( string $filter, int $limit, bool $group_by_month ): string {
	$facets = Events_2023\get_query_var_facets();
	$events = Google_Map\get_events( $filter, 0, 0, $facets );

	// Get all the filters that are currently applied.
	$filtered_events = array_slice( filter_events( $events ), 0, $limit );

	// The results are not guaranteed to be in order, so sort them.
	usort( $filtered_events,
		function ( $a, $b ) {
			return $a->timestamp - $b->timestamp;
		}
	);

	if ( count( $filtered_events ) < 1 ) {
		return get_no_result_view();
	}

	// Prune to only the used properties, to reduce the size of the payload.
	$filtered_events = array_map(
		function ( $event ) {
			return array(
				'title'     => $event->title,
				'url'       => $event->url,
				'location'  => $event->location,
				'timestamp' => $event->timestamp,
			);
		},
		$filtered_events
	);

	$payload = array(
		'events'       => $filtered_events,
		'groupByMonth' => $group_by_month,
	);

	wp_add_inline_script(
		// `generate_block_asset_handle()` includes the index if `viewScript` is an array, so this is fragile.
		// There isn't a way to get it programmatically, though, so it just has to manually be kept in sync.
		'wporg-event-list-view-script-2',
		'globalEventsPayload = ' . wp_json_encode( $payload ) . ';',
		'before'
	);

	$content = wp_kses_post( get_loading_markup( 'Loading global events...' ) );
	$content .= '<ul class="wporg-marker-list__container"></ul>';

	return $content;
}

/**
 * Get the markup for the loading indicator.
 */
function get_loading_markup( string $text ): string {
	ob_start();

	?>

	<p class="wporg-marker-list__loading">
		<?php echo esc_html( $text ); ?>

		<img
			src="<?php echo esc_url( includes_url( 'images/spinner-2x.gif' ) ); ?>"
			width="20"
			height="20"
			alt=""
		/>
	</p>

	<?php

	return ob_get_clean();
}

/**
 * Get markup for a list of nearby events.
 *
 * The events themselves are populated by an XHR, to avoid blocking the TTFB with an external HTTP request. See `view.js`.
 */
function get_nearby_events_markup(): string {
	ob_start();

	require_once ABSPATH . 'wp-admin/includes/class-wp-community-events.php';

	$payload = array(
		'ip'     => WP_Community_Events::get_unsafe_client_ip(),
		'number' => 10,
	);

	if ( is_user_logged_in() ) {
		$payload['locale'] = get_user_locale( get_current_user_id() );
	}

	wp_add_inline_script(
		// See note `get_global_events_markup()` about keeping this in sync.
		'wporg-event-list-view-script-2',
		'localEventsPayload = ' . wp_json_encode( $payload ) . ';',
		'before'
	);

	?>

	<!-- wp:wporg/notice {"type":"warning","className":"wporg-marker-list__no-results wporg-events__hidden"} -->
	<div class="wp-block-wporg-notice is-warning-notice wporg-marker-list__no-results wporg-events__hidden">
		<p>
			There are no events scheduled near you at the moment. You can <a href="<?php echo esc_url( home_url( 'upcoming-events/' ) ); ?>">browse global events</a>, or
			<a href="https://make.wordpress.org/community/handbook/meetup-organizer/welcome/" class="external-link">learn how to organize an event in your area</a>.
		</p>
	</div>
	<!-- /wp:wporg/notice -->

	<!-- wp:wporg/notice {"type":"warning","className":"wporg-marker-list__not-many-results wporg-events__hidden"} -->
	<div class="wp-block-wporg-notice is-warning-notice wporg-marker-list__not-many-results wporg-events__hidden">
		<p>
			Want more events?
			<a href="https://make.wordpress.org/community/handbook/meetup-organizer/welcome/" class="external-link">
				You can help organize the next one!
			</a>
		</p>
	</div>
	<!-- /wp:wporg/notice -->

	<?php echo wp_kses_post( get_loading_markup( 'Loading nearby events...' ) ); ?>

	<ul class="wporg-marker-list__container"></ul>

	<?php

	return ob_get_clean();
}

/**
 * Get a list of the currently-applied filters.
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
		if ( isset( $event->type ) && in_array( $event->type, $terms ) ) {
			$filtered_events[] = $event;
		}
	}

	return $filtered_events;
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
				/* translators: %s is the URL of the event archives. */
				__( 'View <a href="%s">upcoming events</a> or try a different search.', 'wporg' )
			),
			esc_url( home_url( '/upcoming-events/' ) )
		)
	);
	$content .= '</div><!-- /wp:group -->';

	return do_blocks( $content );
}
