<?php
/**
 * Block Name: WordPress Event List
 * Description: List of WordPress Events.
 *
 * @package wporg
 */

namespace WordPressdotorg\Theme\Events_2023\WordPress_Event_List;

use WordPressdotorg\Events_2023;
use WP_Block, WP_Error, WP_REST_Server;
use WordPressdotorg\MU_Plugins\Google_Map;

add_action( 'init', __NAMESPACE__ . '\init' );
add_action( 'rest_api_init', __NAMESPACE__ . '\register_routes' );


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
		return get_nearby_events_markup();
	}

	$attributes['id'] ??= wp_unique_id('events');

	$facets = Events_2023\get_query_var_facets();
	$events = Google_Map\get_events( $attributes['events'], 0, 0, $facets );

	// Get all the filters that are currently applied.
	$filtered_events = filter_events( $events );

	if ( count( $filtered_events ) < 1 ) {
		return get_no_result_view();
	}

	// The results are not guaranteed to be in order, so sort them.
	$order = strtoupper( $attributes['order'] ?? 'ASC' );
	usort( $filtered_events,
		function ( $a, $b ) use( $order ) {
			if ( 'ASC' === $order ) {
				return $a->timestamp - $b->timestamp;
			} else {
				return $b->timestamp - $a->timestamp;
			}
		}
	);

	// Limit the length of the results, as requested.
	$filtered_events = array_slice( $filtered_events, 0, (int) $attributes['limit'] );

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
		'groupByMonth' => $attributes['groupByMonth'],
	);

	wp_add_inline_script(
		// `generate_block_asset_handle()` includes the index if `viewScript` is an array, so this is fragile.
		// There isn't a way to get it programmatically, though, so it just has to manually be kept in sync.
		'wporg-event-list-view-script-2',
		sprintf(
			'var globalEventsPayload = globalEventsPayload || {};
			globalEventsPayload[%s] = %s;',
			wp_json_encode( (string) $attributes['id'] ),
			wp_json_encode( $payload )
		),
		'before'
	);

	ob_start();

	?>

	<p class="wporg-marker-list__loading">
		Loading events...
		<img
			src="<?php echo esc_url( includes_url( 'images/spinner-2x.gif' ) ); ?>"
			width="20"
			height="20"
			alt=""
		/>
	</p>

	<?php

	$content = ob_get_clean();

	$wrapper_attributes = get_block_wrapper_attributes( array(
		'id' => 'wp-block-wporg-event-list-' . $attributes['id'],
	) );

	return sprintf(
		'<div %1$s>%2$s</div>',
		$wrapper_attributes,
		do_blocks( $content )
	);
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

/**
 * Get markup for the list of events near the visitor.
 *
 * The front page is page-cached, so nothing here can depend on the visitor. `view.js` fills it in, either from the
 * `nearby` REST endpoint (approximate location, which needs the visitor's IP) or straight from the Events API (a
 * city they searched for).
 *
 * @return string
 */
