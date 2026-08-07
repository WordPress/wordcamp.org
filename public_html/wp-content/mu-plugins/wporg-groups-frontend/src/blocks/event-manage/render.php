<?php
/**
 * Server-side rendering for the wporg/event-manage block.
 *
 * Renders create/edit event buttons for users who can manage events.
 * The React modal app mounts via the view script.
 *
 * @package WordCamp\Groups\Frontend
 */

use function WordCamp\Groups\Frontend\Capabilities\current_user_can_manage_events;
use function WordCamp\Groups\Frontend\Capabilities\current_user_can_manage_group_settings;
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
			>&#9998; <?php esc_html_e( 'Edit this event', 'wporg-groups-frontend' ); ?></button>
		</div>
	<?php endif; ?>

	<?php if ( $show_edit && $event_post_id ) : ?>
		<div class="wp-block-button is-style-outline">
			<button
				type="button"
				class="wp-block-button__link wp-element-button"
				data-wporg-groups-modal="message-all"
				data-wporg-groups-event-id="<?php echo (int) $event_post_id; ?>"
			><?php esc_html_e( 'Message all members', 'wporg-groups-frontend' ); ?></button>
		</div>

		<div class="wp-block-button is-style-outline">
			<button
				type="button"
				class="wp-block-button__link wp-element-button"
				data-wporg-groups-modal="message-attendees"
				data-wporg-groups-event-id="<?php echo (int) $event_post_id; ?>"
			><?php esc_html_e( 'Message attendees', 'wporg-groups-frontend' ); ?></button>
		</div>
	<?php endif; ?>

	<?php if ( $show_create ) : ?>
		<div class="wp-block-button">
			<button
				type="button"
				class="wp-block-button__link wp-element-button"
				data-wporg-groups-modal="create"
			>+ <?php esc_html_e( 'Create event', 'wporg-groups-frontend' ); ?></button>
		</div>
	<?php endif; ?>

	<div id="wporg-groups-event-modal-root"></div>
</div>
