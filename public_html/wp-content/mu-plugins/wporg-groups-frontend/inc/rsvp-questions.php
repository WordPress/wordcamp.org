<?php
/**
 * Per-event custom registration questions.
 *
 * Organizers define a short list of questions on an event; attendees answer
 * them when they RSVP. Deliberately minimal for a first pass — every question
 * is a single-line text field, and the count is capped so this stays a couple
 * of extra questions at signup rather than a form builder.
 *
 * Storage:
 *
 *   - **Questions** live in one `wporg_groups_rsvp_questions` post meta on the
 *     event: a list of `array( 'id', 'label', 'required' )`.
 *   - **Answers** live in one `wporg_groups_rsvp_answers` comment meta on the
 *     GatherPress RSVP comment, keyed by question id. Keeping them on the
 *     comment means cancelling an RSVP takes the answers with it, and no
 *     orphaned answers survive the RSVP they belong to.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\RSVP_Questions;

defined( 'WPINC' ) || die();

use GatherPress\Core\Rsvp\Rsvp;

const QUESTIONS_META = 'wporg_groups_rsvp_questions';
const ANSWERS_META   = 'wporg_groups_rsvp_answers';

/**
 * Hard cap on questions per event. Meetup.com's own profile questions cap at
 * three; five leaves room for the common set (company, dietary needs, t-shirt
 * size) without turning the RSVP into a form.
 */
const MAX_QUESTIONS = 5;

/**
 * Cap on a single answer's length. Long enough for a sentence or two, short
 * enough that the attendee list stays readable.
 */
const MAX_ANSWER_LENGTH = 500;

/**
 * Hook the guard on GatherPress's own RSVP route.
 */
function bootstrap(): void {
	add_filter( 'rest_endpoints', __NAMESPACE__ . '\guard_gatherpress_rsvp_route' );
}

/**
 * Stop GatherPress's `/gatherpress/v1/event/rsvp` route being used to attend an
 * event while skipping its required questions.
 *
 * Our own `wporg-groups/v1/event/{id}/rsvp` validates the answers, but
 * GatherPress's route stays registered and accepts any logged-in member, so
 * without this the "required" flag is only a front-end suggestion.
 *
 * The permission callback is wrapped rather than replaced, so GatherPress's own
 * token/membership checks still run. Only the case that can produce bad data is
 * refused — attending an event with unanswered required questions. Cancelling,
 * events without questions, and members whose stored answers already satisfy
 * the questions all pass through untouched.
 *
 * @param array $endpoints Registered REST endpoints.
 * @return array Filtered endpoints.
 */
function guard_gatherpress_rsvp_route( array $endpoints ): array {
	$route = '/gatherpress/v1/event/rsvp';

	if ( empty( $endpoints[ $route ] ) ) {
		return $endpoints;
	}

	foreach ( $endpoints[ $route ] as $index => $handler ) {
		if ( ! isset( $handler['permission_callback'] ) ) {
			continue;
		}

		$original = $handler['permission_callback'];

		$endpoints[ $route ][ $index ]['permission_callback'] = static function ( $request ) use ( $original ) {
			$allowed = call_user_func( $original, $request );

			if ( true !== $allowed ) {
				return $allowed;
			}

			return rsvp_answers_satisfied( $request );
		};
	}

	return $endpoints;
}

/**
 * Whether a GatherPress RSVP request would leave required questions unanswered.
 *
 * @param \WP_REST_Request $request The RSVP request.
 * @return true|\WP_Error True when the request is fine to proceed.
 */
function rsvp_answers_satisfied( $request ) {
	if ( 'attending' !== sanitize_key( (string) $request->get_param( 'status' ) ) ) {
		return true;
	}

	$post_id   = (int) $request->get_param( 'post_id' );
	$questions = $post_id ? get_questions( $post_id ) : array();

	if ( ! $questions ) {
		return true;
	}

	$user_id = (int) $request->get_param( 'user_id' );
	$user_id = $user_id ? $user_id : get_current_user_id();
	$missing = get_missing_required( $questions, get_user_answers( $post_id, $user_id ) );

	if ( ! $missing ) {
		return true;
	}

	return new \WP_Error(
		'wporg_groups_missing_answers',
		sprintf(
			/* translators: %s: comma-separated list of question labels. */
			__( 'Please answer: %s', 'wporg-groups-frontend' ),
			implode( ', ', $missing )
		),
		array( 'status' => 400 )
	);
}

