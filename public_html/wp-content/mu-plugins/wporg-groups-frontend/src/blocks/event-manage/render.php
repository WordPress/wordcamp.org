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

$mode = $attributes['mode'] ?? 'auto';

$is_single_event = $event_post_id
	&& 'gatherpress_event' === get_post_type( $event_post_id )
	&& is_singular( 'gatherpress_event' );

$show_edit   = false;
$show_create = false;

if ( 'edit' === $mode ) {
	$show_edit = true;
} elseif ( 'create' === $mode ) {
	$show_create = true;
} elseif ( $is_single_event ) {
	$show_edit = true;
} else {
	$show_create = true;
}

if ( ! $show_edit && ! $show_create ) {
	return;
}

$wrapper_attributes = get_block_wrapper_attributes(
	array( 'class' => 'wporg-event-manage' )
);
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( $show_edit && $event_post_id ) : ?>
		<button
			type="button"
			class="wporg-event-manage__button wporg-event-manage__button--edit wp-element-button"
			data-wporg-groups-modal="edit"
			data-wporg-groups-event-id="<?php echo (int) $event_post_id; ?>"
		>&#9998; <?php esc_html_e( 'Edit this event', 'wporg-groups' ); ?></button>
	<?php endif; ?>

	<?php if ( $show_create ) : ?>
		<button
			type="button"
			class="wporg-event-manage__button wporg-event-manage__button--create wp-element-button"
			data-wporg-groups-modal="create"
		>+ <?php esc_html_e( 'Create event', 'wporg-groups' ); ?></button>
	<?php endif; ?>

	<div id="wporg-groups-event-modal-root"></div>
</div>
