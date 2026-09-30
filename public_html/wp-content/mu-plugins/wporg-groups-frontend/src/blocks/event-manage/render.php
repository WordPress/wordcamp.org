<?php
/**
 * Server-side rendering for the wporg/event-manage block.
 *
 * Renders create/edit event buttons for users who can manage events.
 * The React modal app mounts via the view script.
 *
 * @package WordCamp\Groups\Frontend
 */

use GatherPress\Core\Event\Event;

use function WordCamp\Groups\Frontend\Capabilities\current_user_can_manage_events;
use function WordCamp\Groups\Frontend\Capabilities\current_user_can_manage_group_settings;
use function WordCamp\Groups\Frontend\Check_In\is_open as check_in_is_open;
use function WordCamp\Groups\Frontend\REST\current_user_can_edit_event;

if ( ! current_user_can_manage_events() ) {
	return;
}

$event_post_id = ! empty( $block->context['postId'] )
	? (int) $block->context['postId']
	: ( get_the_ID() ?: get_queried_object_id() );

$block_mode = $attributes['mode'] ?? 'auto';

$is_single_event = $event_post_id
	&& 'gatherpress_event' === get_post_type( $event_post_id )
	&& is_singular( 'gatherpress_event' );

$show_edit   = false;
$show_create = false;

if ( 'edit' === $block_mode ) {
	$show_edit = true;
} elseif ( 'create' === $block_mode ) {
	$show_create = true;
} elseif ( $is_single_event ) {
	$show_edit = true;
} else {
	$show_create = true;
}

if ( $show_edit && ( ! $event_post_id || ! current_user_can_edit_event( $event_post_id ) ) ) {
	$show_edit = false;
}

$show_edit_button = $show_edit && ! current_user_can_manage_group_settings();

/*
 * Check-in (#2130) is offered from shortly before the start, when there's
 * someone to check in, and stays for past events so the list can be caught
 * up afterwards. On a recurring series it's for the date being viewed, which
 * the recurring-events integration supplies through the filter.
 */
$show_check_in       = false;
$check_in_recurrence = '';

if ( $show_edit && $is_single_event && $event_post_id ) {
	$check_in_event = new Event( $event_post_id );
	$show_check_in  = $check_in_event->rsvp
		&& $check_in_event->rsvp->is_enabled()
		&& check_in_is_open( $check_in_event );

	$check_in_recurrence = (string) apply_filters( 'wporg_groups_frontend_current_recurrence_id', '', $event_post_id );
}

if ( ! $show_edit && ! $show_create ) {
	return;
}

$wrapper_attributes = get_block_wrapper_attributes(
	array( 'class' => 'wp-block-buttons wporg-event-manage' )
);
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( $show_edit_button && $event_post_id ) : ?>
		<div class="wp-block-button is-style-outline">
			<button
				type="button"
				class="wp-block-button__link wp-element-button"
				data-wporg-groups-modal="edit"
				data-wporg-groups-event-id="<?php echo (int) $event_post_id; ?>"
			>&#9998; <?php esc_html_e( 'Edit this event', 'wordcamporg' ); ?></button>
		</div>
	<?php endif; ?>

	<?php if ( $show_edit && $event_post_id ) : ?>
		<div class="wp-block-button is-style-outline">
			<button
				type="button"
				class="wp-block-button__link wp-element-button"
				data-wporg-groups-modal="message-all"
				data-wporg-groups-event-id="<?php echo (int) $event_post_id; ?>"
			><?php esc_html_e( 'Message all members', 'wordcamporg' ); ?></button>
		</div>

		<div class="wp-block-button is-style-outline">
			<button
				type="button"
				class="wp-block-button__link wp-element-button"
				data-wporg-groups-modal="message-attendees"
				data-wporg-groups-event-id="<?php echo (int) $event_post_id; ?>"
			><?php esc_html_e( 'Message attendees', 'wordcamporg' ); ?></button>
		</div>

		<?php if ( $show_check_in ) : ?>
			<div class="wp-block-button is-style-outline">
				<button
					type="button"
					class="wp-block-button__link wp-element-button"
					data-wporg-groups-modal="check-in"
					data-wporg-groups-event-id="<?php echo (int) $event_post_id; ?>"
					data-wporg-groups-recurrence-id="<?php echo esc_attr( $check_in_recurrence ); ?>"
				><?php esc_html_e( 'Check in attendees', 'wordcamporg' ); ?></button>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( $show_create ) : ?>
		<div class="wp-block-button">
			<button
				type="button"
				class="wp-block-button__link wp-element-button"
				data-wporg-groups-modal="create"
			>+ <?php esc_html_e( 'Create event', 'wordcamporg' ); ?></button>
		</div>
	<?php endif; ?>

	<div id="wporg-groups-event-modal-root"></div>
</div>
