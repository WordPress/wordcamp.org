<?php
/**
 * Server-side rendering for the wporg/event-flyer-link block.
 *
 * Two ends of the same trip. On the event page (`event`) it's the info card's
 * "Print flyer" link. On the flyer (`flyer`) it's the bar above the sheet:
 * back to the event, and a button that opens the browser's print dialog. The
 * bar is screen-only; the flyer's print styles drop it from the sheet.
 *
 * @package WordCamp\Groups\Frontend
 */

use function WordCamp\Groups\Frontend\Event_Flyer\get_flyer_url;

$event_post_id = $block->context['postId'] ?? get_the_ID();

if ( ! $event_post_id ) {
	$event_post_id = get_queried_object_id();
}

if ( ! $event_post_id ) {
	return;
}

$flyer_variant = 'flyer' === ( $attributes['variant'] ?? 'event' ) ? 'flyer' : 'event';

if ( 'event' === $flyer_variant ) {
	// A flyer for an event whose details sit behind a password would print
	// those details for anyone. Offer it once the visitor is past the gate.
	if ( post_password_required( $event_post_id ) ) {
		return;
	}

	$wrapper_attributes = get_block_wrapper_attributes(
		array( 'class' => 'wporg-event-flyer-link' )
	);

	printf(
		'<p %1$s><a href="%2$s">%3$s</a></p>',
		$wrapper_attributes, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_url( get_flyer_url( (int) $event_post_id ) ),
		esc_html__( 'Print flyer', 'wordcamporg' )
	);
	return;
}

$wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class'      => 'wporg-event-flyer-link is-flyer-toolbar',
		'aria-label' => __( 'Flyer', 'wordcamporg' ),
	)
);
?>
<nav <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<a class="wporg-event-flyer-link__back" href="<?php echo esc_url( get_permalink( $event_post_id ) ); ?>">
		<span aria-hidden="true">&larr;</span> <?php esc_html_e( 'Back to event', 'wordcamporg' ); ?>
	</a>
	<?php
	/*
	 * Rendered hidden and shown by `view.js`: without JavaScript the button
	 * couldn't open the print dialog, so it isn't offered. The browser's own
	 * Print command still works.
	 */
	?>
	<button type="button" class="wp-element-button wporg-event-flyer-link__print" hidden>
		<?php esc_html_e( 'Print flyer', 'wordcamporg' ); ?>
	</button>
</nav>
