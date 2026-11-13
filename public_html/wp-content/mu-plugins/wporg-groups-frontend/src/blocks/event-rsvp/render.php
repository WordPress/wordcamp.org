<?php
/**
 * Server-side rendering for the wporg/event-rsvp block.
 *
 * @package WordCamp\Groups\Frontend
 */

use GatherPress\Core\Event\Event;
use GatherPress\Core\Rsvp\Rsvp;

use function WordCamp\Groups\Frontend\RSVP_Labels\get_count_formats;
use function WordCamp\Groups\Frontend\RSVP_Labels\get_count_label;
use function WordCamp\Groups\Frontend\RSVP_Labels\get_modal_title_formats;
use function WordCamp\Groups\Frontend\RSVP_Labels\get_modal_title_label;
use function WordCamp\Groups\Frontend\RSVP_Labels\get_waiting_formats;
use function WordCamp\Groups\Frontend\RSVP_Labels\get_waiting_label;
use function WordCamp\Groups\Frontend\RSVP_Questions\current_user_can_view_answers;
use function WordCamp\Groups\Frontend\RSVP_Questions\get_labelled_answers;
use function WordCamp\Groups\Frontend\RSVP_Questions\get_questions;
use function WordCamp\Groups\Frontend\RSVP_Questions\get_user_answers;

use const WordCamp\Groups\Frontend\RSVP_Questions\MAX_ANSWER_LENGTH;

$event_post_id = ! empty( $block->context['postId'] )
	? (int) $block->context['postId']
	: ( get_the_ID() ?: get_queried_object_id() );

if ( ! $event_post_id || ! post_type_supports( (string) get_post_type( $event_post_id ), 'gatherpress-rsvp' ) ) {
	return;
}

if ( ! is_preview() && 'publish' !== get_post_status( $event_post_id ) ) {
	return;
}

// The roster follows the event's password gate. Unconditional: `preview` is
// a plain query var any visitor can set, so it must not relax this.
if ( post_password_required( $event_post_id ) ) {
	return;
}

$event     = new Event( $event_post_id );
$rsvp      = new Rsvp( $event_post_id );
$is_past   = $event->has_event_past();
$is_login  = is_user_logged_in();
$is_member = $is_login && is_user_member_of_blog();

$responses = $rsvp->responses();
$attending = $responses['attending'] ?? array(
	'records' => array(),
	'count'   => 0,
);
$records   = $attending['records'] ?? array();
$count     = (int) ( $attending['count'] ?? 0 );

$waiting_count = (int) ( $responses['waiting_list']['count'] ?? 0 );
$capacity      = (int) get_post_meta( $event_post_id, 'gatherpress_capacity', true );

$current_status = 'no_status';
if ( $is_login ) {
	$user_rsvp      = $rsvp->get( get_current_user_id() );
	$current_status = $user_rsvp['status'] ?? 'no_status';
}

// The names on the waiting list are for the people running the event, like
// the answers below. Everyone else sees only how many are waiting.
$waiting_records = array();
if ( $waiting_count && current_user_can_view_answers( $event_post_id ) ) {
	$waiting_records = $responses['waiting_list']['records'] ?? array();

	// Queue order, which is the order `Rsvp::check_waiting_list()` promotes in.
	usort( $waiting_records, array( $rsvp, 'sort_by_timestamp' ) );
}

$listed_records = array_merge( $records, $waiting_records );

// Prime the usermeta cache for every attendee in one query — the modal
// below lists all of them (not just the visible avatars), so without this
// each attendee's bio is otherwise a separate DB round trip.
$attendee_user_ids = array_filter( wp_list_pluck( $listed_records, 'userId' ) );
if ( $attendee_user_ids ) {
	update_meta_cache( 'user', $attendee_user_ids );
}

// Custom registration questions. Answers hold things attendees wouldn't post
// publicly (dietary needs, accessibility requirements), so they're only
// rendered for organizers.
$questions        = get_questions( $event_post_id );
$can_view_answers = $questions && current_user_can_view_answers( $event_post_id );
$own_answers      = ( $questions && $is_login ) ? get_user_answers( $event_post_id, get_current_user_id() ) : array();

