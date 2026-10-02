<?php
/**
 * Server-side rendering for the wporg/event-attendees block.
 *
 * Lists who is attending the current event in the main column. The sidebar
 * RSVP block only shows a few avatars and hides the rest behind a modal,
 * which testers found hard to scan (#2037).
 *
 * @package WordCamp\Groups\Frontend
 */

use GatherPress\Core\Event\Event;
use GatherPress\Core\Rsvp\Rsvp;

use function WordCamp\Groups\Frontend\Check_In\event_has_check_ins;
use function WordCamp\Groups\Frontend\Check_In\is_checked_in;

$event_post_id = ! empty( $block->context['postId'] )
	? (int) $block->context['postId']
	: ( get_the_ID() ?: get_queried_object_id() );

if ( ! $event_post_id || ! post_type_supports( (string) get_post_type( $event_post_id ), 'gatherpress-rsvp' ) ) {
	return;
}

if ( ! is_preview() && 'publish' !== get_post_status( $event_post_id ) ) {
	return;
}

// Same gate as the RSVP block's roster. Unconditional: `preview` is a plain
// query var any visitor can set, so it must not relax this.
if ( post_password_required( $event_post_id ) ) {
	return;
}

$responses = ( new Rsvp( $event_post_id ) )->responses();
$records   = $responses['attending']['records'] ?? array();
$is_past   = ( new Event( $event_post_id ) )->has_event_past();

// Once an organizer has checked anyone in on this date, "attended" means
// checked in, the same as "Events I attended". Listing every RSVP under
// "Attended" would credit the no-shows.
$recurrence_id = (string) apply_filters( 'wporg_groups_frontend_current_recurrence_id', '', $event_post_id );
if ( $is_past && $records && event_has_check_ins( $event_post_id, $recurrence_id ) ) {
	update_meta_cache( 'comment', array_filter( wp_list_pluck( $records, 'commentId' ) ) );

	$records = array_values(
		array_filter(
			$records,
			static fn( array $record ): bool => is_checked_in( (int) ( $record['commentId'] ?? 0 ) )
		)
	);
}

$count = count( $records );

// The sidebar already says "Be the first to RSVP", so an empty section here
// would only repeat it.
if ( ! $count ) {
	return;
}

// Enough for a few rows at any column width. The rest stay in the page
// behind a native disclosure, so large events don't push the discussion
// far down and the full list needs no script.
$visible_limit = 12;
$visible       = array_slice( $records, 0, $visible_limit );
$hidden        = array_slice( $records, $visible_limit );

$heading = sprintf(
	/* translators: %s: number of people. */
	$is_past ? __( 'Attended (%s)', 'wordcamporg' ) : __( 'Attendees (%s)', 'wordcamporg' ),
	number_format_i18n( $count )
);

$render_attendee = static function ( array $record ): void {
	?>
	<li class="wporg-event-attendees__item">
		<a class="wporg-event-attendees__link" href="<?php echo esc_url( $record['profile'] ?? '' ); ?>" target="_blank" rel="noopener">
			<img
				class="wporg-event-attendees__avatar"
				src="<?php echo esc_url( $record['photo'] ?? '' ); ?>"
				alt=""
				width="40"
				height="40"
				loading="lazy"
			/>
			<span class="wporg-event-attendees__name"><?php echo esc_html( $record['name'] ?? '' ); ?></span>
		</a>
	</li>
	<?php
};

$wrapper_attributes = get_block_wrapper_attributes(
	array( 'class' => 'wporg-event-attendees' )
);
?>
<section <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes escapes. ?>>
	<h2 class="wp-block-heading wporg-event-attendees__heading has-heading-4-font-size"><?php echo esc_html( $heading ); ?></h2>

	<ul class="wporg-event-attendees__list">
		<?php array_map( $render_attendee, $visible ); ?>
	</ul>

	<?php if ( $hidden ) : ?>
		<details class="wporg-event-attendees__more">
			<summary class="wporg-event-attendees__toggle">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: total number of attendees. */
						__( 'Show all %s attendees', 'wordcamporg' ),
						number_format_i18n( $count )
					)
				);
				?>
			</summary>
			<ul class="wporg-event-attendees__list">
				<?php array_map( $render_attendee, $hidden ); ?>
			</ul>
		</details>
	<?php endif; ?>
</section>
