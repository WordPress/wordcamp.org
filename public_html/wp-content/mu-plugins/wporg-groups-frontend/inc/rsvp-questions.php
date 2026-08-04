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
 * @param array $questions Sanitized question list.
 * @param mixed $raw       Raw `question id => answer` map from the request.
 * @return array Sanitized `question id => answer` map, blanks omitted.
 */
function sanitize_answers( array $questions, $raw ): array {
	if ( ! is_array( $raw ) ) {
		return array();
	}

	$answers = array();

	foreach ( $questions as $question ) {
		$value = sanitize_text_field( (string) ( $raw[ $question['id'] ] ?? '' ) );
		$value = trim( $value );

		if ( '' === $value ) {
			continue;
		}

		$answers[ $question['id'] ] = mb_substr( $value, 0, MAX_ANSWER_LENGTH );
	}

	return $answers;
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
		if ( $question['required'] && empty( $answers[ $question['id'] ] ) ) {
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
	if ( empty( $answers ) ) {
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
		if ( empty( $answers[ $question['id'] ] ) ) {
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
