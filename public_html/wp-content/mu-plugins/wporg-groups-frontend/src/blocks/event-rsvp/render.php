<?php
/**
 * Server-side rendering for the wporg/event-rsvp block.
 *
 * @package WordCamp\Groups\Frontend
 */

use GatherPress\Core\Event\Event;
use GatherPress\Core\Rsvp\Rsvp;

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

$event    = new Event( $event_post_id );
$rsvp     = new Rsvp( $event_post_id );
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

$current_status = 'no_status';
if ( $is_login ) {
	$user_rsvp      = $rsvp->get( get_current_user_id() );
	$current_status = $user_rsvp['status'] ?? 'no_status';
}

// Prime the usermeta cache for every attendee in one query — the modal
// below lists all of them (not just the visible avatars), so without this
// each attendee's bio is otherwise a separate DB round trip.
$attendee_user_ids = array_filter( wp_list_pluck( $records, 'userId' ) );
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
	$attendee_comment_ids = array_filter( wp_list_pluck( $records, 'commentId' ) );
	if ( $attendee_comment_ids ) {
		update_meta_cache( 'comment', $attendee_comment_ids );
	}
}

$attendees = array();
foreach ( $records as $record ) {
	$bio = '';
	if ( ! empty( $record['userId'] ) ) {
		$bio = get_the_author_meta( 'description', (int) $record['userId'] );
		$bio = wp_trim_words( $bio, 20, "\u{2026}" );
	}

	$attendees[] = array(
		'userId'  => (int) $record['userId'],
		'name'    => $record['name'] ?? '',
		'photo'   => $record['photo'] ?? '',
		'profile' => $record['profile'] ?? '',
		'bio'     => $bio,
		'answers' => $can_view_answers
			? get_labelled_answers( (int) ( $record['commentId'] ?? 0 ), $questions )
			: array(),
	);
}

$max_avatars    = 12;
$overflow_count = max( 0, $count - $max_avatars );
$event_title    = get_the_title( $event_post_id );
$is_attending   = 'attending' === $current_status;
$modal_title    = sprintf(
	/* translators: 1: attendee count, 2: event title */
	_n( '%1$s Attendee of %2$s', '%1$s Attendees of %2$s', $count, 'wporg-groups-frontend' ),
	number_format_i18n( $count ),
	$event_title
);
$rsvp_labels    = array(
	/* translators: %s: attendee count. */
	'countSingular'      => _n( '%s going', '%s going', 1, 'wporg-groups-frontend' ),
	/* translators: %s: attendee count. */
	'countPlural'        => _n( '%s going', '%s going', 2, 'wporg-groups-frontend' ),
	/* translators: 1: attendee count, 2: event title. */
	'modalTitleSingular' => _n( '%1$s Attendee of %2$s', '%1$s Attendees of %2$s', 1, 'wporg-groups-frontend' ),
	/* translators: 1: attendee count, 2: event title. */
	'modalTitlePlural'   => _n( '%1$s Attendee of %2$s', '%1$s Attendees of %2$s', 2, 'wporg-groups-frontend' ),
	'loading'            => "\u{2026}",
	'attending'          => "\u{2713} " . __( 'Attending', 'wporg-groups-frontend' ),
	'rsvp'               => __( 'RSVP', 'wporg-groups-frontend' ),
	'joinRsvp'           => __( 'Join & RSVP', 'wporg-groups-frontend' ),
	'statusAttending'    => __( 'You are attending this event.', 'wporg-groups-frontend' ),
	'statusNotAttending' => __( 'You have not RSVPed to this event.', 'wporg-groups-frontend' ),
	'cancelRsvp'         => __( 'Cancel RSVP', 'wporg-groups-frontend' ),
	'attend'             => __( 'Attend', 'wporg-groups-frontend' ),
	'emptyAttendees'     => __( 'No attendees yet. Be the first to RSVP!', 'wporg-groups-frontend' ),
	'missingAnswers'     => __( 'Please answer the required questions.', 'wporg-groups-frontend' ),
	'rsvpFailed'         => __( 'Sorry, your RSVP could not be saved. Please try again.', 'wporg-groups-frontend' ),
);

