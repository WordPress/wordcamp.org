<?php

namespace WordCamp\SpeakerFeedback\Stats;

use WP_Error;
use WordCamp\SpeakerFeedback\Feedback;
use function WordCamp\SpeakerFeedback\Comment\{ maybe_get_cached_feedback_count, get_feedback };
use function WordCamp\SpeakerFeedback\View\{ event_accepts_feedback, get_feedback_average_rating };
use const WordCamp\SpeakerFeedback\Spam\DELETED_SPAM_KEY;
use const WordCamp\SpeakerFeedback\View\SPEAKER_VIEWED_KEY;

defined( 'WPINC' ) || die();

/**
 * Should stats be generated for this event yet?
 *
 * Stats shouldn't be generated if the event hasn't started yet.
 *
 * @return bool
 */
function should_generate_stats() {
	$event_check = event_accepts_feedback();

	if ( is_wp_error( $event_check ) && 'speaker_feedback_event_too_late' !== $event_check->get_error_code() ) {
		return false;
	}

	return true;
}

/**
 * Collect data used to generate the stats.
 *
 * @return array
 */
function gather_data() {
	$data = array();

	$data['session_posts'] = get_posts( array(
		'post_type'      => 'wcb_session',
		'post_status'    => 'publish',
		'posts_per_page' => - 1,
		// get only sessions, no breaks.
		'meta_query'     => array(
			array(
				'key'   => '_wcpt_session_type',
				'value' => 'session',
			),
		),
	) );

	$data['speaker_posts'] = get_posts( array(
		'post_type'      => 'wcb_speaker',
		'post_status'    => 'publish',
		'posts_per_page' => - 1,
	) );

	$data['attendee_posts'] = get_posts( array(
		'post_type'      => 'tix_attendee',
		'post_status'    => 'publish',
		'posts_per_page' => - 1,
	) );

	$data['feedback_counts']        = maybe_get_cached_feedback_count();
	$data['feedback_approved']      = get_feedback( array(), array( 'approve' ) );
	$data['feedback_inappropriate'] = get_feedback( array(), array( 'inappropriate' ) );

	$data['feedback_spam_deleted_count'] = get_option( DELETED_SPAM_KEY, 0 );

	return $data;
}

/**
 * Generate stats based on the given data.
 *
 * To add a new stat, add a key for it in the array, and then create a corresponding `calculate_{$key_name}` function.
 *
 * The stats are handled within a `while` loop so that we don't have to manage a specific order that they must be
 * calculated in, since some stats depend on the values of other stats.
 *
 * @param array $data
 *
 * @return array
 */
function generate_stats( $data ) {
	$stats = array();

	$stat_keys = array(
		'total_feedback',
		'total_feedback_approved',
		'total_feedback_helpful',
		'total_feedback_inappropriate',
		'total_feedback_pending',
		'total_feedback_spam',
		'total_sessions',
		'total_sessions_with_feedback_approved',
		'total_speakers',
		'total_speakers_viewed_feedback',
		'total_tickets',
		'total_tickets_attended',
		'total_unique_feedback_authors',

		'average_feedback_approved_per_ticket',
		'average_feedback_approved_per_ticket_attended',
		'average_feedback_approved_per_session',
		'average_feedback_approved_rating',
		'average_feedback_helpful_per_session',

		'percent_feedback_approved',
		'percent_feedback_approved_helpful',
		'percent_feedback_inappropriate',
		'percent_feedback_spam',
		'percent_sessions_with_feedback_approved',
		'percent_speakers_viewed_feedback',

		'most_feedback_by_author',
		'most_feedback_helpful_by_author',
		'most_feedback_inappropriate_by_author',
		'most_feedback_approved_for_session',
	);

	while ( ! empty( $stat_keys ) ) {
		$previous_stat_keys = $stat_keys;

		foreach ( $stat_keys as $index => $stat_key ) {
			$function = __NAMESPACE__ . '\calculate_' . $stat_key;

			$stat_value = call_user_func( $function, $data, $stats );

			if ( is_wp_error( $stat_value ) ) {
				continue;
			}

			$stats[ $stat_key ] = $stat_value;
			unset( $stat_keys[ $index ] );
		}

		if ( $stat_keys === $previous_stat_keys ) {
			// Bail, no more stats can be calculated at this point.
			$stats['error'] = array(
				'message' => 'Some stats could not be calculated.',
				'data'    => $stat_keys,
			);
			break;
		}
	}

	return $stats;
}

