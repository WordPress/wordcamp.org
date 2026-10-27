<?php
/**
 * Server-side rendering for the wporg/event-flyer-qr block.
 *
 * The flyer's way back to the event: a QR code, drawn in the browser by
 * `view.js` from the URL in `data-url`, and the same URL printed underneath
 * for anyone who'd rather type it. Drawn locally rather than fetched from a
 * QR service so the event's address isn't handed to a third party, and the
 * printed URL means the flyer still works if the script never runs.
 *
 * Both use the event's short URL (#2222), which is shorter to type than its
 * permalink. On a dated occurrence of a recurring series it names that date,
 * so the code opens the date the flyer is for.
 *
 * @package WordCamp\Groups\Frontend
 */

use function WordCamp\Groups\Frontend\Event_Flyer\get_short_url;

$event_post_id = $block->context['postId'] ?? get_the_ID();

if ( ! $event_post_id ) {
	$event_post_id = get_queried_object_id();
}

$event_url = $event_post_id && get_permalink( $event_post_id ) ? get_short_url( (int) $event_post_id ) : '';

if ( '' === $event_url ) {
	return;
}

// Shown without the scheme: it's what someone would type.
$display_url = untrailingslashit( preg_replace( '#^https?://#', '', $event_url ) );

$wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class'    => 'wporg-event-flyer-qr',
		'data-url' => $event_url,
	)
);
?>
<figure <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="wporg-event-flyer-qr__code" aria-hidden="true"></div>
	<figcaption class="wporg-event-flyer-qr__caption">
		<span class="wporg-event-flyer-qr__label"><?php esc_html_e( 'Scan to RSVP, or visit', 'wordcamporg' ); ?></span>
		<span class="wporg-event-flyer-qr__url"><?php echo esc_html( $display_url ); ?></span>
	</figcaption>
</figure>
