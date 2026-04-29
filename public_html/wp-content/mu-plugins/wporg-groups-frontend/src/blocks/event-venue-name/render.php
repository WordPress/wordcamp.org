<?php
/**
 * Server-side rendering for the wporg/event-venue-name block.
 *
 * Displays the venue name with a location icon for the current event.
 *
 * @package WordCamp\Groups\Frontend
 */

use GatherPress\Core\Event\Event;

$event_post_id = $block->context['postId'] ?? get_the_ID();

if ( ! $event_post_id || ! class_exists( '\GatherPress\Core\Event\Event' ) ) {
	return;
}

$event     = new Event( $event_post_id );
$venue     = $event->get_venue_information();
$venue_name = $venue['name'] ?? '';

if ( empty( $venue_name ) ) {
	return;
}

$wrapper_attributes = get_block_wrapper_attributes();
?>
<span <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<span class="dashicons dashicons-location" aria-hidden="true"></span>
	<?php echo esc_html( $venue_name ); ?>
</span>