/**
 * The questions defined on an event, in display order.
 *
 * @param int $event_id Event post ID.
 * @return array List of `array{id:string, label:string, required:bool}`.
 */
function get_questions( int $event_id ): array {
	$stored = get_post_meta( $event_id, QUESTIONS_META, true );

	return is_array( $stored ) ? sanitize_questions( $stored ) : array();
}

/**
 * Persist an event's questions, replacing whatever was there before.
 *
 * @param int   $event_id Event post ID.
 * @param mixed $raw      Raw question list from the request.
 */
function save_questions( int $event_id, $raw ): void {
	$questions = sanitize_questions( $raw );

	if ( empty( $questions ) ) {
		delete_post_meta( $event_id, QUESTIONS_META );
		return;
	}

	update_post_meta( $event_id, QUESTIONS_META, $questions );
}

/**
 * Normalise a raw question list: drop unlabelled entries, assign ids to new
 * ones, and enforce `MAX_QUESTIONS`.
 *
 * @param mixed $raw Raw question list.
 * @return array Sanitized questions.
 */
function sanitize_questions( $raw ): array {
	if ( ! is_array( $raw ) ) {
		return array();
	}

	$questions = array();
	$used_ids  = array();

	foreach ( $raw as $question ) {
		if ( ! is_array( $question ) ) {
			continue;
		}

		$label = sanitize_text_field( (string) ( $question['label'] ?? '' ) );
		if ( '' === trim( $label ) ) {
			continue;
		}

		$id = sanitize_key( (string) ( $question['id'] ?? '' ) );
		if ( '' === $id || isset( $used_ids[ $id ] ) ) {
			$id = next_question_id( $used_ids );
		}
		$used_ids[ $id ] = true;

		$questions[] = array(
			'id'       => $id,
			'label'    => $label,
			'required' => ! empty( $question['required'] ),
		);

		if ( count( $questions ) >= MAX_QUESTIONS ) {
			break;
		}
	}

	return $questions;
}

/**
 * Mint an id for a question the organizer just added.
 *
 * Random rather than sequential on purpose: answers are stored against the
 * question id, so an id must never be reused. A counter would hand `q1` back
 * out after the first question was deleted, and the previous attendees'
 * answers to the old `q1` would resurface under the new question's label.
 *
 * @param array $used_ids Map of ids already taken in this set.
 */
function next_question_id( array $used_ids ): string {
	do {
		$id = 'q' . substr( md5( uniqid( '', true ) ), 0, 8 );
	} while ( isset( $used_ids[ $id ] ) );

	return $id;
}

/**
 * Filter a raw answer map down to the questions the event actually asks.
 *
 * A key that is **present but blank** is kept as `''` — that's how the caller
 * tells "the attendee cleared this answer" apart from "this question wasn't
 * submitted at all", which `merge_answers()` then treats differently. Keys the
 * request didn't mention are dropped entirely.
 *
 * @param array $questions Sanitized question list.
 * @param mixed $raw       Raw `question id => answer` map from the request.
 * @return array Sanitized `question id => answer` map of submitted keys only.
 */
function sanitize_answers( array $questions, $raw ): array {
	if ( ! is_array( $raw ) ) {
		return array();
	}

	$answers = array();

	foreach ( $questions as $question ) {
		if ( ! array_key_exists( $question['id'], $raw ) ) {
			continue;
		}

		$value = trim( sanitize_text_field( (string) $raw[ $question['id'] ] ) );

		$answers[ $question['id'] ] = mb_substr( $value, 0, MAX_ANSWER_LENGTH );
	}

	return $answers;
}