/**
 * Calculate the total number of feedback comments, excluding ones in the trash.
 *
 * @param array $data
 *
 * @return int|WP_Error
 */
function calculate_total_feedback( array $data ) {
	if ( ! isset( $data['feedback_counts'] ) ) {
		return new WP_Error();
	}

	return intval( $data['feedback_counts']->total_comments );
}

/**
 * Calculate the total number of approved feedback comments.
 *
 * @param array $data
 *
 * @return int|WP_Error
 */
function calculate_total_feedback_approved( array $data ) {
	if ( ! isset( $data['feedback_counts'] ) ) {
		return new WP_Error();
	}

	return intval( $data['feedback_counts']->approved );
}

/**
 * Calculate the total number of approved feedback comments that have been marked as helpful.
 *
 * @param array $data
 *
 * @return int|WP_Error
 */
function calculate_total_feedback_helpful( array $data ) {
	if ( ! isset( $data['feedback_approved'] ) ) {
		return new WP_Error();
	}

	$helpful_feedback = array_filter(
		$data['feedback_approved'],
		function( $item ) {
			return $item->helpful;
		}
	);

	return count( $helpful_feedback );
}

/**
 * Calculate the total number of inappropriate feedback comments.
 *
 * @param array $data
 *
 * @return int|WP_Error
 */
function calculate_total_feedback_inappropriate( array $data ) {
	if ( ! isset( $data['feedback_counts'] ) ) {
		return new WP_Error();
	}

	return intval( $data['feedback_counts']->inappropriate );
}

/**
 * Calculate the total number of pending/moderated feedback comments.
 *
 * @param array $data
 *
 * @return int|WP_Error
 */
function calculate_total_feedback_pending( array $data ) {
	if ( ! isset( $data['feedback_counts'] ) ) {
		return new WP_Error();
	}

	return intval( $data['feedback_counts']->moderated );
}

/**
 * Calculate the total number of spam feedback comments, including ones that have already been auto-deleted.
 *
 * @param array $data
 *
 * @return bool|mixed|void|WP_Error
 */
function calculate_total_feedback_spam( array $data ) {
	if ( ! isset( $data['feedback_counts'], $data['feedback_spam_deleted_count'] ) ) {
		return new WP_Error();
	}

	return intval( $data['feedback_counts']->spam + $data['feedback_spam_deleted_count'] );
}

/**
 * Calculate the total number of published session posts.
 *
 * @param array $data
 *
 * @return int|WP_Error
 */
function calculate_total_sessions( array $data ) {
	if ( ! isset( $data['session_posts'] ) ) {
		return new WP_Error();
	}

	return count( $data['session_posts'] );
}

/**
 * Calculate the total number of published session posts.
 *
 * @param array $data
 *
 * @return int|WP_Error
 */
function calculate_total_sessions_with_feedback_approved( array $data ) {
	if ( ! isset( $data['feedback_approved'] ) ) {
		return new WP_Error();
	}

	$sessions_with_feedback = get_feedback_session_counts( $data['feedback_approved'] );

	return count( $sessions_with_feedback );
}

/**
 * Calculate the total number of published speaker posts.
 *
 * @param array $data
 *
 * @return int|WP_Error
 */
function calculate_total_speakers( array $data ) {
	if ( ! isset( $data['speaker_posts'] ) ) {
		return new WP_Error();
	}

	return count( $data['speaker_posts'] );
}