// Same treatment for the answers, which live in commentmeta on each RSVP.
// GatherPress primes this incidentally while *building* `responses()`, but
// that result is object-cached for 15 minutes — on a cache hit the loop never
// runs and the reads below would be one query per attendee.
if ( $can_view_answers ) {
	$attendee_comment_ids = array_filter( wp_list_pluck( $listed_records, 'commentId' ) );
	if ( $attendee_comment_ids ) {
		update_meta_cache( 'comment', $attendee_comment_ids );
	}
}

$to_attendee = static function ( array $record ) use ( $can_view_answers, $questions ): array {
	$bio = '';
	if ( ! empty( $record['userId'] ) ) {
		$bio = get_the_author_meta( 'description', (int) $record['userId'] );
		$bio = wp_trim_words( $bio, 20, "\u{2026}" );
	}

	return array(
		'userId'  => (int) $record['userId'],
		'name'    => $record['name'] ?? '',
		'photo'   => $record['photo'] ?? '',
		'profile' => $record['profile'] ?? '',
		'bio'     => $bio,
		'answers' => $can_view_answers
			? get_labelled_answers( (int) ( $record['commentId'] ?? 0 ), $questions )
			: array(),
	);
};

$attendees         = array_map( $to_attendee, $records );
$waiting_attendees = array_map( $to_attendee, $waiting_records );

// Show at most $max_avatars avatars; the remaining $overflow_count is
// rendered as a "+N" pill, and the modal lists every attendee. Five avatars
// fit on one line at any card width — more would wrap onto a second row.
$max_avatars      = 5;
$overflow_count   = max( 0, $count - $max_avatars );
$event_title      = get_the_title( $event_post_id );
$is_attending     = 'attending' === $current_status;
$is_waiting       = 'waiting_list' === $current_status;
$has_response     = $is_attending || $is_waiting;
$is_full          = $capacity > 0 && $count >= $capacity;
$has_no_attendees = 0 === $count;
$modal_title      = get_modal_title_label( $count, $event_title );

// Each string is used by the labels array, the SSR state and the markup
// below — keep them in locals so the three can't drift.
$cancel_rsvp        = _x( 'Cancel RSVP', 'button to withdraw attendance from an event', 'wordcamporg' );
$leave_waiting_list = _x( 'Leave waiting list', 'button to give up a place on an event waiting list', 'wordcamporg' );
$attending_label    = "\u{2713} " . __( 'Attending', 'wordcamporg' );
$waiting_label      = __( 'On the waiting list', 'wordcamporg' );
$join_waiting_list  = __( 'Join waiting list', 'wordcamporg' );
$status_attending   = __( 'You are attending this event.', 'wordcamporg' );
$status_waiting     = __( "You're on the waiting list. If a spot opens up, you'll move to attending automatically.", 'wordcamporg' );
$status_none        = __( 'You have not RSVPed to this event.', 'wordcamporg' );

// The SSR side of the `rsvpButtonLabel`, `statusText`, `modalRsvpLabel` and
// `cancelLabel` getters in view.js.
if ( $is_attending ) {
	$rsvp_button_label = $attending_label;
} elseif ( $is_waiting ) {
	$rsvp_button_label = $waiting_label;
} elseif ( $is_full ) {
	$rsvp_button_label = $join_waiting_list;
} else {
	$rsvp_button_label = $is_member ? __( 'RSVP', 'wordcamporg' ) : __( 'Join & RSVP', 'wordcamporg' );
}

if ( $is_attending ) {
	$modal_rsvp_label = $cancel_rsvp;
} elseif ( $is_waiting ) {
	$modal_rsvp_label = $leave_waiting_list;
} else {
	$modal_rsvp_label = $is_full ? $join_waiting_list : __( 'Attend', 'wordcamporg' );
}

if ( $is_attending ) {
	$status_text = $status_attending;
} else {
	$status_text = $is_waiting ? $status_waiting : $status_none;
}

$cancel_label = $is_waiting ? $leave_waiting_list : $cancel_rsvp;

