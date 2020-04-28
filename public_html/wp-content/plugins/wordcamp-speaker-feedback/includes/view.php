<?php

namespace WordCamp\SpeakerFeedback\View;

use WP_Post, WP_Query;
use function WordCamp\SpeakerFeedback\{ get_views_path, get_assets_url, get_assets_path };
use function WordCamp\SpeakerFeedback\Comment\{ count_feedback, get_feedback, get_feedback_comment };
use function WordCamp\SpeakerFeedback\CommentMeta\get_feedback_questions;
use function WordCamp\SpeakerFeedback\Post\{
	get_earliest_session_timestamp, get_latest_session_ending_timestamp,
	get_session_speaker_user_ids, post_accepts_feedback
};
use const WordCamp\SpeakerFeedback\{ OPTION_KEY, QUERY_VAR };
use const WordCamp\SpeakerFeedback\Comment\COMMENT_TYPE;
use const WordCamp\SpeakerFeedback\Post\ACCEPT_INTERVAL_IN_SECONDS;

defined( 'WPINC' ) || die();

add_filter( 'the_content', __NAMESPACE__ . '\render' );
add_filter( 'wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_assets' );

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

	$now = date_create( 'now', wp_timezone() );

	if ( has_feedback_form() ) {
		$session_speakers = get_session_speaker_user_ids( $post->ID );
		if ( in_array( get_current_user_id(), $session_speakers, true ) ) {
			ob_start();

			$query_args = parse_feedback_args();
			$feedback   = get_feedback( array( get_the_ID() ), array( 'approve' ), $query_args );
			$avg_rating = 0;

			if ( count( $feedback ) ) {
				$sum_rating = array_reduce(
					$feedback,
					function( $carry, $item ) {
						$carry += absint( $item->rating );
						return $carry;
					},
					0
				);
				$avg_rating = round( $sum_rating / count( $feedback ) );
			}

			$feedback_count = count_feedback( $post->ID );
			$approved       = absint( $feedback_count['approved'] );
			$moderated      = absint( $feedback_count['moderated'] );

			require get_views_path() . 'view-feedback.php';
		} else {
			$accepts_feedback = post_accepts_feedback( $post->ID );

			ob_start();

			if ( is_wp_error( $accepts_feedback ) ) {
				$message = $accepts_feedback->get_error_message();
				require get_views_path() . 'form-not-available.php';
				return $content . ob_get_clean(); // Append the error message, return early.
			}

			$questions       = get_feedback_questions();
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

		$content = $content . ob_get_clean(); // Append form to the normal content.
	} elseif ( is_page( get_option( OPTION_KEY ) ) ) {
		$wordcamp            = get_wordcamp_post();
		$valid_wcpt_statuses = array( 'wcpt-scheduled', 'wcpt-closed' );
		$start_time          = get_earliest_session_timestamp();
		$end_time            = get_latest_session_ending_timestamp();

		if ( ! $start_time ) {
			// No valid start time, the event probably hasn't been scheduled yet. Use a far future date for now.
			$start_time = $now->getTimestamp() + YEAR_IN_SECONDS;
		}

		if ( ! $end_time ) {
			// No valid end time, assume it's 24 hours later.
			$end_time = $start_time + DAY_IN_SECONDS;
		}

		if (
			// The event either needs to be on the schedule, already occurred, or a test site.
			( ! $wordcamp || ! in_array( $wordcamp->post_status, $valid_wcpt_statuses, true ) )
			&& ! is_wordcamp_test_site()
		) {
			$message = __( 'Feedback forms are not available for this site.', 'wordcamporg' );
			$file    = 'form-not-available.php';
		} elseif ( $now->getTimestamp() < $start_time ) {
			$message = __( 'Feedback forms are not available until the event has started.', 'wordcamporg' );
			$file    = 'form-not-available.php';
		} elseif ( $now->getTimestamp() > $end_time + ACCEPT_INTERVAL_IN_SECONDS ) {
			$message = __( 'Feedback forms are closed for this event.', 'wordcamporg' );
			$file    = 'form-not-available.php';
		} else {
			$file = 'form-select-sessions.php';
		}

		ob_start();
		require get_views_path() . $file;

		$content = ob_get_clean(); // Replace the content.
	}

	return $content;
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
			array(),
			filemtime( dirname( __DIR__ ) . '/assets/build/style.css' )
		);

		wp_enqueue_script(
			'speaker-feedback',
			get_assets_url() . 'js/script.js',
			array( 'wp-api-fetch', 'jquery', 'wp-a11y' ),
			filemtime( dirname( __DIR__ ) . '/assets/js/script.js' ),
			true
		);

		$data = array(
			'messages' => array(
				'submitSuccess'   => __( 'Feedback submitted.', 'wordcamporg' ),
				'markedHelpful'   => __( 'Feedback marked as helpful.', 'wordcamporg' ),
				'unmarkedHelpful' => __( 'Feedback unmarked as helpful.', 'wordcamporg' ),
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