/**
 * Calculate the total number of speakers who viewed their feedback.
 *
 * @param array $data
 *
 * @return int|WP_Error
 */
function calculate_total_speakers_viewed_feedback( array $data ) {
	if ( ! isset( $data['speaker_posts'] ) ) {
		return new WP_Error();
	}

	$viewership = wp_list_pluck( $data['attendee_posts'], SPEAKER_VIEWED_KEY );
	$viewed     = array_filter( $viewership );

	return count( $viewed );
}

/**
 * Calculate the total number of published attendee posts (tickets issued).
 *
 * @param array $data
 *
 * @return int|WP_Error
 */
function calculate_total_tickets( array $data ) {
	if ( ! isset( $data['attendee_posts'] ) ) {
		return new WP_Error();
	}

	return count( $data['attendee_posts'] );
}

/**
 * Calculate the total number of tickets issued that have been marked as "attended".
 *
 * @param array $data
 *
 * @return int|WP_Error
 */
function calculate_total_tickets_attended( array $data ) {
	if ( ! isset( $data['attendee_posts'] ) ) {
		return new WP_Error();
	}

	$attendance = wp_list_pluck( $data['attendee_posts'], 'tix_attended' );
	$attended   = array_filter( $attendance );

	return count( $attended );
}

/**
 * Calculate the total number of unique feedback authors (excluding spammed and trashed feedback),
 * based on their email address.
 *
 * @param array $data
 *
 * @return int|WP_Error
 */
function calculate_total_unique_feedback_authors( array $data ) {
	if ( ! isset( $data['feedback_approved'], $data['feedback_inappropriate'] ) ) {
		return new WP_Error();
	}

	$all_reviewed_feedback = array_merge( $data['feedback_approved'], $data['feedback_inappropriate'] );

	$author_counts = get_feedback_author_counts( $all_reviewed_feedback );
	$authors       = array_keys( $author_counts );

	return count( $authors );
}

/**
 * Calculate the amount of approved feedback per issued ticket.
 *
 * @param array $data  Unused.
 * @param array $stats
 *
 * @return float|WP_Error
 */
function calculate_average_feedback_approved_per_ticket( array $data, array $stats ) {
	if ( ! isset( $stats['total_feedback_approved'], $stats['total_tickets'] ) ) {
		return new WP_Error();
	}

	if ( $stats['total_tickets'] < 1 ) {
		// Avoid dividing by 0.
		return floatval( 0 );
	}

	return round( $stats['total_feedback_approved'] / $stats['total_tickets'], 1 );
}

/**
 * Calculate the amount of approved feedback per attended ticket.
 *
 * @param array $data  Unused.
 * @param array $stats
 *
 * @return float|WP_Error
 */
function calculate_average_feedback_approved_per_ticket_attended( array $data, array $stats ) {
	if ( ! isset( $stats['total_feedback_approved'], $stats['total_tickets_attended'] ) ) {
		return new WP_Error();
	}

	if ( $stats['total_tickets_attended'] < 1 ) {
		// Avoid dividing by 0.
		return floatval( 0 );
	}

	return round( $stats['total_feedback_approved'] / $stats['total_tickets_attended'], 1 );
}

/**
 * Calculate the amount of approved feedback per event session.
 *
 * @param array $data  Unused.
 * @param array $stats
 *
 * @return float|WP_Error
 */
function calculate_average_feedback_approved_per_session( array $data, array $stats ) {
	if ( ! isset( $stats['total_feedback_approved'], $stats['total_sessions'] ) ) {
		return new WP_Error();
	}

	return round( $stats['total_feedback_approved'] / $stats['total_sessions'], 1 );
}

/**
 * Calculate the average rating of all approved feedback.
 *
 * @param array $data
 *
 * @return int|WP_Error
 */
