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
 * Renders nothing when the member has no upcoming events, so the surrounding
 * page content gets the visibility instead of a permanent empty-state message.
 * That's safe now that authored events are part of the query: an empty result
 * genuinely means nothing upcoming, unlike the pre-#1810 behavior where it
 * could mean the query was missing the member's own events.
 *
 * @package WordCamp\Groups\Frontend
 */

use function WordCamp\Groups\Frontend\My_Events\get_upcoming_event_ids;

if ( ! is_user_logged_in() || ! is_user_member_of_blog() ) {
	return;
}

$wporg_upcoming_ids = get_upcoming_event_ids( get_current_user_id() );

if ( empty( $wporg_upcoming_ids ) ) {
	return;
}

/*
 * Uncapped on purpose: the block exists so a member can confirm their event is
 * listed (#1810), and truncating could hide the one they came to check.
 */
$wporg_upcoming_events = get_posts(
	array(
		'post_type'      => 'gatherpress_event',
		'post_status'    => 'publish',
		'post__in'       => $wporg_upcoming_ids,
		'orderby'        => 'post__in',
		'posts_per_page' => count( $wporg_upcoming_ids ),
	)
);

if ( empty( $wporg_upcoming_events ) ) {
	return;
}

$wporg_wrapper_attributes = get_block_wrapper_attributes(
	array( 'class' => 'wporg-my-events' )
);
?>
<section <?php echo $wporg_wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<h2 class="wporg-section-heading wporg-my-events__heading">
		<?php esc_html_e( 'My upcoming events', 'wporg-groups-frontend' ); ?>
	</h2>
	<div class="wporg-my-events__list">
		<?php
		foreach ( $wporg_upcoming_events as $wporg_event_post ) :
			$wporg_start      = get_post_meta( $wporg_event_post->ID, 'gatherpress_datetime_start', true );
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
					<a href="<?php echo esc_url( get_permalink( $wporg_event_post->ID ) ); ?>">
						<?php echo esc_html( get_the_title( $wporg_event_post->ID ) ); ?>
					</a>
				</h3>
			</div>
		<?php endforeach; ?>
	</div>
</section>
