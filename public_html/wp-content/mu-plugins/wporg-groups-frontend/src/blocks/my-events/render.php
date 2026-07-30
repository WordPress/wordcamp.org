<?php
/**
 * Server-side rendering for the wporg/my-events block.
 *
 * Shows the current logged-in member their upcoming events, meaning both the
 * ones they have RSVP'd to as attending and the ones they authored, which on a
 * group site are the ones they organise. Authorship is unioned into the query
 * rather than written into the RSVP data, so "attending" keeps meaning that the
 * person said they are coming (#1810).
 *
 * The heading renders with an explanatory line when a member has no upcoming
 * events, rather than the block disappearing: a block that renders nothing is
 * indistinguishable from a broken one, which is how #1810 came to be reported.
 *
 * @package WordCamp\Groups\Frontend
 */

use function WordCamp\Groups\Frontend\My_Events\get_upcoming_event_ids;

if ( ! is_user_logged_in() || ! is_user_member_of_blog() ) {
	return;
}

$wporg_upcoming_ids = get_upcoming_event_ids( get_current_user_id() );

$wporg_upcoming_events = empty( $wporg_upcoming_ids )
	? array()
	: get_posts(
		array(
			'post_type'      => 'gatherpress_event',
			'post_status'    => 'publish',
			'post__in'       => $wporg_upcoming_ids,
			'orderby'        => 'post__in',
			'posts_per_page' => count( $wporg_upcoming_ids ),
		)
	);

$wporg_wrapper_attributes = get_block_wrapper_attributes(
	array( 'class' => 'wporg-my-events' )
);
?>
<div <?php echo $wporg_wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<h3 class="wporg-my-events__heading">
		<?php esc_html_e( 'My upcoming events', 'wporg-groups-frontend' ); ?>
	</h3>
	<?php if ( empty( $wporg_upcoming_events ) ) : ?>
		<p class="wporg-my-events__empty">
			<?php esc_html_e( 'Nothing on your calendar yet. Events you RSVP to, and events you organise, will show up here.', 'wporg-groups-frontend' ); ?>
		</p>
	<?php else : ?>
		<div class="wporg-my-events__list">
			<?php
			foreach ( $wporg_upcoming_events as $wporg_event_post ) :
				$wporg_start      = get_post_meta( $wporg_event_post->ID, 'gatherpress_datetime_start', true );
				$wporg_date_label = '';

				if ( $wporg_start ) {
					try {
						$wporg_datetime   = new \DateTime( $wporg_start );
						$wporg_date_label = $wporg_datetime->format( 'M j, Y · g:i A' );
					} catch ( \Exception $e ) {
						$wporg_date_label = $wporg_start;
					}
				}
				?>
				<a class="wporg-my-events__item" href="<?php echo esc_url( get_permalink( $wporg_event_post->ID ) ); ?>">
					<span class="wporg-my-events__date"><?php echo esc_html( $wporg_date_label ); ?></span>
					<span class="wporg-my-events__title"><?php echo esc_html( get_the_title( $wporg_event_post->ID ) ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
