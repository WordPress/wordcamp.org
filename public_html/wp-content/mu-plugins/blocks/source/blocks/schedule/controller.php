<?php

namespace WordCamp\Blocks\Schedule;
use WordCamp_Post_Types_Plugin;
use WP_Post, WP_Term;

defined( 'WPINC' ) || die();


/**
 * Register block types and enqueue scripts.
 *
 * @return void
 */
function init() {
	register_block_type(
		'wordcamp/schedule',
		array(
			'attributes'      => get_attributes_schema(),
			'render_callback' => __NAMESPACE__ . '\render',
			'editor_script'   => 'wordcamp-blocks',
			'editor_style'    => 'wordcamp-blocks',
			'style'           => 'wordcamp-blocks',
		)
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

	$container_classes = array(
		'wordcamp-schedule',
		sanitize_html_class( $attributes['className'] ),
	);

	$container_classes = implode( ' ', $container_classes );

	ob_start();
	require_once( __DIR__ . '/view.php' );
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
	$data['schedule'] = array(
		'schema'  => get_attributes_schema(),
		'options' => get_options(),
	);

	return $data;
}
add_filter( 'wordcamp_blocks_script_data', __NAMESPACE__ . '\add_script_data' );

/**
 * Print styles that need to be generated dynamically.
 */
function print_dynamic_styles() {
	global $post;

	if ( ! $post instanceof WP_Post ) {
		return;
	}

	if ( ! has_block( 'wordcamp/schedule', $post->post_content ) ) {
		return;
	}

	$blocks = parse_blocks( $post->post_content );

	foreach ( $blocks as $block ) :
		if ( 'wordcamp/schedule' !== $block['blockName'] ) {
			// todo test with multiple blocks that show different days/tracks.
			continue;
		}

		$chosen_sessions  = get_session_posts( $block['attrs'] ?? array() );
		$sessions_by_date = group_sessions_by_date( $chosen_sessions );

		?>

		<!-- wordcamp/schedule block -->
		<style>
			@supports ( display: grid ) {
				<?php // This media query should be kept in sync with the `breakpoint-grid-layout` mixin in block-content.scss. ?>
				@media screen and ( min-width: 700px ) {
					<?php
					// Each date must be generated individually, because it might have different times and tracks.
					foreach ( $sessions_by_date as $date => $sessions ) :
						$tracks = get_tracks_from_sessions( $sessions );

						/*
						 * Create an implicit "0" track when none formally exist.
						 *
						 * Camps with a single track may neglect to create a formal one, but the Grid still has to
						 * have a track to lay sessions onto.
						 */
						if ( empty( $tracks ) ) {
							$tracks[] = new WP_Term( (object) array( 'term_id' => 0 ) );
						}

						echo sprintf(
							'#wordcamp-schedule__day-%s {
								%s
								%s
							} ',
							esc_html( $date ),
								// todo this needs to include the track ids, and any other options, b/c could have multiple blocks on one page w/ different options
								// or maybe generate some kind of unique block id that won't change? that way organizers can target w/ custom css and not worry about it breaking if they change options?
							esc_html( render_grid_template_rows( $chosen_sessions ) ),
							esc_html( render_grid_template_columns( $tracks ) )
						);

					endforeach; ?>
				}
			}
		</style>
		<!-- End wordcamp/schedule block -->

	<?php endforeach;
}
add_action( 'wp_print_styles', __NAMESPACE__ . '\print_dynamic_styles' );

/*
 * @todo remove this when building inspector controls, because the editor needs the CSS generated on the fly
 * in response to user actions (selecting days, tracks, etc). The admin hook is only here temporarily while the static
 * interface is being built. The function will eventually only be used on the front-end.
 */
add_action( 'admin_print_styles', __NAMESPACE__ . '\print_dynamic_styles' );

/**
 * Render the `grid-template-rows` styles for the given sessions.
 *
 * @param array $chosen_sessions
 *
 * @return string
 */
function render_grid_template_rows( $chosen_sessions ) {
	ob_start();
	?>

	grid-template-rows:
		[tracks] auto

		/* Organizers: Set these to `1fr` to make the cell length correspond to the time length of the session. */
		<?php foreach ( get_start_end_times( $chosen_sessions ) as $time ) : ?>
			[time-<?php echo esc_html( $time ); ?>] auto
		<?php endforeach; ?>
	;

	<?php

	return ob_get_clean();
}

/**
 * Render the `grid-template-columns` styles for the given tracks.
 *
 * @param array $tracks
 *
 * @return string
 */
function render_grid_template_columns( $tracks ) {
	$current_track = current( $tracks );
	$next_track    = next( $tracks );

	ob_start();
	?>

	grid-template-columns:
		[times] auto
		[wordcamp-schedule-track-<?php echo esc_html( $current_track->term_id ); ?>-start] 1fr

		<?php

		if ( count( $tracks ) > 1 ) {
			while ( false !== $next_track ) {
				echo esc_html( "[
					wordcamp-schedule-track-{$current_track->term_id}-end
					wordcamp-schedule-track-{$next_track->term_id}-start
				] 1fr " );

				$current_track = current( $tracks );
				$next_track    = next(    $tracks );
			}
		}

		?>

		[wordcamp-schedule-track-<?php echo esc_html( $current_track->term_id ); ?>-end]
	;

	<?php

	return ob_get_clean();
}