/**
 * Apply a submitted answer map on top of what's already stored.
 *
 * Only the questions the request actually mentioned are touched, so a client
 * that renders a stale form — or any caller that omits the field — can't wipe
 * answers it never showed. A submitted blank is an explicit clear.
 *
 * @param array $stored    Currently stored `question id => answer` map.
 * @param array $submitted Sanitized submitted map (blanks preserved).
 * @return array The resulting map.
 */
function merge_answers( array $stored, array $submitted ): array {
	foreach ( $submitted as $id => $value ) {
		if ( '' === $value ) {
			unset( $stored[ $id ] );
			continue;
		}

		$stored[ $id ] = $value;
	}

	return $stored;
}

/**
 * Whether a question has an answer.
 *
 * Deliberately not `empty()`: an answer of `"0"` is a real answer (a count, a
 * guest number, a size), and `empty( '0' )` is true in PHP while the browser
 * treats the same string as filled in — so the two ends would disagree about
 * whether a required question had been answered.
 *
 * @param array  $answers Answer map.
 * @param string $id      Question ID.
 */
function has_answer( array $answers, string $id ): bool {
	return isset( $answers[ $id ] ) && '' !== $answers[ $id ];
}

/**
 * Labels of the required questions left unanswered.
 *
 * @param array $questions Sanitized question list.
 * @param array $answers   Sanitized answer map.
 * @return string[] Labels of the missing answers, in question order.
 */
function get_missing_required( array $questions, array $answers ): array {
	$missing = array();

	foreach ( $questions as $question ) {
		if ( $question['required'] && ! has_answer( $answers, $question['id'] ) ) {
			$missing[] = $question['label'];
		}
	}

	return $missing;
}

/**
 * Store the answers on an RSVP, or clear them when there are none.
 *
 * @param int   $comment_id RSVP comment ID.
 * @param array $answers    Sanitized answer map.
 */
function save_answers( int $comment_id, array $answers ): void {
	if ( ! $answers ) {
		delete_comment_meta( $comment_id, ANSWERS_META );
		return;
	}

	update_comment_meta( $comment_id, ANSWERS_META, $answers );
}

/**
 * The answers stored on a single RSVP.
 *
 * @param int $comment_id RSVP comment ID.
 * @return array `question id => answer` map.
 */
function get_answers( int $comment_id ): array {
	$stored = get_comment_meta( $comment_id, ANSWERS_META, true );

	return is_array( $stored ) ? $stored : array();
}

/**
 * An RSVP's answers paired with the question labels, ready to display.
 *
 * Questions the attendee skipped, and answers to questions the organizer has
 * since deleted, are both left out.
 *
 * @param int   $comment_id RSVP comment ID.
 * @param array $questions  Sanitized question list for the event.
 * @return array List of `array{label:string, answer:string}`.
 */
function get_labelled_answers( int $comment_id, array $questions ): array {
	$answers  = get_answers( $comment_id );
	$labelled = array();

	foreach ( $questions as $question ) {
		if ( ! has_answer( $answers, $question['id'] ) ) {
			continue;
		}

		$labelled[] = array(
			'label'  => $question['label'],
			'answer' => (string) $answers[ $question['id'] ],
		);
	}

	return $labelled;
}

/**
 * Whether the current user may see other attendees' answers.
 *
 * Answers can hold things attendees would not post publicly (dietary needs,
 * accessibility requirements), so they're limited to the people who need them
 * to run the event: whoever can edit it.
 *
 * @param int $event_id Event post ID.
 */
function current_user_can_view_answers( int $event_id ): bool {
	return current_user_can( 'edit_post', $event_id );
}

/**
 * The current user's own answers for an event, for prefilling the RSVP form.
 *
 * @param int $event_id Event post ID.
 * @param int $user_id  User ID.
 * @return array `question id => answer` map.
 */
function get_user_answers( int $event_id, int $user_id ): array {
	if ( ! $user_id || ! class_exists( Rsvp::class ) ) {
		return array();
	}

	$rsvp       = ( new Rsvp( $event_id ) )->get( $user_id );
	$comment_id = (int) ( $rsvp['comment_id'] ?? 0 );

	return $comment_id ? get_answers( $comment_id ) : array();
}