function get_nearby_events_markup() {
	$wrapper_attributes = get_block_wrapper_attributes( array(
		'class'         => 'wporg-event-list__nearby',
		'data-rest-url' => rest_url( 'wporg-events/v1/nearby' ),
	) );

	ob_start();

	?>

	<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by `get_block_wrapper_attributes()`. ?>>
		<div class="wporg-event-list__nearby-header">
			<h2 class="wp-block-heading has-inter-font-family has-medium-font-size" style="font-style:normal;font-weight:700">
				<?php esc_html_e( 'Events near you', 'wporg' ); ?>
			</h2>

			<p class="wporg-event-list__nearby-location">
				<svg aria-hidden="true" focusable="false" width="16" height="16" viewBox="0 0 24 24">
					<path fill="currentColor" d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z" />
				</svg>
				<span class="wporg-event-list__nearby-location-name"><?php esc_html_e( 'Near you', 'wporg' ); ?></span>
				<button type="button" class="wporg-event-list__nearby-change">
					<?php esc_html_e( 'Change', 'wporg' ); ?>
				</button>
			</p>

			<form class="wporg-event-list__nearby-form wporg-events__hidden" role="search">
				<label class="screen-reader-text" for="wporg-event-list__nearby-city">
					<?php esc_html_e( 'City', 'wporg' ); ?>
				</label>
				<input
					type="text"
					id="wporg-event-list__nearby-city"
					class="wporg-event-list__nearby-city"
					placeholder="<?php esc_attr_e( 'City', 'wporg' ); ?>"
					autocomplete="address-level2"
					maxlength="100"
					required
				/>
				<button type="submit" class="wp-element-button">
					<?php esc_html_e( 'Search', 'wporg' ); ?>
				</button>
			</form>
		</div>

		<p class="wporg-event-list__nearby-description">
			<?php esc_html_e( 'Based on your approximate location, the same way the WordPress dashboard finds events near you.', 'wporg' ); ?>
		</p>

		<p class="wporg-marker-list__loading">
			<?php esc_html_e( 'Loading events near you...', 'wporg' ); ?>
			<img
				src="<?php echo esc_url( includes_url( 'images/spinner-2x.gif' ) ); ?>"
				width="20"
				height="20"
				alt=""
			/>
		</p>

		<div class="wporg-event-list__nearby-results" aria-live="polite"></div>

		<div class="wporg-event-list__nearby-empty wporg-events__hidden">
			<p>
				<?php
				printf(
					/* translators: %s: The place the visitor searched for, or "you". */
					esc_html__( "There aren't any WordPress events scheduled near %s right now.", 'wporg' ),
					'<strong class="wporg-event-list__nearby-empty-place"></strong>'
				);
				?>
				<br />
				<?php
				printf(
					wp_kses_post(
						/* translators: %s: URL of the Organize an Event page. */
						__( 'Want to be the one who starts something? <a href="%s">Organize an event</a>, or browse every upcoming event below.', 'wporg' )
					),
					esc_url( home_url( '/organize-an-event/' ) )
				);
				?>
			</p>
		</div>
	</div>

	<?php

	return ob_get_clean();
}

/**
 * Register REST API routes.
 */
function register_routes() {
	register_rest_route(
		'wporg-events/v1',
		'/nearby',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\get_nearby_events',
			'permission_callback' => '__return_true',
			'args'                => array(
				'timezone' => array(
					'type'              => 'string',
					'default'           => '',
					'validate_callback' => function ( $timezone ) {
						return '' === $timezone || in_array( $timezone, timezone_identifiers_list(), true );
					},
				),
			),
		)
	);
}

/**
 * Get upcoming events near the visitor's approximate location.
 *
 * This only ever geolocates the visitor's IP. Searching for a different city happens in the browser, straight
 * against the Events API, so this endpoint never forwards arbitrary input there.
 *
 * @param \WP_REST_Request $request
 *
 * @return array|WP_Error
 */
function get_nearby_events( $request ) {
	require_once dirname( __DIR__, 2 ) . '/inc/class-nearby-community-events.php';

	$ip = Events_2023\Nearby_Community_Events::get_unsafe_client_ip();

	if ( ! $ip ) {
		return new WP_Error( 'nearby_events_no_location', 'Could not determine your location.', array( 'status' => 400 ) );
	}

	$community_events = new Events_2023\Nearby_Community_Events( 0, array( 'ip' => $ip ) );
	$response         = $community_events->get_events( '', $request['timezone'] );

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'nearby_events_unavailable', 'Events near you are not available right now.', array( 'status' => 502 ) );
	}

	return array(
		'events' => prepare_nearby_events( $response['events'] ),
	);
}

/**
 * Reduce Events API results to the fields the list displays, in the shape the global events use.
 *
 * @param array $events
 *
 * @return array
 */
function prepare_nearby_events( array $events ) {
	return array_values( array_map(
		function ( $event ) {
			return array(
				'title'     => (string) ( $event['title'] ?? '' ),
				'url'       => (string) ( $event['url'] ?? '' ),
				'location'  => (string) ( $event['location']['location'] ?? '' ),
				'timestamp' => (int) ( $event['start_unix_timestamp'] ?? 0 ),
				'type'      => (string) ( $event['type'] ?? '' ),
			);
		},
		$events
	) );
}
