<?php
/**
 * Server-side rendering for the wporg/event-rsvp block.
 *
 * @package WordCamp\Groups\Frontend
 */

use GatherPress\Core\Event\Event;
use GatherPress\Core\Rsvp\Rsvp;

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
	);
}

$max_avatars    = 12;
$overflow_count = max( 0, $count - $max_avatars );
$event_title    = get_the_title( $event_post_id );
$is_attending   = 'attending' === $current_status;

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
	'modalOpen'         => false,
	'rsvpLoading'       => false,
);

// Register server-side state so Interactivity API directives can evaluate
// derived getters during SSR.
wp_interactivity_state(
	'wporg/event-rsvp',
	array(
		'isAttending'    => $is_attending,
		'countLabel'     => sprintf(
			_n( '%d going', '%d going', $count, 'wporg-groups' ),
			$count
		),
		'modalTitle'     => sprintf(
			/* translators: 1: attendee count, 2: event title */
			__( '%1$s Attending %2$s', 'wporg-groups' ),
			number_format_i18n( $count ),
			$event_title
		),
		'isMember'        => $is_member,
		'rsvpButtonLabel' => $is_attending
			? "\u{2713} " . __( 'Attending', 'wporg-groups' )
			: ( $is_member ? __( 'RSVP', 'wporg-groups' ) : __( 'Join & RSVP', 'wporg-groups' ) ),
		'statusText'     => $is_attending
			? __( 'You are attending this event.', 'wporg-groups' )
			: __( 'You have not RSVPed to this event.', 'wporg-groups' ),
		'modalRsvpLabel' => $is_attending
			? __( 'Cancel RSVP', 'wporg-groups' )
			: __( 'Attend', 'wporg-groups' ),
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
		aria-label="<?php esc_attr_e( 'View attendees', 'wporg-groups' ); ?>">

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
					_n( '%s going', '%s going', $count, 'wporg-groups' ),
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
				echo "\u{2713} " . esc_html__( 'Attending', 'wporg-groups' );
			} elseif ( $is_member ) {
				esc_html_e( 'RSVP', 'wporg-groups' );
			} else {
				esc_html_e( 'Join & RSVP', 'wporg-groups' );
			}
			?>
		</button>
	<?php else : ?>
		<button class="wporg-event-rsvp__button wp-element-button is-past" disabled>
			<?php esc_html_e( 'Past Event', 'wporg-groups' ); ?>
		</button>
	<?php endif; ?>

	<div
		class="wporg-event-rsvp__modal"
		data-wp-class--is-open="context.modalOpen"
		data-wp-on--click="actions.handleBackdropClick"
		data-wp-on-window--keydown="actions.handleEscape"
		role="dialog"
		aria-modal="true"
		aria-label="<?php esc_attr_e( 'Event attendees', 'wporg-groups' ); ?>"
	>
		<div class="wporg-event-rsvp__modal-content">
			<div class="wporg-event-rsvp__modal-header">
				<h2 class="wporg-event-rsvp__modal-title" data-wp-text="state.modalTitle">
					<?php
					echo esc_html(
						sprintf(
							__( '%1$d Attending %2$s', 'wporg-groups' ),
							$count,
							$event_title
						)
					);
					?>
				</h2>
				<button
					class="wporg-event-rsvp__modal-close"
					data-wp-on--click="actions.closeModal"
					aria-label="<?php esc_attr_e( 'Close', 'wporg-groups' ); ?>"
				>&times;</button>
			</div>

			<?php if ( ! $is_past ) : ?>
				<div class="wporg-event-rsvp__modal-action">
					<?php if ( $is_login ) : ?>
						<p class="wporg-event-rsvp__modal-status" data-wp-text="state.statusText">
							<?php
							if ( $is_attending ) {
								esc_html_e( 'You are attending this event.', 'wporg-groups' );
							} else {
								esc_html_e( 'You have not RSVPed to this event.', 'wporg-groups' );
							}
							?>
						</p>
						<button
							class="wporg-event-rsvp__modal-rsvp-btn wp-element-button<?php echo $is_attending ? ' is-attending' : ''; ?>"
							data-wp-on--click="actions.toggleRsvp"
							data-wp-text="state.modalRsvpLabel"
							data-wp-class--is-attending="state.isAttending"
							data-wp-bind--disabled="context.rsvpLoading"
						>
							<?php
							if ( $is_attending ) {
								esc_html_e( 'Cancel RSVP', 'wporg-groups' );
							} else {
								esc_html_e( 'Attend', 'wporg-groups' );
							}
							?>
						</button>
					<?php else : ?>
						<p class="wporg-event-rsvp__modal-status">
							<?php
							printf(
								wp_kses(
									/* translators: %s: login URL */
									__( '<a href="%s">Log in</a> to RSVP to this event.', 'wporg-groups' ),
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
						</div>
					</a>
				<?php endforeach; ?>
				<?php if ( empty( $attendees ) ) : ?>
					<p class="wporg-event-rsvp__empty"><?php esc_html_e( 'No attendees yet. Be the first to RSVP!', 'wporg-groups' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>