/**
 * Group the given sessions by their dates.
 *
 * @param array $ungrouped_sessions
 *
 * @return array
 */
function group_sessions_by_date( $ungrouped_sessions ) {
	$grouped_sessions = array();

	foreach ( $ungrouped_sessions as $session ) {
		$date = date( 'Y-m-d', $session->_wcpt_session_time );

		$grouped_sessions[ $date ][] = $session;
	}

	return $grouped_sessions;
}

/**
 * Get the start and end times for the given sessions.
 *
 * @param array $sessions
 *
 * @return array
 */
function get_start_end_times( $sessions ) {
	$start_times = wp_list_pluck( $sessions, '_wcpt_session_time' );
	$start_times = array_map(
		function( $timestamp ) {
			return date( 'Hi', $timestamp );
		},
		$start_times
	);

	$end_times = array_map( __NAMESPACE__ . '\get_session_end_time', $sessions );
	$all_times = array_unique( array_merge( $start_times, $end_times ) );

	sort( $all_times );

	return $all_times;
}

/**
 * Get the end time for the given session.
 *
 * @param WP_Post $session
 *
 * @return string
 */
function get_session_end_time( $session ) {
	$start_time      = $session->_wcpt_session_time;
	$session_hours   = $session->_wcpt_session_length_hours;
	$session_minutes = $session->_wcpt_session_length_minutes;

	/*
	 * Retroactively set session length.
	 *
	 * These fields didn't exist before this block was created. Newer Session posts will set them when the posts
	 * are saved for the first time, but older posts need to have it back-filled. We should only do that for sites
	 * that are actually using this block, though, because otherwise we'd be inserting inaccurate data. It's ok
	 * for users of the block, though, because the length will be obvious when they look at the schedule, so
	 * they'll fix it if the default doesn't match the actual length.
	 */
	if ( '' === $session_hours && '' === $session_minutes ) {
		$session_hours   = WordCamp_Post_Types_Plugin::DEFAULT_LENGTH_HOURS;
		$session_minutes = WordCamp_Post_Types_Plugin::DEFAULT_LENGTH_MINUTES;

		update_post_meta( $session->ID, '_wcpt_session_length_hours',   $session_hours   );
		update_post_meta( $session->ID, '_wcpt_session_length_minutes', $session_minutes );
	}

	$duration = absint( $session_hours ) * HOUR_IN_SECONDS + absint( $session_minutes ) * MINUTE_IN_SECONDS;
	$end_time = $start_time + $duration;

	return date( 'Hi', $end_time );
}

/**
 * Get the tracks that the given sessions are assigned to.
 *
 * @param array $sessions
 *
 * @return array
 */
function get_tracks_from_sessions( array $sessions ) {
	$tracks = array();

	foreach ( $sessions as $session ) {
		$assigned_tracks = wp_get_object_terms( $session->ID, 'wcb_track' );

		foreach ( $assigned_tracks as $track ) {
			$tracks[ $track->term_id ] = $track;
		}
	}

	/*
	 * Sorting alphabetically by slug.
	 *
	 * This must be consistent so that `print_dynamic_styles()` can predict which grid-column comes next.
	 */
	uasort(
		$tracks,
		function( $first, $second ) {
			if ( $first->slug === $second->slug ) {
				return 0;
			}

			return $first->slug > $second->slug ? 1 : -1;
		}
	);

	return $tracks;
}

/**
 * Get the posts to display in the block.
 *
 * @param array $attributes
 *
 * @return array
 */
function get_session_posts( array $attributes ) {
	$post_args = array(
		'post_type'      => 'wcb_session',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'name',
		'order'          => 'asc',

		// Only get sessions that have been assigned a date/time.
		'meta_key'       => '_wcpt_session_time',
		'meta_value'     => 0,
		'meta_compare'   => '>',
	);

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

	return array(
		'item_ids' => array(
			'type'    => 'array',
			'default' => array(),
			'items'   => array(
				'type' => 'integer',
			),
		),

		'className' => array(
			'type'    => 'string',
			'default' => '',
		),

		'show_categories' => array(
			'type'    => 'bool',
			'default' => false,
		),

		'chosen_days' => array(
			'type'    => 'array',
			'default' => array(),
			'items'   => array(
				'type' => 'string',
			),
		),

		'chosen_tracks' => array(
			'type'    => 'array',
			'default' => array(),
			'items'   => array(
				'type' => 'integer',
			),
		),
	);
}

/**
 * Get the label/value pairs for all options or a specific type.
 *
 * @param string $type
 *
 * @return array
 */
function get_options( $type = '' ) {
	$options = array();

	if ( $type ) {
		return empty( $options[ $type ] ) ? [] : $options[ $type ];
	}

	return $options;
}
