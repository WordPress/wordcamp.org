<?php
/**
 * Server-side rendering for the wporg/event-topics block.
 *
 * Lists the event's topics, each linking to the events archive filtered to
 * it. Renders nothing for an event with no topics, so the info card only
 * gains a row when there is something to follow.
 *
 * @package WordCamp\Groups\Frontend
 */

use function WordCamp\Groups\Frontend\Event_Topics\get_event_topic_terms;
use function WordCamp\Groups\Frontend\Event_Topics\get_topic_archive_url;

$event_post_id = $block->context['postId'] ?? get_the_ID();

if ( ! $event_post_id ) {
	$event_post_id = get_queried_object_id();
}

// Follows the event's password gate, the same as the rest of the card.
if ( post_password_required( $event_post_id ) ) {
	return;
}

$topic_terms = get_event_topic_terms( (int) $event_post_id );

if ( ! $topic_terms ) {
	return;
}

$wrapper_attributes = get_block_wrapper_attributes(
	array( 'class' => 'wporg-event-topics' )
);
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<p class="wporg-event-topics__label groups-site-event-label" id="wporg-event-topics-label-<?php echo (int) $event_post_id; ?>"><?php esc_html_e( 'Topics', 'wordcamporg' ); ?></p>
	<ul class="wporg-event-topics__list" aria-labelledby="wporg-event-topics-label-<?php echo (int) $event_post_id; ?>">
		<?php foreach ( $topic_terms as $topic_term ) : ?>
			<li class="wporg-event-topics__item">
				<a class="wporg-event-topics__link" href="<?php echo esc_url( get_topic_archive_url( $topic_term ) ); ?>"><?php echo esc_html( $topic_term->name ); ?></a>
			</li>
		<?php endforeach; ?>
	</ul>
</div>
