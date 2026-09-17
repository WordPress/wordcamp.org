<?php
/**
 * Server-side rendering for the wporg/my-events block.
 *
 * Shows the current logged-in member their upcoming events, meaning both the
 * ones they have RSVP'd to as attending and the ones they authored, which on a
 * group site are the ones they organize. Authorship is unioned into the query
 * rather than written into the RSVP data, so "attending" keeps meaning that the
 * person said they are coming (#1810).
 *
 * A recurring series is one post with many dates, so the list is of dates
 * rather than events (#2056): a member sees the occurrence they RSVP'd to,
 * and its card links to that occurrence rather than to the series.
 *
 * Renders nothing when the member has no upcoming events, so the surrounding
 * page content gets the visibility instead of a permanent empty-state message.
 * Safe because authored events are part of the query (#1810): an empty result
 * genuinely means nothing upcoming, not a query missing the member's own
 * events.
 *
 * @package WordCamp\Groups\Frontend
 */

use function WordCamp\Groups\Frontend\My_Events\get_entry_url;
use function WordCamp\Groups\Frontend\My_Events\get_upcoming_events;

if ( ! is_user_logged_in() || ! is_user_member_of_blog() ) {
	return;
}

$wporg_upcoming = get_upcoming_events( get_current_user_id() );

if ( empty( $wporg_upcoming ) ) {
	return;
}

/*
 * Uncapped on purpose: the block exists so a member can confirm their event is
 * listed (#1810), and truncating could hide the one they came to check.
 *
 * One query for the posts behind the dates, rather than one per card: a
 * recurring series is several dates on the same post (#2056).
 */
$wporg_event_ids = array_values( array_unique( array_column( $wporg_upcoming, 'event_id' ) ) );

$wporg_event_posts = get_posts(
	array(
		'post_type'      => 'gatherpress_event',
		'post_status'    => 'publish',
		'post__in'       => $wporg_event_ids,
		'posts_per_page' => count( $wporg_event_ids ),
	)
);

if ( empty( $wporg_event_posts ) ) {
	return;
}

$wporg_events_by_id = array();
foreach ( $wporg_event_posts as $wporg_event_post ) {
	$wporg_events_by_id[ $wporg_event_post->ID ] = $wporg_event_post;
}

/*
 * The `id` is the header's "My events" link target (#2060): the section only
 * exists on the group's front page, so the link is an anchor into it rather
 * than a route of its own. A member with nothing upcoming has no section to
 * jump to and lands at the top of the front page, which is the same trade the
 * block already makes by rendering nothing.
 */
$wporg_wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class' => 'wporg-my-events',
		'id'    => 'my-events',
	)
);
?>
<section <?php echo $wporg_wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<h2 class="wporg-section-heading wporg-my-events__heading">
		<?php esc_html_e( 'My upcoming events', 'wporg-groups-frontend' ); ?>
	</h2>
	<div class="wporg-my-events__list">
		<?php
		foreach ( $wporg_upcoming as $wporg_entry ) :
			if ( ! isset( $wporg_events_by_id[ $wporg_entry['event_id'] ] ) ) {
				continue;
			}

			$wporg_event_post = $wporg_events_by_id[ $wporg_entry['event_id'] ];
			$wporg_start      = $wporg_entry['start'];
			$wporg_date_label = '';
			$wporg_date_attr  = '';

			if ( $wporg_start ) {
				try {
					$wporg_datetime   = new \DateTime( $wporg_start );
					$wporg_date_label = $wporg_datetime->format( 'M j, Y · g:i A' );

					/*
					 * Local time with no offset. The stored value is
					 * already in the event's own timezone, and PHP's
					 * default timezone — which `c` would stamp on it —
					 * is not necessarily that one.
					 */
					$wporg_date_attr = $wporg_datetime->format( 'Y-m-d\TH:i' );
				} catch ( \Exception $e ) {
					$wporg_date_label = $wporg_start;
				}
			}
			?>
			<div class="wporg-my-events__card">
				<?php if ( $wporg_date_label ) : ?>
					<p class="wporg-my-events__date">
						<?php if ( $wporg_date_attr ) : ?>
							<time datetime="<?php echo esc_attr( $wporg_date_attr ); ?>"><?php echo esc_html( $wporg_date_label ); ?></time>
						<?php else : ?>
							<?php echo esc_html( $wporg_date_label ); ?>
						<?php endif; ?>
					</p>
				<?php endif; ?>
				<h3 class="wporg-my-events__title">
					<a href="<?php echo esc_url( get_entry_url( $wporg_entry['event_id'], $wporg_entry['recurrence_id'] ) ); ?>">
						<?php echo esc_html( get_the_title( $wporg_event_post->ID ) ); ?>
					</a>
				</h3>
			</div>
		<?php endforeach; ?>
	</div>
</section>
