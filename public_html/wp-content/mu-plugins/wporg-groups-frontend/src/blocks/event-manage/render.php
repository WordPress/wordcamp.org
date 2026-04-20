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

if ( ! current_user_can_manage_events() ) {
	return;
}

$event_post_id = ! empty( $block->context['postId'] )
	? (int) $block->context['postId']
	: ( get_the_ID() ?: get_queried_object_id() );

$mode       = $attributes['mode'] ?? 'auto';
$is_editing = false;

if ( 'auto' === $mode ) {
	$is_editing = $event_post_id
		&& 'gatherpress_event' === get_post_type( $event_post_id );
} elseif ( 'edit' === $mode ) {
	$is_editing = true;
} else {
	$is_editing = false;
}

$wrapper_attributes = get_block_wrapper_attributes(
	array( 'class' => 'wporg-event-manage' )
);
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( $is_editing && $event_post_id ) : ?>
		<button
			type="button"
			class="wporg-event-manage__button wporg-event-manage__button--edit"
			data-wporg-groups-modal="edit"
			data-wporg-groups-event-id="<?php echo (int) $event_post_id; ?>"
		>&#9998; <?php esc_html_e( 'Edit this event', 'wporg-groups' ); ?></button>
	<?php endif; ?>

	<?php if ( ! $is_editing || 'auto' === $mode ) : ?>
		<button
			type="button"
			class="wporg-event-manage__button wporg-event-manage__button--create"
			data-wporg-groups-modal="create"
		>+ <?php esc_html_e( 'Create event', 'wporg-groups' ); ?></button>
	<?php endif; ?>

	<div id="wporg-groups-event-modal-root"></div>
</div>
