<?php

namespace WordCamp\Blocks\Schedule;
use WordCamp\Blocks;
use WP_Post;

defined( 'WPINC' ) || die();


/**
 * Register block types and enqueue scripts.
 *
 * @return void
 */
function init() {
	register_block_type(
		'wordcamp/schedule',
		[
			'attributes'      => get_attributes_schema(),
			'render_callback' => __NAMESPACE__ . '\render',
			'editor_script'   => 'wordcamp-blocks',
			'editor_style'    => 'wordcamp-blocks',
			'style'           => 'wordcamp-blocks',
		]
	);
}
add_action( 'init', __NAMESPACE__ . '\init' );

/**
 * Render the block on the front end.
 *
 * @param array $attributes Block attributes.
 *
 * @return string
 */
function render( $attributes ) {
	$defaults   = wp_list_pluck( get_attributes_schema(), 'default' );
	$attributes = wp_parse_args( $attributes, $defaults );
	$sessions   = get_session_posts( $attributes );

	$container_classes = [
		'wordcamp-block',
		'wordcamp-block-post-list', // todo maybe not have this one?
		'wordcamp-schedule-block',
		sanitize_html_class( $attributes['className'] ),
	];

	$container_classes = implode( ' ', $container_classes );

	ob_start();
	require Blocks\PLUGIN_DIR . 'view/schedule.php';
	return ob_get_clean();
}

/**
 * Add data to be used by the JS scripts in the block editor.
 *
 * @param array $data
 *
 * @return array
 */
function add_script_data( array $data ) {
	$data['schedule'] = [
		'schema'  => get_attributes_schema(),
		'options' => get_options(),
	];

	return $data;
}
add_filter( 'wordcamp_blocks_script_data', __NAMESPACE__ . '\add_script_data' );

/**
 * Print styles that need to be generated dynamically.
 */
function print_dynamic_styles() {
	global $post;

	if ( ! $post instanceof WP_Post || ! has_block( 'wordcamp/schedule', $post ) ) {
		return;
	}

	?>

	<!-- wordcamp/schedule block -->
	<style>
		@supports ( display: grid ) {
			@media screen and ( min-width: 700px ) {
				.wordcamp-schedule-day {
					/* Organizers: To make the row height proportional to session length, change `auto` to `1fr` for the `time-NNNN` rows. */
					grid-template-rows:
						[tracks]    auto
						[time-0800] auto
						[time-0830] auto
						[time-0900] auto
						[time-0930] auto
						[time-1000] auto
						[time-1030] auto
						[time-1100] auto
						[time-1130] auto
						[time-1200] auto;

					grid-template-columns:
						[times] 4em	/* todo maybe make 5em, or 1fr, because fixed width caused problems when adding padding earlier */
						[wordcamp-schedule-track-1-start] 1fr
						[wordcamp-schedule-track-1-end wordcamp-schedule-track-2-start] 1fr
						[wordcamp-schedule-track-2-end wordcamp-schedule-track-3-start] 1fr
						[wordcamp-schedule-track-3-end wordcamp-schedule-track-4-start] 1fr
						[wordcamp-schedule-track-4-end];

					/* todo calculate these dynamically based on sessions */
					/* todo those track names are really verbose, can you remove the prefix without conflicting with other things? */
				}
			}
		}
	</style>

	<?php
}
add_action( 'wp_print_styles',    __NAMESPACE__ . '\print_dynamic_styles' );
add_action( 'admin_print_styles', __NAMESPACE__ . '\print_dynamic_styles' );

/**
 * Get the posts to display in the block.
 *
 * @param array $attributes
 *
 * @return array
 */
function get_session_posts( array $attributes ) {
	$post_args = [
		'post_type'      => 'wcb_session',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
	];

//	switch ( $attributes['mode'] ) {
		// not sure what to do here. need to filter by category, days, tracks, but those aren't exclusive modes
		// seems like there's just two modes: placeholder and the schedule
			// is there even a placeholder? actually i'm not sure there is
		// maybe the "modes" concept breaks down with this block, and we need to refactor it to include this?
		// or simpler to just treat this like there's only 1 mode, and remove the mode parameter entirely?
		// yeah, probably ^
		// but even if do that, still need to have something here to filter the query based on cats/days/tracks

//		case 'wcb_organizer':
//			$post_args['post__in'] = $attributes['item_ids'];
//			break;
//
//		case 'wcb_organizer_team':
//			$post_args['tax_query'] = [
//				[
//					'taxonomy' => $attributes['mode'],
//					'field'    => 'id',
//					'terms'    => $attributes['item_ids'],
//				],
//			];
//			break;
//	}

	// why does this run on the back end? should only be called on front, right? unless maybe called for API, yeah that's probably it

	return get_posts( $post_args );
}

/**
 * Get the schema for the block's attributes.
 *
 * @return array
 */
function get_attributes_schema() {
	// need to update this once pr is merged to make them dry
	// this one will be different than others in that it wont have a 'mode', so need to make that not a shared attribute? or maybe leave it sahred since most blocks use it, but explicilty remove it here?

	return [
		'item_ids'     => [
			'type'    => 'array',
			'default' => [],
			'items'   => [
				'type' => 'integer',
			],
		],
		'className'    => [
			'type'    => 'string',
			'default' => '',
		],
		'show_categories' => [
			'type'    => 'bool',
			'default' => false,
		],
		'chosen_days' => [
			'type'    => 'array',
			'default' => [],
			'items'   => [
				'type' => 'string',
			],
		],
		'chosen_tracks' => [
			'type'    => 'array',
			'default' => [],
			'items'   => [
				'type' => 'integer',
			],
		],

		// don't think we need this one
//		'content'      => [
//			'type'    => 'string',
//			'enum'    => wp_list_pluck( get_options( 'content' ), 'value' ),
//			'default' => 'full',
//		],
	];
}

/**
 * Get the label/value pairs for all options or a specific type.
 *
 * @param string $type
 *
 * @return array
 */
function get_options( $type = '' ) {
	$options = [
		// don't need for categories b/c bool?

		// what about chosen days and tracks? maybe? i guess so? see what happens without, but will probably need to add
	];

	if ( $type ) {
		return empty( $options[ $type ] ) ? [] : $options[ $type ];
	}

	return $options;
}