// Both the count line and the formats the view module re-renders it from come
// out of `inc/rsvp-labels.php`, so the wording lives in one place. See
// `getCountParts()` in view.js for the browser-side half of the branching.
$count_label        = get_count_label( $count, $is_attending );
$waiting_line_label = get_waiting_label( $waiting_count, $is_full );

$rsvp_labels = array_merge(
	get_count_formats(),
	get_waiting_formats(),
	get_modal_title_formats(),
	array(
		'loading'                 => "\u{2026}",
		'attending'               => $attending_label,
		'waitingListed'           => $waiting_label,
		'joinWaitingList'         => $join_waiting_list,
		'leaveWaitingList'        => $leave_waiting_list,
		'rsvp'                    => __( 'RSVP', 'wordcamporg' ),
		'joinRsvp'                => __( 'Join & RSVP', 'wordcamporg' ),
		'statusAttending'         => $status_attending,
		'statusWaiting'           => $status_waiting,
		'statusNotAttending'      => $status_none,
		'cancelRsvp'              => $cancel_rsvp,
		'attend'                  => __( 'Attend', 'wordcamporg' ),
		'emptyAttendees'          => __( 'No attendees yet. Be the first to RSVP!', 'wordcamporg' ),
		'rsvpSuccessAttending'    => __( 'You are now attending this event.', 'wordcamporg' ),
		'rsvpSuccessNotAttending' => __( 'Your RSVP has been canceled.', 'wordcamporg' ),
		'rsvpSuccessWaitingList'  => __( 'You have joined the event waiting list.', 'wordcamporg' ),
		'rsvpSuccessLeftWaitingList' => __( 'You left the waiting list.', 'wordcamporg' ),
		'rsvpError'               => __( 'Your RSVP could not be updated. Please try again.', 'wordcamporg' ),
		'missingAnswers'          => __( 'Please answer the required questions.', 'wordcamporg' ),
		'answersSaved'            => __( 'Your answers have been saved.', 'wordcamporg' ),
	)
);

$context = array(
	'postId'            => (int) $event_post_id,
	'isLoggedIn'        => $is_login,
	'isMember'          => $is_member,
	'isPastEvent'       => $is_past,
	'currentUserStatus' => $current_status,
	'attendingCount'    => $count,
	'waitingCount'      => $waiting_count,
	'capacity'          => $capacity,
	'maxAvatars'        => $max_avatars,
	'eventTitle'        => $event_title,
	'loginUrl'          => wp_login_url( get_permalink( $event_post_id ) ),
	'apiBase'           => rest_url( 'gatherpress/v1/event' ),
	// A non-member's RSVP joins the group first; `source` records that it was by RSVPing.
	'joinApi'           => add_query_arg( 'source', 'rsvp', rest_url( 'wporg-groups/v1/members/join' ) ),
	'rsvpApi'           => rest_url( sprintf( 'wporg-groups/v1/event/%d/rsvp', $event_post_id ) ),
	'recurrenceId'      => '',
	'hasQuestions'      => ! empty( $questions ),
	'canViewAnswers'    => $can_view_answers,
	'listsWaiting'      => ! empty( $waiting_records ),
	'modalOpen'         => false,
	'rsvpLoading'       => false,
	'rsvpNotice'        => '',
	'rsvpNoticeSuccess' => false,
	'rsvpNoticeError'   => false,
	'questionsError'    => '',
	'labels'            => $rsvp_labels,
);

// Register server-side state so Interactivity API directives can evaluate
// derived getters during SSR.
wp_interactivity_state(
	'wporg/event-rsvp',
	array(
		'isAttending'       => $is_attending,
		'isNotAttending'    => ! $is_attending,
		'isWaiting'         => $is_waiting,
		'hasResponse'       => $has_response,
		'hasNoResponse'     => ! $has_response,
		'hasNoAttendees'    => $has_no_attendees,
		'countLabel'        => $count_label,
		'waitingLabel'      => $waiting_line_label,
		'hasNoWaitingLabel' => '' === $waiting_line_label,
		'modalTitle'        => $modal_title,
		'isMember'          => $is_member,
		'rsvpButtonLabel'   => $rsvp_button_label,
		'statusText'        => $status_text,
		'modalRsvpLabel'    => $modal_rsvp_label,
		'cancelLabel'       => $cancel_label,
	)
);

