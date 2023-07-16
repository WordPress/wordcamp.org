<?php

namespace WordPress_Community\Applications\Tracker;

use \WordCamp_Loader;
defined( 'WPINC' ) || die();

const SHORTCODE_SLUG = 'application-tracker';

add_shortcode( SHORTCODE_SLUG, __NAMESPACE__ . '\render_status_shortcode' );

/**
 * Render the [application-tracker] shortcode.
 */
function render_status_shortcode( $atts = array() ) {
	$application_type = 'wordcamp';
	if ( isset( $atts['type'] ) ) {
		$application_type = $atts['type'];
	}
	enqueue_scripts( $application_type );
	return '<div id="wpc-application-tracker">Loading WordCamp Application Tracker...</div>';
}

/**
 * Get applications that are active enough to be shown on the tracker
 *
 * @param string $application_type Type of application. Could be `wordcamp` or `meetup`.
 * @return array
 */
function get_active_events( $application_type ) {
	global $wpdb;
	$events             = array();
	$shown_statuses     = array();
	$statuses           = array();
	$milestones         = array();
	$inactive_timestamp = strtotime( '60 days ago' );
	$post_type          = '';

	if ( 'wordcamp' === $application_type ) {
		$statuses   = WordCamp_Loader::get_post_statuses();
		$milestones = WordCamp_Loader::map_statuses_to_milestones();
		unset( $shown_statuses[ WCPT_FINAL_STATUS ] );
		$post_type = WCPT_POST_TYPE_ID;
	} elseif ( 'meetup' === $application_type ) {
		$statuses  = \Meetup_Loader::get_post_statuses();
		$post_type = WCPT_MEETUP_SLUG;
	}

	$shown_statuses = array_keys( $statuses );

	$event_post_objs = $wpdb->get_results(
		$wpdb->prepare(
			"
			SELECT DISTINCT post_id, MAX( meta_value ) as last_updated
			FROM {$wpdb->prefix}postmeta
			WHERE
				meta_key like %s
			AND
				meta_value >= %d
			GROUP BY post_id
			",
			'_status_change_log_' . $post_type . '%',
			$inactive_timestamp
		)
	);

	$event_posts = array();
	foreach ( $event_post_objs as $event_post ) {
		$event_posts[ $event_post->post_id ] = $event_post->last_updated;
	}

	$raw_posts = get_posts(
		array(
			'post_type'      => $post_type,
			'post_status'    => $shown_statuses,
			'posts_per_page' => 1000,
			'order'          => 'ASC',
			'orderby'        => 'post_title',
			'post__in'       => array_keys( $event_posts ),
		)
	);

	foreach ( $raw_posts as $key => $post ) {
		$last_update_timestamp = $event_posts[ $post->ID ];

		if ( 'wordcamp' === $application_type ) {
			$events[] = array(
				'city'          => $post->post_title,
				'cityUrl'       => filter_var( get_post_meta( $post->ID, 'URL', true ), FILTER_VALIDATE_URL ),
				'applicant'     => esc_html( get_post_meta( $post->ID, 'Organizer Name', true ) ),
				'milestone'     => $milestones[ $post->post_status ],
				'status'        => $statuses[ $post->post_status ],
				'lastUpdate'    => $last_update_timestamp,
				'humanizedTime' => human_time_diff( $last_update_timestamp ),
			);
		} elseif ( 'meetup' === $application_type ) {
			$events[] = array(
				'city'          => $post->post_title,
				'cityUrl'       => filter_var( get_post_meta( $post->ID, 'Meetup URL', true ), FILTER_VALIDATE_URL ),
				'applicant'     => esc_html( get_post_meta( $post->ID, 'Organizer Name', true ) ),
				'status'        => $statuses[ $post->post_status ],
				'lastUpdate'    => $last_update_timestamp,
				'humanizedTime' => human_time_diff( $last_update_timestamp ),
			);
		}
	}

	return $events;
}

/**
 * Get the columns headers for WordCamp
 *
 * @return array
 */
function get_display_columns( $application_type ) {
	switch ( $application_type ) {
		case 'wordcamp':
			return array(
				'city'       => 'City',
				'applicant'  => 'Applicant',
				'milestone'  => 'Milestone',
				'status'     => 'Status',
				'lastUpdate' => 'Updated',
			);
		case 'meetup':
			return array(
				'city' => 'City',
				'applicant' => 'Applicant',
				'status' => 'Status',
				'lastUpdate' => 'Updated',
			);
	}
}

/**
 * Enqueue scripts and styles.
 * Based on the event type passed, we will localize different data for Meetup and WordCamp events.
 *
 * @param string $application_type Application type for the tracker table. Could be either `wordcamp` or `meetup`.
 */
function enqueue_scripts( $application_type ) {
	$script_info = require WP_PLUGIN_DIR . '/wcpt/javascript/tracker/build/applications.min.asset.php';

	wp_register_script(
		'wpc-application-tracker',
		plugins_url( 'javascript/tracker/build/applications.min.js', dirname( __FILE__ ) ),
		$script_info['dependencies'],
		$script_info['version'],
		true
	);

	wp_register_style(
		'wpc-application-tracker',
		plugins_url( 'javascript/tracker/build/style-applications.css', dirname( __FILE__ ) ),
		array( 'dashicons', 'list-tables' ),
		$script_info['version']
	);

	wp_enqueue_script( 'wpc-application-tracker' );
	wp_enqueue_style( 'wpc-application-tracker' );

	$data = array(
		'applications'     => get_active_events( $application_type ),
		'displayColumns'   => get_display_columns( $application_type ),
		'initialSortField' => 'city',
	);

	wp_add_inline_script(
		'wpc-application-tracker',
		sprintf(
			'var wpcApplicationTracker = JSON.parse( decodeURIComponent( \'%s\' ) );',
			rawurlencode( wp_json_encode( $data ) )
		),
		'before'
	);
}