function calculate_average_feedback_approved_rating( array $data ) {
	if ( ! isset( $data['feedback_approved'] ) ) {
		return new WP_Error();
	}

	return get_feedback_average_rating( $data['feedback_approved'] );
}

/**
 * Calculate the amount of feedback marked as helpful per event session.
 *
 * @param array $data  Unused.
 * @param array $stats
 *
 * @return float|WP_Error
 */
function calculate_average_feedback_helpful_per_session( array $data, array $stats ) {
	if ( ! isset( $stats['total_feedback_helpful'], $stats['total_sessions'] ) ) {
		return new WP_Error();
	}

	return round( $stats['total_feedback_helpful'] / $stats['total_sessions'], 1 );
}

/**
 * Calculate the percentage of total feedback that has been approved.
 *
 * @param array $data  Unused.
 * @param array $stats
 *
 * @return float|WP_Error
 */
function calculate_percent_feedback_approved( array $data, array $stats ) {
	if ( ! isset( $stats['total_feedback'], $stats['total_feedback_approved'] ) ) {
		return new WP_Error();
	}

	return round( 100 * $stats['total_feedback_approved'] / $stats['total_feedback'], 1 );
}

/**
 * Calculate the percentage of total feedback that has been marked as helpful.
 *
 * @param array $data  Unused.
 * @param array $stats
 *
 * @return float|WP_Error
 */
function calculate_percent_feedback_approved_helpful( array $data, array $stats ) {
	if ( ! isset( $stats['total_feedback_approved'], $stats['total_feedback_helpful'] ) ) {
		return new WP_Error();
	}

	return round( 100 * $stats['total_feedback_helpful'] / $stats['total_feedback_approved'], 1 );
}

/**
 * Calculate the percentage of total feedback that has been marked as inappropriate.
 *
 * @param array $data  Unused.
 * @param array $stats
 *
 * @return float|WP_Error
 */
function calculate_percent_feedback_inappropriate( array $data, array $stats ) {
	if ( ! isset( $stats['total_feedback'], $stats['total_feedback_inappropriate'] ) ) {
		return new WP_Error();
	}

	return round( 100 * $stats['total_feedback_inappropriate'] / $stats['total_feedback'], 1 );
}

/**
 * Calculate the percentage of total feedback that has been marked as spam.
 *
 * @param array $data  Unused.
 * @param array $stats
 *
 * @return float|WP_Error
 */
function calculate_percent_feedback_spam( array $data, array $stats ) {
	if ( ! isset( $stats['total_feedback'], $stats['total_feedback_spam'] ) ) {
		return new WP_Error();
	}

	return round( 100 * $stats['total_feedback_spam'] / $stats['total_feedback'], 1 );
}

/**
 * Calculate the percentage of sessions that have approved feedback.
 *
 * @param array $data  Unused.
 * @param array $stats
 *
 * @return float|WP_Error
 */
function calculate_percent_sessions_with_feedback_approved( array $data, array $stats ) {
	if ( ! isset( $stats['total_sessions'], $stats['total_sessions_with_feedback_approved'] ) ) {
		return new WP_Error();
	}

	return round( 100 * $stats['total_sessions_with_feedback_approved'] / $stats['total_sessions'], 1 );
}

/**
 * Calculate the percentage of speakers who viewed their feedback.
 *
 * @param array $data  Unused.
 * @param array $stats
 *
 * @return float|WP_Error
 */
function calculate_percent_speakers_viewed_feedback( array $data, array $stats ) {
	if ( ! isset( $stats['total_speakers'], $stats['total_speakers_viewed_feedback'] ) ) {
		return new WP_Error();
	}

	return round( 100 * $stats['total_speakers_viewed_feedback'] / $stats['total_speakers'], 1 );
}

/**
 * Calculate which feedback author(s) submitted the most comments, excluding spammed and trashed comments.
 *
 * @param array $data
 *
 * @return array|WP_Error
 */
