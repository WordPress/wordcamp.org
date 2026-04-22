<?php
/**
 * Server-side rendering for the wporg/event-rsvp-count block.
 *
 * Displays "N going" for the current event post.
 *
 * @package WordCamp\Groups\Frontend
 */

use GatherPress\Core\Rsvp;

$event_post_id = $block->context['postId'] ?? get_the_ID();

if ( ! $event_post_id || ! class_exists( '\GatherPress\Core\Rsvp' ) ) {
	return;
}

$rsvp      = new Rsvp( $event_post_id );
$responses = $rsvp->responses();
$count     = (int) ( $responses['attending']['count'] ?? 0 );

if ( 0 === $count ) {
	return;
}

$label = sprintf(
	_n( '%s going', '%s going', $count, 'wporg-groups-frontend' ),
	number_format_i18n( $count )
);

$wrapper_attributes = get_block_wrapper_attributes();
?>
<span <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( $label ); ?></span>