$context = array(
	'postId'            => (int) $event_post_id,
	'isLoggedIn'        => $is_login,
	'isMember'          => $is_member,
	'isPastEvent'       => $is_past,
	'currentUserStatus' => $current_status,
	'attendingCount'    => $count,
	'eventTitle'        => $event_title,
	'loginUrl'          => wp_login_url( get_permalink( $event_post_id ) ),
	'apiBase'           => rest_url( 'gatherpress/v1/event' ),
	'joinApi'           => rest_url( 'wporg-groups/v1/members/join' ),
	'rsvpApi'           => rest_url( sprintf( 'wporg-groups/v1/event/%d/rsvp', $event_post_id ) ),
	'hasQuestions'      => ! empty( $questions ),
	'canViewAnswers'    => $can_view_answers,
	'modalOpen'         => false,
	'rsvpLoading'       => false,
	'rsvpError'         => '',
	'labels'            => $rsvp_labels,
);

// Register server-side state so Interactivity API directives can evaluate
// derived getters during SSR.
wp_interactivity_state(
	'wporg/event-rsvp',
	array(
		'isAttending'    => $is_attending,
		'countLabel'     => sprintf(
			/* translators: %s: attendee count. */
			_n( '%s going', '%s going', $count, 'wporg-groups-frontend' ),
			number_format_i18n( $count )
		),
		'modalTitle'     => $modal_title,
		'isMember'        => $is_member,
		'rsvpButtonLabel' => $is_attending
			? "\u{2713} " . __( 'Attending', 'wporg-groups-frontend' )
			: ( $is_member ? __( 'RSVP', 'wporg-groups-frontend' ) : __( 'Join & RSVP', 'wporg-groups-frontend' ) ),
		'statusText'     => $is_attending
			? __( 'You are attending this event.', 'wporg-groups-frontend' )
			: __( 'You have not RSVPed to this event.', 'wporg-groups-frontend' ),
		'modalRsvpLabel' => $is_attending
			? __( 'Cancel RSVP', 'wporg-groups-frontend' )
			: __( 'Attend', 'wporg-groups-frontend' ),
	)
);

