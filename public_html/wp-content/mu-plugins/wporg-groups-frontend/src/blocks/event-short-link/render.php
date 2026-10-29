<?php
/**
 * Server-side rendering for the wporg/event-short-link block.
 *
 * The info card's short link (#2259): `…/e/{ID}/`, the same one the flyer
 * prints. An event's permalink carries its slug, so a long or non-Latin
 * title makes a link too unwieldy to paste elsewhere. On a date of a series
 * the link names that date, as on that date's flyer.
 *
 * @package WordCamp\Groups\Frontend
 */

use function WordCamp\Groups\Frontend\Event_Flyer\get_short_url;

$event_post_id = $block->context['postId'] ?? get_the_ID();

if ( ! $event_post_id ) {
	$event_post_id = get_queried_object_id();
}

if ( ! $event_post_id || ! get_permalink( $event_post_id ) ) {
	return;
}

$short_url = get_short_url( (int) $event_post_id );

$wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class'         => 'wporg-event-short-link',
		'data-copied'   => __( 'Link copied.', 'wordcamporg' ),
		'data-failed'   => __( "Couldn't copy the link. Select it and copy it yourself.", 'wordcamporg' ),
	)
);
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<a class="wporg-event-short-link__url" href="<?php echo esc_url( $short_url ); ?>"><?php echo esc_html( preg_replace( '#^https?://#', '', $short_url ) ); ?></a>
	<?php
	/*
	 * Rendered hidden and shown by `view.js`, like the flyer's print button:
	 * without JavaScript it couldn't copy anything. The link is still there
	 * to copy by hand.
	 */
	?>
	<button type="button" class="wporg-event-short-link__copy" data-url="<?php echo esc_url( $short_url ); ?>" hidden>
		<?php esc_html_e( 'Copy', 'wordcamporg' ); ?>
	</button>
	<span class="wporg-event-short-link__status" role="status"></span>
</div>
