<?php

namespace WordCamp\Blocks\Schedule;

use WP_Post, WP_REST_Request;
use const WordCamp\Blocks\{ PLUGIN_DIR, PLUGIN_URL };

defined( 'WPINC' ) || die();

/**
 * Register block types and enqueue scripts.
 *
 * @return void
 */
function init() {
	$front_end_assets = require PLUGIN_DIR . 'build/schedule-front-end.min.asset.php';

	$front_end_assets['dependencies'][] = 'wp-sanitize';
	wp_register_script(
		'wordcamp-schedule-front-end',
		PLUGIN_URL . 'build/schedule-front-end.min.js',
		$front_end_assets['dependencies'],
		$front_end_assets['version'],
		true
	);

	wp_register_style(
		'wordcamp-schedule-front-end',
		PLUGIN_URL . 'build/schedule-front-end.css',
		array(),
		filemtime( PLUGIN_DIR . 'build/schedule-front-end.css' )
	);

	wp_set_script_translations( 'wordcamp-schedule-front-end', 'wordcamporg' );

	$block_arguments = array(
		'attributes'      => get_attributes_schema(),
		'render_callback' => __NAMESPACE__ . '\render',

		'editor_script' => 'wordcamp-blocks',
		'editor_style'  => 'wordcamp-blocks',
	);

	register_block_type( 'wordcamp/schedule', $block_arguments );
}
add_action( 'init', __NAMESPACE__ . '\init' );

/**
 * Enable registration of the block on the JavaScript side.
 *
 * This only exists to pass the `enabledBlocks` test in `blocks.js`. We don't actually need to populate the
 * `WordCampBlocks.schedule` property with anything on the back end, because all of the necessary data has
 * to be fetched on the fly in `fetchScheduleData`.
 *
 * See `pass_global_data_to_front_end()` for details on front- vs back-end data sourcing.
 *
 * @todo There's probably an elegant way to avoid the need for a workaround, by refactoring `blocks.js`.
 *
 * @param array $data
 *
 * @return array
 */
function enable_js_block_registration( $data ) {
	$data['schedule'] = array();

	return $data;
}
add_filter( 'wordcamp_blocks_script_data', __NAMESPACE__ . '\enable_js_block_registration' );

/**
 * Pass data that's shared across all front-end Schedule block instances from PHP to JS.
 *
 * `render()` will output the data that's specific to each block.
 *
 * This is only used on the front end, not in the editor. For the editor, see `fetchScheduleData()`. In both
 * contexts, the ideal situation would be to fetch the data up front, so that the block can be rendered
 * immediately as the page loads. The page content is the most important thing, and fetching it async would
 * introduce unnecessary delays and UX pain.
 *
 * That wouldn't be performant in the editor, because any block can be added to a page at any time; to immediately
 * render, all blocks would need to output their data on all pages, which wouldn't be performant.
 *
 * We can do that on the front end though, because we know ahead of time whether or not the block is on the page,
 * and don't have to worry about it being added during the current render.
 *
 * This has to run on `template_redirect` instead of `init`, because calling `get_sessions_post()` et al would
 * create nested REST API queries. That would remove all `wc-post-types` routes from the API response, which would
 * break block rendering.
 *
 * In the back end, the data is intentionally fetch when the block loads. See `enable_js_block_registration()`.
 */
function pass_global_data_to_front_end() {
	global $post;

	if ( ! $post instanceof WP_Post || ! has_block( 'wordcamp/schedule', $post ) ) {
		return;
	}

	$schedule_data = array(
		'allSessions'   => get_all_sessions(),
		'allTracks'     => get_all_tracks(),
		'allCategories' => get_all_categories(),
		'settings'      => get_settings(),
	);

	// The rest request in get_all_sessions changes the global $post value.
	wp_reset_postdata();

	wp_add_inline_script(
		'wordcamp-schedule-front-end',
		sprintf(
			'var WordCampBlocks = WordCampBlocks || {}; WordCampBlocks.schedule = JSON.parse( decodeURIComponent( \'%s\' ) );',
			rawurlencode( wp_json_encode( $schedule_data ) )
		),
		'before'
	);
}
add_action( 'template_redirect', __NAMESPACE__ . '\pass_global_data_to_front_end' );

/**
 * Get the schema for the block's attributes.
 *
 * These intentionally use `camelCase` naming, despite some other blocks using `snake_case`. That's because, in
 * this block, the attributes are only referenced in JavaScript. If they were `snake_case`, then it'd be painful
 * to maintain consistency with our JavaScript style guide. We could either rename them on-the-fly whenever
 * they're destructured, or we could always reference the `snake_case` name.
 *
 * The former would be tedious, error prone, and reduce readability/simplicity; the latter would introduce
 * inconsistency when a `snake_case` attribute was passed as an argument to a function, which would need to
 * receive the parameter in `camelCase` format. The latter would also introduce visual inconsistency between
 * attribute naming and all other names.
 *
 * @return array
 */