$wrapper_attributes = get_block_wrapper_attributes(
	array(
		'data-wp-interactive' => 'wporg/event-rsvp',
		'data-wp-context'     => wp_json_encode( $context ),
	)
);
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes escapes. ?>>

	<div class="wporg-event-rsvp__summary"
		data-wp-on--click="actions.openModal"
		data-wp-on--keydown="actions.handleSummaryKeydown"
		role="button"
		tabindex="0"
		aria-label="<?php esc_attr_e( 'View attendees', 'wporg-groups-frontend' ); ?>">

		<div class="wporg-event-rsvp__avatars">
			<?php foreach ( array_slice( $attendees, 0, $max_avatars ) as $attendee ) : ?>
				<img
					class="wporg-event-rsvp__avatar"
					src="<?php echo esc_url( $attendee['photo'] ); ?>"
					alt="<?php echo esc_attr( $attendee['name'] ); ?>"
					width="40"
					height="40"
					loading="lazy"
				/>
			<?php endforeach; ?>
			<?php if ( $overflow_count > 0 ) : ?>
				<span class="wporg-event-rsvp__overflow">+<?php echo (int) $overflow_count; ?></span>
			<?php endif; ?>
		</div>

		<span class="wporg-event-rsvp__count" data-wp-text="state.countLabel">
			<?php
			echo esc_html(
				sprintf(
					_n( '%s going', '%s going', $count, 'wporg-groups-frontend' ),
					number_format_i18n( $count )
				)
			);
			?>
		</span>
	</div>

	<?php if ( ! $is_past ) : ?>
		<button
			class="wporg-event-rsvp__button wp-element-button<?php echo $is_attending ? ' is-attending' : ''; ?>"
			data-wp-on--click="actions.handleRsvpButton"
			data-wp-text="state.rsvpButtonLabel"
			data-wp-class--is-attending="state.isAttending"
			data-wp-bind--disabled="context.rsvpLoading"
		>
			<?php
			if ( $is_attending ) {
				echo "\u{2713} " . esc_html__( 'Attending', 'wporg-groups-frontend' );
			} elseif ( $is_member ) {
				esc_html_e( 'RSVP', 'wporg-groups-frontend' );
			} else {
				esc_html_e( 'Join & RSVP', 'wporg-groups-frontend' );
			}
			?>
		</button>
	<?php else : ?>
		<button class="wporg-event-rsvp__button wp-element-button is-past" disabled>
			<?php esc_html_e( 'Past Event', 'wporg-groups-frontend' ); ?>
		</button>
	<?php endif; ?>

	<div
		class="wporg-event-rsvp__modal"
		data-wp-class--is-open="context.modalOpen"
		data-wp-on--click="actions.handleBackdropClick"
		data-wp-on-window--keydown="actions.handleEscape"
		role="dialog"
		aria-modal="true"
		aria-label="<?php esc_attr_e( 'Event attendees', 'wporg-groups-frontend' ); ?>"
	>
		<div class="wporg-event-rsvp__modal-content">
			<div class="wporg-event-rsvp__modal-header">
				<h2 class="wporg-event-rsvp__modal-title" data-wp-text="state.modalTitle">
					<?php
					echo esc_html( $modal_title );
					?>
				</h2>
				<button
					class="wporg-event-rsvp__modal-close"
					data-wp-on--click="actions.closeModal"
					aria-label="<?php esc_attr_e( 'Close', 'wporg-groups-frontend' ); ?>"
				>&times;</button>
			</div>

			<?php if ( ! $is_past ) : ?>
				<div class="wporg-event-rsvp__modal-action">
					<?php if ( $is_login ) : ?>
						<p class="wporg-event-rsvp__modal-status" data-wp-text="state.statusText">
							<?php
							if ( $is_attending ) {
								esc_html_e( 'You are attending this event.', 'wporg-groups-frontend' );
							} else {
								esc_html_e( 'You have not RSVPed to this event.', 'wporg-groups-frontend' );
							}
							?>
						</p>

						<?php if ( $questions ) : ?>
							<div class="wporg-event-rsvp__questions" data-wp-class--is-hidden="state.isAttending">
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
											<?php echo $question['required'] ? 'required' : ''; ?>
										/>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>

						<p class="wporg-event-rsvp__error" role="alert" data-wp-text="context.rsvpError"></p>

						<button
							class="wporg-event-rsvp__modal-rsvp-btn wp-element-button<?php echo $is_attending ? ' is-attending' : ''; ?>"
							data-wp-on--click="actions.toggleRsvp"
							data-wp-text="state.modalRsvpLabel"
							data-wp-class--is-attending="state.isAttending"
							data-wp-bind--disabled="context.rsvpLoading"
						>
							<?php
							if ( $is_attending ) {
								esc_html_e( 'Cancel RSVP', 'wporg-groups-frontend' );
							} else {
								esc_html_e( 'Attend', 'wporg-groups-frontend' );
							}
							?>
						</button>
					<?php else : ?>
						<p class="wporg-event-rsvp__modal-status">
							<?php
							printf(
								wp_kses(
									/* translators: %s: login URL */
									__( '<a href="%s">Log in</a> to RSVP to this event.', 'wporg-groups-frontend' ),
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
				<?php endforeach; ?>
				<?php if ( empty( $attendees ) ) : ?>
					<p class="wporg-event-rsvp__empty"><?php esc_html_e( 'No attendees yet. Be the first to RSVP!', 'wporg-groups-frontend' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>