// One row of the attendee modal, shared by the attendee and waiting lists.
$print_attendee_row = static function ( array $attendee ): void {
	?>
	<a class="wporg-event-rsvp__attendee" href="<?php echo esc_url( $attendee['profile'] ); ?>" target="_blank" rel="noopener">
		<img
			class="wporg-event-rsvp__attendee-avatar"
			src="<?php echo esc_url( $attendee['photo'] ); ?>"
			alt=""
			width="48"
			height="48"
			loading="lazy"
		/>
		<div class="wporg-event-rsvp__attendee-info">
			<span class="wporg-event-rsvp__attendee-name"><?php echo esc_html( $attendee['name'] ); ?></span>
			<?php if ( ! empty( $attendee['bio'] ) ) : ?>
				<span class="wporg-event-rsvp__attendee-bio"><?php echo esc_html( $attendee['bio'] ); ?></span>
			<?php endif; ?>
			<?php foreach ( $attendee['answers'] as $answer ) : ?>
				<span class="wporg-event-rsvp__attendee-answer">
					<strong><?php echo esc_html( $answer['label'] ); ?>:</strong>
					<?php echo esc_html( $answer['answer'] ); ?>
				</span>
			<?php endforeach; ?>
		</div>
	</a>
	<?php
};

$wrapper_attributes = get_block_wrapper_attributes(
	array(
		'data-wp-interactive' => 'wporg/event-rsvp',
		'data-wp-context'     => wp_json_encode( $context ),
	)
);
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes escapes. ?>>

	<?php if ( ! $is_past ) : ?>
		<div
			class="wp-block-button<?php echo $has_response ? ' is-style-outline' : ''; ?><?php echo $is_waiting ? ' is-waiting' : ''; ?>"
			data-wp-class--is-style-outline="state.hasResponse"
			data-wp-class--is-waiting="state.isWaiting"
		>
			<?php
			/*
			 * `aria-busy="true"` does two things: the theme's custom.css
			 * draws the loading spinner from it, and screen readers use it
			 * to announce the button as busy rather than unavailable.
			 */
			?>
			<button
				type="button"
				class="wp-block-button__link wp-element-button<?php echo $is_attending ? ' is-attending' : ''; ?>"
				data-wp-on--click="actions.handleRsvpButton"
				data-wp-text="state.rsvpButtonLabel"
				data-wp-class--is-attending="state.isAttending"
				data-wp-bind--disabled="context.rsvpLoading"
				data-wp-bind--aria-busy="context.rsvpLoading"
			>
				<?php echo esc_html( $rsvp_button_label ); ?>
			</button>
		</div>
	<?php else : ?>
		<div class="wp-block-button is-style-outline">
			<button type="button" class="wp-block-button__link wp-element-button is-past" disabled>
				<?php esc_html_e( 'Past Event', 'wordcamporg' ); ?>
			</button>
		</div>
	<?php endif; ?>

	<?php if ( ! $is_past && $is_login ) : ?>
		<?php
		/*
		 * Cancelling used to be reachable only by clicking the "Attending"
		 * button, which says what your status is rather than what pressing it
		 * will do, and then finding the action inside the modal it opens
		 * (#2058). Say it on the page instead. Same `toggleRsvp` action the
		 * modal's button calls, so there is one way to withdraw and both
		 * doors lead to it.
		 *
		 * Rendered for every signed-in visitor and hidden until they are
		 * attending or waiting, rather than rendered on demand: the button
		 * above changes status without a reload, so the control has to
		 * already be in the DOM for `state.hasNoResponse` to reveal it.
		 */
		?>
		<button
			type="button"
			class="wporg-event-rsvp__cancel<?php echo $has_response ? '' : ' is-hidden'; ?>"
			data-wp-on--click="actions.toggleRsvp"
			data-wp-text="state.cancelLabel"
			data-wp-class--is-hidden="state.hasNoResponse"
			data-wp-bind--disabled="context.rsvpLoading"
			data-wp-bind--aria-busy="context.rsvpLoading"
		>
			<?php echo esc_html( $cancel_label ); ?>
		</button>
	<?php endif; ?>

	<button
		type="button"
		class="wporg-event-rsvp__summary<?php echo $has_no_attendees ? ' has-no-attendees' : ''; ?>"
		data-wp-class--has-no-attendees="state.hasNoAttendees"
		data-wp-on--click="actions.openModal">

		<span class="wporg-event-rsvp__avatars" aria-hidden="true">
			<?php foreach ( array_slice( $attendees, 0, $max_avatars ) as $attendee ) : ?>
				<img
					class="wporg-event-rsvp__avatar"
					src="<?php echo esc_url( $attendee['photo'] ); ?>"
					alt=""
					width="28"
					height="28"
					loading="lazy"
				/>
			<?php endforeach; ?>
			<?php if ( $overflow_count > 0 ) : ?>
				<span class="wporg-event-rsvp__overflow">+<?php echo (int) $overflow_count; ?></span>
			<?php endif; ?>
		</span>

		<span class="wporg-event-rsvp__count" data-wp-text="state.countLabel">
			<?php echo esc_html( $count_label ); ?>
		</span>

		<?php
		/*
		 * The button's accessible name has to start with the count it shows,
		 * so a voice-control user can say what they see ("click 5 going").
		 * An `aria-label` would replace that text, so what the button does is
		 * added after it as hidden text instead.
		 */
		?>
		<span class="screen-reader-text"><?php esc_html_e( 'View attendees', 'wordcamporg' ); ?></span>
	</button>

	<p
		class="wporg-event-rsvp__waiting<?php echo '' === $waiting_line_label ? ' is-hidden' : ''; ?>"
		data-wp-text="state.waitingLabel"
		data-wp-class--is-hidden="state.hasNoWaitingLabel"
	><?php echo esc_html( $waiting_line_label ); ?></p>

	<p
		class="screen-reader-text wporg-event-rsvp__notice"
		role="status"
		aria-live="polite"
		aria-atomic="true"
		data-wp-text="context.rsvpNotice"
		data-wp-class--screen-reader-text="!context.rsvpNotice"
		data-wp-class--is-success="context.rsvpNoticeSuccess"
		data-wp-class--is-error="context.rsvpNoticeError"
	></p>

	<div
		class="wporg-event-rsvp__modal"
		data-wp-class--is-open="context.modalOpen"
		data-wp-on--click="actions.handleBackdropClick"
		data-wp-on-window--keydown="actions.handleModalKeydown"
		role="dialog"
		aria-modal="true"
		tabindex="-1"
		aria-label="<?php esc_attr_e( 'Event attendees', 'wordcamporg' ); ?>"
	>
		<div class="wporg-event-rsvp__modal-content">
			<div class="wporg-event-rsvp__modal-header">
				<h2 class="wporg-event-rsvp__modal-title" data-wp-text="state.modalTitle">
					<?php
					echo esc_html( $modal_title );
					?>
				</h2>
				<button
					type="button"
					class="wporg-event-rsvp__modal-close"
					data-wp-on--click="actions.closeModal"
					aria-label="<?php esc_attr_e( 'Close', 'wordcamporg' ); ?>"
				>&times;</button>
			</div>

			<?php if ( ! $is_past ) : ?>
				<div class="wporg-event-rsvp__modal-action">
					<?php if ( $is_login ) : ?>
						<p class="wporg-event-rsvp__modal-status" data-wp-text="state.statusText">
							<?php echo esc_html( $status_text ); ?>
						</p>

						<?php if ( $questions ) : ?>
							<?php $error_id = 'wporg-event-rsvp-questions-error-' . $event_post_id; ?>
							<?php // Stays visible while attending: an organizer can add a required question after people have signed up, and correcting an answer shouldn't mean cancelling the RSVP. ?>
							<fieldset class="wporg-event-rsvp__questions">
								<legend class="screen-reader-text">
									<?php esc_html_e( 'Registration questions', 'wordcamporg' ); ?>
								</legend>
								<?php foreach ( $questions as $question ) : ?>
									<?php $field_id = 'wporg-event-rsvp-question-' . $event_post_id . '-' . $question['id']; ?>
									<div class="wporg-event-rsvp__question">
										<label class="wporg-event-rsvp__question-label" for="<?php echo esc_attr( $field_id ); ?>">
											<?php echo esc_html( $question['label'] ); ?>
											<?php if ( $question['required'] ) : ?>
												<span class="wporg-event-rsvp__question-required" aria-hidden="true">*</span>
											<?php endif; ?>
										</label>
										<input
											type="text"
											id="<?php echo esc_attr( $field_id ); ?>"
											class="wporg-event-rsvp__question-input"
											data-question-id="<?php echo esc_attr( $question['id'] ); ?>"
											maxlength="<?php echo (int) MAX_ANSWER_LENGTH; ?>"
											value="<?php echo esc_attr( $own_answers[ $question['id'] ] ?? '' ); ?>"
											aria-describedby="<?php echo esc_attr( $error_id ); ?>"
											<?php echo $question['required'] ? 'required aria-required="true"' : ''; ?>
										/>
									</div>
								<?php endforeach; ?>
								<p
									class="wporg-event-rsvp__questions-error"
									id="<?php echo esc_attr( $error_id ); ?>"
									data-wp-text="context.questionsError"
								></p>
							</fieldset>
						<?php endif; ?>

						<div class="wporg-event-rsvp__modal-buttons">
							<?php if ( $questions ) : ?>
								<button
									type="button"
									class="wporg-event-rsvp__save-answers wp-element-button<?php echo $is_attending ? '' : ' is-hidden'; ?>"
									data-wp-on--click="actions.saveAnswers"
									data-wp-class--is-hidden="state.isNotAttending"
									data-wp-bind--disabled="context.rsvpLoading"
									data-wp-bind--aria-busy="context.rsvpLoading"
								>
									<?php esc_html_e( 'Save answers', 'wordcamporg' ); ?>
								</button>
							<?php endif; ?>

							<button
								type="button"
								class="wporg-event-rsvp__modal-rsvp-btn wp-element-button<?php echo $has_response ? ' is-attending' : ''; ?>"
								data-wp-on--click="actions.toggleRsvp"
								data-wp-text="state.modalRsvpLabel"
								data-wp-class--is-attending="state.hasResponse"
								data-wp-bind--disabled="context.rsvpLoading"
								data-wp-bind--aria-busy="context.rsvpLoading"
							>
								<?php echo esc_html( $modal_rsvp_label ); ?>
							</button>
						</div>
					<?php else : ?>
						<p class="wporg-event-rsvp__modal-status">
							<?php
							printf(
								wp_kses(
									/* translators: %s: login URL */
									__( '<a href="%s">Log in</a> to RSVP to this event.', 'wordcamporg' ),
									array( 'a' => array( 'href' => array() ) )
								),
								esc_url( wp_login_url( get_permalink( $event_post_id ) ) )
							);
							?>
						</p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="wporg-event-rsvp__attendee-list">
				<?php foreach ( $attendees as $attendee ) : ?>
					<?php $print_attendee_row( $attendee ); ?>
				<?php endforeach; ?>
				<?php if ( empty( $attendees ) ) : ?>
					<p class="wporg-event-rsvp__empty"><?php esc_html_e( 'No attendees yet. Be the first to RSVP!', 'wordcamporg' ); ?></p>
				<?php endif; ?>

				<?php // Inside the scrolling list so a long waiting list scrolls with the attendees. Organizers only; see `$waiting_records`. ?>
				<?php if ( $waiting_attendees ) : ?>
					<h3 class="wporg-event-rsvp__waiting-heading">
						<?php
						printf(
							/* translators: %s: number of people on the waiting list. */
							esc_html__( 'Waiting list (%s)', 'wordcamporg' ),
							esc_html( number_format_i18n( $waiting_count ) )
						);
						?>
					</h3>
					<?php foreach ( $waiting_attendees as $attendee ) : ?>
						<?php $print_attendee_row( $attendee ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>
