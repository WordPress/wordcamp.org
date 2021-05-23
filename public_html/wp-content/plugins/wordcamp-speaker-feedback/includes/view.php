<?php

namespace WordCamp\SpeakerFeedback\View;

use WP_Comment, WP_Error, WP_Post, WP_Query;
use WordCamp\SpeakerFeedback\Feedback;
use function WordCamp\SpeakerFeedback\{ get_views_path, get_assets_url, get_assets_path };
use function WordCamp\SpeakerFeedback\Comment\{ maybe_get_cached_feedback_count, get_feedback, get_feedback_comment };
use function WordCamp\SpeakerFeedback\CommentMeta\{ get_feedback_meta_field_schema, get_feedback_questions };
use function WordCamp\SpeakerFeedback\Post\{
	get_earliest_session_timestamp, get_latest_session_ending_timestamp,
	get_session_speaker_user_ids, post_accepts_feedback, get_session_feedback_url
};
use const WordCamp\SpeakerFeedback\{ OPTION_KEY, QUERY_VAR };
use const WordCamp\SpeakerFeedback\Comment\COMMENT_TYPE;
use const WordCamp\SpeakerFeedback\Post\ACCEPT_INTERVAL_IN_SECONDS;

defined( 'WPINC' ) || die();

const SPEAKER_VIEWED_KEY = 'sft-speaker-viewed-feedback';

add_filter( 'the_content', __NAMESPACE__ . '\render' );
add_filter( 'wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_assets' );
add_action( 'sft_speaker_viewed_feedback', __NAMESPACE__ . '\mark_speaker_as_viewed', 10, 2 );

/**
 * Check if the current page should include the feedback form.
 *
 * @global WP_Query $wp_query
 *
 * @return bool
 */
function has_feedback_form() {
	global $wp_query;
	return false !== $wp_query->get( QUERY_VAR, false );
}

/**
 * Check to see if feedback is open for this event site.
 *
 * @return bool|WP_Error
 */
function event_accepts_feedback() {
	$wordcamp            = get_wordcamp_post();
	$valid_wcpt_statuses = array( 'wcpt-scheduled', 'wcpt-closed' ); // Avoid having to load the WordCamp_Loader class.
	$now                 = date_create( 'now', wp_timezone() );
	$start_time          = get_earliest_session_timestamp();
	$end_time            = get_latest_session_ending_timestamp();

	if ( ! $start_time ) {
		// No valid start time, the schedule probably hasn't been published yet.
		// Assume a far future date for now.
		$start_time = $now->getTimestamp() + YEAR_IN_SECONDS;
	}

	if ( ! $end_time ) {
		// No valid end time, assume it's 24 hours after the start time.
		$end_time = $start_time + DAY_IN_SECONDS;
	}

	// Organizers need to be able to test and style feedback forms.
	if ( current_user_can( 'moderate_' . COMMENT_TYPE ) ) {
		return true;
	}

	if ( ! $wordcamp || ! in_array( $wordcamp->post_status, $valid_wcpt_statuses, true ) ) {
		return new WP_Error(
			'speaker_feedback_event_feedback_unavailable',
			__( 'Feedback forms are not available for this site.', 'wordcamporg' )
		);
	}

	if ( $now->getTimestamp() < $start_time ) {
		return new WP_Error(
			'speaker_feedback_event_too_soon',
			__( 'Feedback forms are not available until the event has started.', 'wordcamporg' )
		);
	}

	if ( $now->getTimestamp() > $end_time + ACCEPT_INTERVAL_IN_SECONDS ) {
		return new WP_Error(
			'speaker_feedback_event_too_late',
			__( 'Feedback forms are closed for this event.', 'wordcamporg' )
		);
	}

	return true;
}

/**
 * Short-circuit the content, and output the feedback form (or the session select).
 *
 * This assumes that `wcb_session` is the only post type supporting speaker feedback. This will need to be updated
 * if more post type support is added.
 *
 * @global WP_Post $post
 *
 * @return string
 */
function render( $content ) {
	global $post;
	if ( ! $post instanceof WP_Post ) {
		return $content;
	}

	$now = date_create( 'now', wp_timezone() );
	$form_content = '';

	if ( has_feedback_form() ) {
		$form_content = render_feedback_view();
	} elseif ( is_single() && true === post_accepts_feedback( $post->ID ) ) {
		$html = sprintf(
			wp_kses_post(
				__( 'Did you attend this session? <a href="%s" class="sft-feedback-link">Leave feedback.</a>', 'wordcamporg' )
			),
			get_session_feedback_url( $post->ID ) . '#sft-feedback'
		);

		$form_content = wpautop( $html );
	} elseif ( is_page( get_option( OPTION_KEY ) ) ) {
		$accepts_feedback = event_accepts_feedback();

		if ( is_wp_error( $accepts_feedback ) ) {
			$message = $accepts_feedback->get_error_message();
			$file    = 'form-not-available.php';
		} else {
			$file = 'form-select-sessions.php';
		}

		ob_start();
		require get_views_path() . $file;

		$form_content = ob_get_clean();
	}

	return $content . $form_content;
}

