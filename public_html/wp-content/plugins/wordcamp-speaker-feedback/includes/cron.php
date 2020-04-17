<?php

namespace WordCamp\SpeakerFeedback\Cron;

use function WordCamp\SpeakerFeedback\Comment\{ get_feedback, update_feedback };
use function WordCamp\SpeakerFeedback\Post\get_session_speaker_user_ids;

defined( 'WPINC' ) || die();

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
	if ( ! speaker_notifications_are_enabled() ) {
		return;
	}

	$wordcamp_name = get_wordcamp_name( get_current_blog_id() );

	$feedbacks       = get_unnotified_feedback();
	$post_ids        = wp_list_pluck( $feedbacks, 'comment_post_ID', 'comment_ID' );
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
		$speaker       = get_user_by( 'id', $user_id );
		$feedback_info = array(
			'total_new' => 0,
		);

		foreach ( $session_ids as $session_id ) {
			$associated_feedbacks = array_keys( $post_ids, $session_id );
			$feedback_info['total_new'] += count( $associated_feedbacks );
			$feedback_info[] = array(
				'feedback_ids' => $associated_feedbacks,
				'title'        => get_the_title( $session_id ),
				'count'        => count( $associated_feedbacks ),
				'link'         => trailingslashit( get_permalink( $session_id ) ) . 'feedback',
			);
		}

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
				esc_html__( '%1$s new submissions on %2$s', 'wordcamporg' ),
				$info['count'],
				$info['title']
			);
			$message .= "\n";
			$message .= esc_url_raw( $info['link'] );
			$message .= "\n\n";
		}

		$message .= sprintf(
			// translators: %s is the name of the WordCamp.
			esc_html__( "Sincerely,\nthe %s organizing team", 'wordcamporg' ),
			esc_html( $wordcamp_name )
		);

		$result = wp_mail( $speaker->user_email, $subject, $message );

		if ( $result ) {
			foreach ( $feedback_info['feedback_ids'] as $feedback_id ) {
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
function speaker_notifications_are_enabled() {
	$now       = date_create( 'now', wp_timezone() );
	$stop_date = get_option( 'sft_speaker_notification_stop_date', false );

	if ( false !== $stop_date && $now->getTimestamp() > $stop_date ) {
		return false;
	}

	$first_session_time = get_transient( 'sft_first_session_time' );

	if ( ! $first_session_time ) {
		$earliest_session = get_posts( array(
			'post_type'      => 'wcb_session',
			'post_status'    => 'publish',
			'meta_key'       => '_wcpt_session_time',
			'orderby'        => 'meta_value_num',
			'order'          => 'ASC',
			'posts_per_page' => 1,
		) );

		if ( ! empty( $earliest_session ) ) {
			$first_session_time = absint( $earliest_session[0]->_wcpt_session_time );
		}

		if ( ! $first_session_time ) {
			return false;
		}

		// Session times can change, so this is a transient.
		set_transient( 'sft_first_session_time', $first_session_time, DAY_IN_SECONDS );

		$stop_date = strtotime( '+ 3 months', $first_session_time );
		update_option( 'sft_speaker_notification_stop_date', $stop_date, false );
	}

	return $now->getTimestamp() > $first_session_time;
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
