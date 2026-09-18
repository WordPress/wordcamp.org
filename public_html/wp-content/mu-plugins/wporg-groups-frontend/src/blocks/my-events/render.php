<?php
/**
 * Server-side rendering for the wporg/my-events block.
 *
 * Shows the current logged-in member their own events in two lists: the ones
 * still to come, and the ones they have already been to (#2061).
 *
 * "Upcoming" means both the events they have RSVP'd to as attending and the
 * ones they authored, which on a group site are the ones they organize.
 * Authorship is unioned into the query rather than written into the RSVP data,
 * so "attending" keeps meaning that the person said they are coming (#1810).
 * "Past" is RSVPs only: creating an event is not the same as going to it.
 *
 * A recurring series is one post with many dates, so both lists are of dates
 * rather than events (#2056): a member sees the occurrence they RSVP'd to,
 * and its card links to that occurrence rather than to the series.
 *
 * Renders nothing when the member has neither, so the surrounding page content
 * gets the visibility instead of a permanent empty-state message. Safe because
 * authored events are part of the upcoming query (#1810): an empty result
 * genuinely means nothing to show, not a query missing the member's own events.
 *
 * @package WordCamp\Groups\Frontend
 */

use function WordCamp\Groups\Frontend\My_Events\get_entry_url;
use function WordCamp\Groups\Frontend\My_Events\get_past_events;
use function WordCamp\Groups\Frontend\My_Events\get_upcoming_events;

if ( ! is_user_logged_in() || ! is_user_member_of_blog() ) {
	return;
}

$wporg_member_id = get_current_user_id();

$wporg_upcoming = get_upcoming_events( $wporg_member_id );
$wporg_past     = get_past_events( $wporg_member_id );

if ( empty( $wporg_upcoming ) && empty( $wporg_past ) ) {
	return;
}

/*
 * Uncapped on purpose, for the upcoming list: the block exists so a member
 * can confirm their event is listed (#1810), and truncating could hide the one
 * they came to check. The past list caps itself — see `PAST_EVENTS_LIMIT`.
 *
 * One query for the posts behind both lists, rather than one per card: a
 * recurring series is several dates on the same post (#2056), and a member who
 * attends a series regularly has it in both lists at once.
 */
$wporg_event_ids = array_values(
	array_unique(
		array_merge(
			array_column( $wporg_upcoming, 'event_id' ),
			array_column( $wporg_past, 'event_id' )
		)
	)
);

$wporg_event_posts = get_posts(
	array(
		'post_type'      => 'gatherpress_event',
		'post_status'    => 'publish',
		'post__in'       => $wporg_event_ids,
		'posts_per_page' => count( $wporg_event_ids ),
	)
);

$wporg_events_by_id = array();
foreach ( $wporg_event_posts as $wporg_event_post ) {
	$wporg_events_by_id[ $wporg_event_post->ID ] = $wporg_event_post;
}

/*
 * A date whose event is no longer published — unpublished after the RSVP was
 * made, say — drops out here rather than in the card loop, so a list that
 * loses every entry takes its heading with it instead of leaving one standing
 * over nothing.
 */
$wporg_resolved = static function ( array $entries ) use ( $wporg_events_by_id ): array {
	return array_values(
		array_filter(
			$entries,
			static function ( array $entry ) use ( $wporg_events_by_id ): bool {
				return isset( $wporg_events_by_id[ $entry['event_id'] ] );
			}
		)
	);
};

$wporg_upcoming = $wporg_resolved( $wporg_upcoming );
$wporg_past     = $wporg_resolved( $wporg_past );
$wporg_sections = array();

// Upcoming first: what the member still has to turn up to outranks what
// they have already done.
if ( $wporg_upcoming ) {
	$wporg_sections[] = array(
		'heading' => __( 'My upcoming events', 'wporg-groups-frontend' ),
		'entries' => $wporg_upcoming,
	);
}

if ( $wporg_past ) {
	$wporg_sections[] = array(
		'heading' => __( 'Events I attended', 'wporg-groups-frontend' ),
		'entries' => $wporg_past,
	);
}

if ( empty( $wporg_sections ) ) {
	return;
}

/*
 * The `id` is the header's "My events" link target (#2060): the section only
 * exists on the group's front page, so the link is an anchor into it rather
 * than a route of its own. A member with nothing to show has no section to
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
	<?php foreach ( $wporg_sections as $wporg_section ) : ?>
		<h2 class="wporg-section-heading wporg-my-events__heading">
			<?php echo esc_html( $wporg_section['heading'] ); ?>
		</h2>
		<div class="wporg-my-events__list">
			<?php
			foreach ( $wporg_section['entries'] as $wporg_entry ) :
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
	<?php endforeach; ?>
</section>