/**
 * Render the content that will be appended to the session when the feedback view is requested.
 *
 * @return string
 */
function render_feedback_view() {
	global $post;

	ob_start();

	// Show the form to everyone except the speaker.
	$session_speakers   = get_session_speaker_user_ids( $post->ID );
	$is_session_speaker = in_array( get_current_user_id(), $session_speakers, true );

	if ( ! $is_session_speaker ) {
		$accepts_feedback = post_accepts_feedback( $post->ID );

		if ( is_wp_error( $accepts_feedback ) ) {
			$message = $accepts_feedback->get_error_message();

			require get_views_path() . 'form-not-available.php';
		} else {
			$questions       = get_feedback_questions();
			$schema          = get_feedback_meta_field_schema();
			$rating_question = $questions['rating'];
			$text_questions  = array_filter( array_map(
				function( $key, $question ) {
					return ( 'q' === $key[0] ) ? array( $key, $question ) : false;
				},
				array_keys( $questions ),
				$questions
			) );

			require get_views_path() . 'form-feedback.php';
		}
	}

	// Only show the approved feedback to the speaker and organizers.
	if ( current_user_can( 'read_post_' . COMMENT_TYPE, $post ) ) {
		if ( $is_session_speaker ) {
			/**
			 * Action: Speaker has viewed their feedback for a session.
			 *
			 * @param int $session_id The session that has feedback that is being viewed.
			 * @param int $user_id    The user ID of the speaker viewing the feedback.
			 */
			do_action( 'sft_speaker_viewed_feedback', $post->ID, get_current_user_id() );
		}

		$query_args = parse_feedback_args();
		$feedback   = get_feedback( array( get_the_ID() ), array( 'approve' ), $query_args );
		$avg_rating = intval( get_feedback_average_rating( $feedback ) );

		$feedback_count = (array) maybe_get_cached_feedback_count( $post->ID );
		$approved       = absint( $feedback_count['approved'] );
		$moderated      = absint( $feedback_count['moderated'] );

		require get_views_path() . 'view-feedback.php';
	}

	return ob_get_clean(); // Append form to the normal content.
}

/**
 * Render a single feedback comment to a human-readable HTML string.
 *
 * @param WP_Comment|Feedback|string|int $comment A comment/feedback object or a comment ID.
 * @param bool                           $echo    Whether to echo the output or return it. Default true.
 *
 * @return string The feedback in a question-answer HTML display (or empty string).
 */
function render_feedback_comment( $comment, $echo = true ) {
	$feedback  = get_feedback_comment( $comment );
	$questions = get_feedback_questions( $feedback->version );
	$output    = '';

	foreach ( $questions as $key => $question ) {
		if ( 'rating' === $key ) {
			continue;
		}

		$answer = $feedback->$key;

		if ( $answer ) {
			$output .= sprintf(
				'<p class="speaker-feedback__question">%s</p><p class="speaker-feedback__answer">%s</p>',
				wp_kses_data( $question ),
				wp_kses_data( $answer )
			);
		}
	}

	if ( ! $echo ) {
		return $output;
	}

	echo $output; // phpcs:ignore -- sanitized above.
}

/**
 * Render the star rating for a given feedback comment.
 *
 * @param WP_Comment|Feedback|string|int $comment A comment/feedback object or a comment ID.
 * @param bool                           $echo    Whether to echo the output or return it. Default true.
 *
 * @return string The feedback rating stars in HTML.
 */
function render_feedback_rating( $comment, $echo = true ) {
	$feedback = get_feedback_comment( $comment );
	$output   = render_rating_stars( $feedback->rating );

	if ( ! $echo ) {
		return $output;
	}

	echo $output; // phpcs:ignore -- sanitized in `render_rating_stars`.
}

/**
 * Render a rating of X out of Y stars. Used by `render_feedback_rating`.
 *
 * @param int $rating    The selected rating value (stars below this will be "filled").
 * @param int $max_stars The total rating value (will render this many stars).
 *
 * @return string The feedback rating stars in HTML.
 */