function get_attributes_schema() {
	$schema = array(
		// See index.js for explanation.
		'__isStylesPreview' => array(
			'type'    => 'boolean',
			'default' => false,
		),

		'align' => array(
			'type'    => 'string',
			'enum'    => array( 'wide', 'full' ),
			'default' => 'wide',
		),

		'chooseSpecificDays' => array(
			'type'    => 'boolean',
			'default' => false,
		),

		'chosenDays' => array(
			'type'    => 'array',
			'default' => array(),

			'items' => array(
				'type' => 'string',
			),
		),

		'chooseSpecificTracks' => array(
			'type'    => 'boolean',
			'default' => false,
		),

		'chosenTrackIds' => array(
			'type'    => 'array',
			'default' => array(),

			'items' => array(
				'type' => 'integer',
			),
		),

		'showCategories' => array(
			'type'    => 'boolean',
			'default' => true,
		),
	);

	return $schema;
}

/**
 * Get the posts to display in the block.
 *
 * Using an internal REST API query because the data needs to match the same format that `fetchScheduleData()`
 * returns.
 *
 * @todo If needed, this could be optimized by only querying for sessions that have been assigned a date/time. If
 *       specific days/tracks attributes are set, then the query could also be narrowed to sessions which match
 *       those. Make sure that it's the _union_ of al chosen days/tracks for _all_ blocks on the page, though,
 *       because this data is shared among all of them.
 *
 * @return array
 */
function get_all_sessions() {
	// These must be kept in sync with `fetchScheduleData()`.
	$session_fields = array(
		'id',
		'link',
		'meta._wcpt_session_time',
		'meta._wcpt_session_duration',
		'meta._wcpt_session_type',
		'session_category',
		'session_speakers',
		'session_track',
		'slug',
		'title',
	);

	$request = new WP_REST_Request( 'GET', '/wp/v2/sessions' );

	$request->set_param( 'per_page', 100 ); // @todo paginate this, because some larger camps have 90+ sessions.
	$request->set_param( '_fields', $session_fields );

	$response = rest_do_request( $request );

	return $response->get_data();
}

/**
 * Get all of the tracks, including ones that won't be displayed.
 *
 * Using an internal REST API query because the data needs to match the same format that `fetchScheduleData()`
 * returns.
 *
 * @return array
 */
function get_all_tracks() {
	$request = new WP_REST_Request( 'GET', '/wp/v2/session_track' );

	/*
	 * These must be kept in sync with `fetchScheduleData()`, especially the `orderby`. See comments in that
	 * function for details.
	 */
	$request->set_param( 'per_page', 100 );
	$request->set_param( '_fields', array( 'id', 'name', 'slug' ) );
	$request->set_param( 'orderby', 'slug' );

	$response = rest_do_request( $request );

	return $response->get_data();
}

/**
 * Get all of the categories, including ones that won't be displayed.
 *
 * Using an internal REST API query because the data needs to match the same format that `fetchScheduleData()`
 * returns.
 *
 * @return array
 */
function get_all_categories() {
	$request = new WP_REST_Request( 'GET', '/wp/v2/session_category' );

	// These must be kept in sync with `fetchScheduleData()`.
	$request->set_param( 'per_page', 100 );
	$request->set_param( '_fields', array( 'id', 'name', 'slug' ) );

	$response = rest_do_request( $request );

	return $response->get_data();
}

/**
 * Get the site's settings.
 *
 * @return array
 */
function get_settings() {
	/*
	 * This needs to match the same format that `fetchScheduleData()` returns.
	 *
	 * Hardcoding these instead of creating a `WP_REST_Request` because:
	 *
	 * 1) That API endpoint is only intended to be used by authorized users. Right now it doesn't contain anything
	 *    particularly sensitive, but that could change at any point in the future.
	 * 2) The data will need to be accessed by logged-out visitors, and that endpoint requires authentication by
	 *    default.
	 * 3) It makes the page size slightly smaller, and `WordCampBlocks` less cluttered.
	 */
	return array(
		'date_format' => get_option( 'date_format' ),
		'time_format' => get_option( 'time_format' ),
		'timezone'    => get_option( 'timezone' ),
	);
}

/**
 * Render the block on the front end.
 *
 * The attributes are intentionally passed from PHP to JavaScript here, instead of being saved in `post_content`.
 * That avoids the frustrating and time-consuming problem where even minor changes to `save()` will break existing
 * blocks, unless we write transforms.
 *
 * @param array $attributes
 *
 * @return string
 */
function render( $attributes ) {
	$defaults   = wp_list_pluck( get_attributes_schema(), 'default' );
	$attributes = wp_parse_args( $attributes, $defaults );

	require_once WP_PLUGIN_DIR . '/wc-post-types/inc/favorite-schedule-shortcode.php';
	require_once WP_PLUGIN_DIR . '/wc-post-types/inc/rest-api.php';

	/*
	 * Only enqueue these on pages where the block is present.
	 *
	 * Workaround for https://github.com/WordPress/gutenberg/issues/5445.
	 * @see https://github.com/WordPress/gutenberg/issues/21838
	 *
	 * This checks `is_admin()` because `render_callback()` is unexpectedly executed in the editor, maybe because
	 * of https://github.com/WordPress/gutenberg/issues/18394.
	 *
	 * @todo remove most of this when #5445 is resolved, but make sure that doesn't cause it to start being
	 * enqueued in the editor. The Favorite Sessions assets will still need to be enqueued manually, though.
	 */
	if ( ! is_admin() ) {
		wp_enqueue_script( 'wordcamp-schedule-front-end' );
		wp_enqueue_style( 'wordcamp-schedule-front-end' );

		enqueue_favorite_sessions_dependencies();
	}

	ob_start();
	require __DIR__ . '/view.php';
	return ob_get_clean();
}
