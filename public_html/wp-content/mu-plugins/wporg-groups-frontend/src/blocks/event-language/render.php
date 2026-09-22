<?php
/**
 * Server-side rendering for the wporg/event-language block.
 *
 * Renders nothing when the organizer left the language unset, so the info card
 * gains a row only on the groups that actually answer the question.
 *
 * @package WordCamp\Groups\Frontend
 */

use function WordCamp\Groups\Frontend\Event_Language\get_event_language;
use function WordCamp\Groups\Frontend\Event_Language\get_name;

$event_post_id = $block->context['postId'] ?? get_the_ID();

if ( ! $event_post_id ) {
	$event_post_id = get_queried_object_id();
}

// Follows the event's password gate, the same as the rest of the card.
// Unconditional: `preview` is a plain query var any visitor can set, so it
// must not relax this.
if ( post_password_required( $event_post_id ) ) {
	return;
}

$language_code = get_event_language( (int) $event_post_id );
$language_name = '' === $language_code ? '' : get_name( $language_code );

if ( '' === $language_name ) {
	return;
}

$wrapper_attributes = get_block_wrapper_attributes(
	array( 'class' => 'wporg-event-language' )
);
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<p class="wporg-event-language__label groups-site-event-label"><?php esc_html_e( 'Language', 'wporg-groups-frontend' ); ?></p>
	<p class="wporg-event-language__value"><?php echo esc_html( $language_name ); ?></p>
</div>