function render_rating_stars( $rating, $max_stars = 5 ) {
	$star_output = 0;
	$label = sprintf(
		_n( '%d star', '%d stars', $rating, 'wordcamporg' ),
		absint( $rating )
	);

	ob_start();
	?>
	<span role="img" aria-label="<?php echo esc_attr( $label ); ?>" class="speaker-feedback__meta-rating">
		<?php while ( $star_output < $max_stars ) :
			$class = ( $star_output < $rating ) ? 'star__full' : 'star__empty';
			?>
			<span class="star <?php echo esc_attr( $class ); ?>">
				<?php require get_assets_path() . 'svg/star.svg'; ?>
			</span>
			<?php
			$star_output++;
		endwhile; ?>
	</span>
	<?php

	return ob_get_clean();
}

/**
 * Add stylesheet to the form page.
 */
function enqueue_assets() {
	if ( has_feedback_form() || is_page( get_option( OPTION_KEY ) ) ) {
		wp_enqueue_style(
			'speaker-feedback',
			get_assets_url() . 'build/style.css',
			array( 'select2' ),
			filemtime( dirname( __DIR__ ) . '/assets/build/style.css' )
		);

		wp_enqueue_script(
			'speaker-feedback',
			get_assets_url() . 'js/script.js',
			array( 'wp-api-fetch', 'jquery', 'lodash', 'wp-a11y', 'select2' ),
			filemtime( dirname( __DIR__ ) . '/assets/js/script.js' ),
			true
		);

		$data = array(
			'url'      => home_url(),
			'messages' => array(
				'submitSuccess'         => __( 'Feedback submitted.', 'wordcamporg' ),
				'markedHelpful'         => __( 'Feedback marked as helpful.', 'wordcamporg' ),
				'unmarkedHelpful'       => __( 'Feedback unmarked as helpful.', 'wordcamporg' ),
				'notificationsEnabled'  => __( 'Feedback notifications are enabled.', 'wordcamporg' ),
				'notificationsDisabled' => __( 'Feedback notifications are disabled.', 'wordcamporg' ),
				'enableNotifications'   => __( 'Enable', 'wordcamporg' ),
				'disableNotifications'  => __( 'Disable', 'wordcamporg' ),
			),
		);

		wp_add_inline_script(
			'speaker-feedback',
			sprintf(
				'var SpeakerFeedbackData = JSON.parse( decodeURIComponent( \'%s\' ) );',
				rawurlencode( wp_json_encode( $data ) )
			),
			'before'
		);
	}
}

/**
 * Parse the GET args to the feedback list into WP_Comment_Query-friendly format.
 *
 * @return array Sorting & filtering args in WP_Comment_Query format.
 */
function parse_feedback_args() {
	$args = array();

	if ( isset( $_GET['forder'] ) ) {
		switch ( $_GET['forder'] ) {
			case 'newest':
				$args['orderby'] = 'comment_date';
				$args['order']   = 'desc';
				break;
			case 'highest':
				$args['orderby'] = 'meta_value_num';
				$args['meta_key'] = 'rating';
				$args['order'] = 'desc';
				break;
			case 'oldest':
			default:
				$args['orderby'] = 'comment_date';
				$args['order']   = 'asc';
				break;
		}
	}

	if ( isset( $_GET['helpful'] ) && 'yes' === $_GET['helpful'] ) {
		$args['meta_query'] = array(
			array(
				'key'     => 'helpful',
				'value'   => '1',
			),
		);
	}

	return $args;
}

/**
 * Calculate the average rating of a group of feedbacks.
 *
 * @param Feedback[] $feedback  Array of feedback comment objects.
 * @param int        $precision Optional. Number of decimal digits to round to. Default 0.
 *
 * @return float
 */
function get_feedback_average_rating( array $feedback, $precision = 0 ) {
	$count = count( $feedback );
	if ( 0 === $count ) {
		return 0;
	}

	$sum_rating = array_reduce(
		$feedback,
		function( $carry, $item ) {
			$carry += absint( $item->rating );
			return $carry;
		},
		0
	);

	return round( $sum_rating / $count, $precision );
}

/**
 * Add a post meta value when a speaker views their feedback.
 *
 * @param int $session_id
 * @param int $user_id
 *
 * @return void
 */
function mark_speaker_as_viewed( $session_id, $user_id ) {
	$speaker_post_ids = get_post_meta( $session_id, '_wcpt_speaker_id' );

	foreach ( $speaker_post_ids as $speaker_post_id ) {
		if ( intval( get_post_meta( $speaker_post_id, '_wcpt_user_id', true ) ) === $user_id ) {
			update_post_meta( $speaker_post_id, SPEAKER_VIEWED_KEY, true );
			break;
		}
	}
}