function calculate_most_feedback_by_author( array $data ) {
	if ( ! isset( $data['feedback_approved'], $data['feedback_inappropriate'] ) ) {
		return new WP_Error();
	}

	$all_reviewed_feedback = array_merge( $data['feedback_approved'], $data['feedback_inappropriate'] );

	$author_counts = get_feedback_author_counts( $all_reviewed_feedback );

	reset( $author_counts );
	$highest_count = current( $author_counts );

	return array(
		'author' => array_keys( $author_counts, $highest_count, true ),
		'number' => $highest_count,
	);
}

/**
 * Calculate which feedback author(s) submitted the most comments that were marked as helpful.
 *
 * @param array $data
 *
 * @return array|WP_Error
 */
function calculate_most_feedback_helpful_by_author( array $data ) {
	if ( ! isset( $data['feedback_approved'] ) ) {
		return new WP_Error();
	}

	$helpful_feedback = array_filter(
		$data['feedback_approved'],
		function( $item ) {
			return $item->helpful;
		}
	);

	$author_counts = get_feedback_author_counts( $helpful_feedback );

	reset( $author_counts );
	$highest_count = current( $author_counts );

	return array(
		'author' => array_keys( $author_counts, $highest_count, true ),
		'number' => $highest_count,
	);
}

/**
 * Calculate which feedback author(s) submitted the most comments that were marked as inappropriate.
 *
 * @param array $data
 *
 * @return array|WP_Error
 */
function calculate_most_feedback_inappropriate_by_author( array $data ) {
	if ( ! isset( $data['feedback_inappropriate'] ) ) {
		return new WP_Error();
	}

	$author_counts = get_feedback_author_counts( $data['feedback_inappropriate'] );

	reset( $author_counts );
	$highest_count = current( $author_counts );

	return array(
		'author' => array_keys( $author_counts, $highest_count, true ),
		'number' => $highest_count,
	);
}

/**
 * Calculate which session(s) received the most feedback comments, excluding spammed and trashed comments.
 *
 * @param array $data
 *
 * @return array|WP_Error
 */
function calculate_most_feedback_approved_for_session( array $data ) {
	if ( ! isset( $data['feedback_approved'], $data['feedback_inappropriate'] ) ) {
		return new WP_Error();
	}

	$all_reviewed_feedback = array_merge( $data['feedback_approved'], $data['feedback_inappropriate'] );

	$session_counts = get_feedback_session_counts( $all_reviewed_feedback );

	reset( $session_counts );
	$highest_count = current( $session_counts );

	return array(
		'session' => array_map( 'intval', array_keys( $session_counts, $highest_count, true ) ),
		'number'  => $highest_count,
	);
}

/**
 * List the number of feedback comments for each author, by email address, sorted high to low.
 *
 * @param Feedback[] $feedback
 *
 * @return array
 */
function get_feedback_author_counts( $feedback ) {
	$counts = array_reduce(
		$feedback,
		function( $carry, $item ) {
			$author_email = $item->comment_author_email;
			if ( ! isset( $carry[ $author_email ] ) ) {
				$carry[ $author_email ] = 0;
			}
			$carry[ $author_email ] ++;

			return $carry;
		},
		array()
	);

	arsort( $counts );

	return $counts;
}

/**
 * List the number of feedback comments for each session, by post ID, sorted high to low.
 *
 * Note that the post IDs are cast as strings in the array keys so that PHP will treat the array
 * as associative instead of numeric.
 *
 * @param Feedback[] $feedback
 *
 * @return array
 */
function get_feedback_session_counts( $feedback ) {
	$counts = array_reduce(
		$feedback,
		function( $carry, $item ) {
			$post_id = (string) $item->comment_post_ID; // Convert to string to retain array keys.
			if ( ! isset( $carry[ $post_id ] ) ) {
				$carry[ $post_id ] = 0;
			}
			$carry[ $post_id ] ++;

			return $carry;
		},
		array()
	);

	arsort( $counts );

	return $counts;
}
