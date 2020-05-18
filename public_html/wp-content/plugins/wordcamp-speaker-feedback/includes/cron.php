<?php

namespace WordCamp\SpeakerFeedback\Cron;

use function WordCamp\SpeakerFeedback\Comment\{ get_feedback, update_feedback };
use function WordCamp\SpeakerFeedback\Post\{
	get_earliest_session_timestamp, get_latest_session_ending_timestamp,
	get_session_speaker_user_ids, get_session_feedback_url
};

defined( 'WPINC' ) || die();

const SPEAKER_OPT_OUT_KEY = 'sft_notifications_speaker_opt_out';

add_action( 'init', __NAMESPACE__ . '\schedule_jobs' );
add_action( 'sft_notify_speakers_approved_feedback', __NAMESPACE__ . '\notify_speakers_approved_feedback' );

/**
 * Add cron jobs to the schedule.
 *
 * @return void
 */
function schedule_jobs() {
	if ( ! wp_next_scheduled( 'sft_notify_speakers_approved_feedback' ) ) {
		$next_time = strtotime( 'Next day 6pm ' . wp_timezone_string() );
		wp_schedule_single_event( $next_time, 'sft_notify_speakers_approved_feedback' );
	}
}

/**
 * Notify speakers via email about newly approved feedback submissions.
 *
 * @return void
 */
function notify_speakers_approved_feedback() {
	if ( ! feedback_notifications_are_enabled() ) {
		return;
	}

	$wordcamp_name = get_wordcamp_name( get_current_blog_id() );

	$feedbacks       = get_unnotified_feedback();
	$post_ids        = array_combine(
		// Using the 'index_key' parameter doesn't work here, maybe because the values are numeric?
		wp_list_pluck( $feedbacks, 'comment_ID' ),
		wp_list_pluck( $feedbacks, 'comment_post_ID' )
	);
	$unique_post_ids = array_unique( array_values( $post_ids ) );

	$speaker_session_ids = array_reduce(
		$unique_post_ids,
		function( $carry, $post_id ) {
			$user_ids = get_session_speaker_user_ids( $post_id );

			foreach ( $user_ids as $user_id ) {
				if ( ! isset( $carry[ $user_id ] ) ) {
					$carry[ $user_id ] = array();
				}
				$carry[ $user_id ][] = $post_id;
			}

			return $carry;
		},
		array()
	);

	foreach ( $speaker_session_ids as $user_id => $session_ids ) {
		$speaker = get_user_by( 'id', $user_id );
		if ( true === wp_validate_boolean( $speaker->{SPEAKER_OPT_OUT_KEY} ) ) {
			continue;
		}

		$feedback_info = array(
			'total_new' => 0,
		);
		foreach ( $session_ids as $session_id ) {
			$session = get_post( $session_id );
			$associated_feedbacks = array_keys( $post_ids, $session_id );
			$feedback_info['total_new'] += count( $associated_feedbacks );
			$feedback_info[] = array(
				'feedback_ids' => $associated_feedbacks,
				// We don't want most of the filters for the_title running here, e.g. converting characters into HTML entities.
				'title'        => trim( strip_tags( $session->post_title ) ),
				'count'        => count( $associated_feedbacks ),
				'link'         => wp_login_url( get_session_feedback_url( $session_id ) ),
			);
		}

		$speaker_locale = get_user_locale( $speaker );
		switch_to_locale( $speaker_locale );

		$subject = sprintf(
			// translators: 1. Number of feedback submissions. 2. WordCamp name.
			esc_html( _n(
				'You have %1$s new feedback submission from %2$s',
				'You have %1$s new feedback submissions from %2$s',
				$feedback_info['total_new'],
				'wordcamporg'
			) ),
			number_format_i18n( $feedback_info['total_new'] ),
			esc_html( $wordcamp_name )
		);
		unset( $feedback_info['total_new'] );

		$message  = sprintf( esc_html__( 'Hi %s,', 'wordcamporg' ), $speaker->display_name );
		$message .= "\n\n";
		$message .= esc_html__( 'You have new feedback submissions to read on the following sessions:', 'wordcamporg' );
		$message .= "\n\n";

		foreach ( $feedback_info as $info ) {
			$message .= sprintf(
				// translators: 1. Number of new submissions. 2. Session title.
				esc_html( _n(
					'%1$s new submission on %2$s',
					'%1$s new submissions on %2$s',
					$info['count'],
					'wordcamporg'
				) ),
				number_format_i18n( $info['count'] ),
				esc_html( $info['title'] )
			);
			$message .= "\n";
			$message .= esc_url_raw( $info['link'] );
			$message .= "\n\n";
		}

		$message .= sprintf(
			// translators: %s is the name of the WordCamp.
			esc_html__( "Sincerely,\nThe %s organizing team", 'wordcamporg' ),
			esc_html( $wordcamp_name )
		);

		$result = wp_mail( $speaker->user_email, $subject, $message );

		restore_current_locale();

		if ( $result ) {
			foreach ( $info['feedback_ids'] as $feedback_id ) {
				update_feedback(
					$feedback_id,
					array(
						'speaker_notified' => true,
					)
				);
			}
		}
	}
}

/**
 * Check to see if the conditions are right to send notifications.
 *
 * @return bool
 */
function feedback_notifications_are_enabled() {
	$now       = date_create( 'now', wp_timezone() );
	$stop_time = get_option( 'sft_notification_stop_time', false );

	if ( false !== $stop_time && $now->getTimestamp() > $stop_time ) {
		return false;
	}

	// Session times can change, so this is a transient.
	$start_time = get_transient( 'sft_notification_start_time' );

	if ( ! $start_time ) {
		$start_time = get_earliest_session_timestamp();

		if ( ! $start_time ) {
			return false;
		}

		set_transient( 'sft_notification_start_time', $start_time, DAY_IN_SECONDS );

		$stop_time = strtotime( '+ 3 months', get_latest_session_ending_timestamp() );
		update_option( 'sft_notification_stop_time', $stop_time, false );
	}

	return $now->getTimestamp() > $start_time;
}

/**
 * Get a list of approved feedback comments that speakers have not yet been notified about.
 *
 * @return array
 */
function get_unnotified_feedback() {
	$args = array(
		'meta_query' => array(
			array(
				'key'     => 'speaker_notified',
				'compare' => 'NOT EXISTS',
			),
		),
	);

	return get_feedback( array(), array( 'approve' ), $args );
}
