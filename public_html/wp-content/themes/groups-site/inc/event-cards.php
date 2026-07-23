<?php
/**
 * Shared helpers for the WordPress Group event-card patterns.
 *
 * Both the front-page upcoming list and the archive (upcoming + past)
 * page render their event lists as a CSS-grid of cards via custom
 * patterns. This file holds the bits both patterns need to share so we
 * don't end up duplicating ~80 lines of date-formatting and Event_Query
 * iteration logic in two places:
 *
 *   - `format_card_datetime( $event )`     short, organizer-friendly date.
 *   - `extract_card_excerpt( $post_id )`   description-only excerpt with
 *                                           the GatherPress metadata
 *                                           blocks stripped.
 *   - `render_event_cards( $query, $opts )` outputs the `.groups-site-event-cards`
 *                                           grid for a WP_Query of events.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Event_Cards;

use GatherPress\Core\Event\Event;
use GatherPress\Core\Rsvp\Rsvp;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Format an event's start/end datetimes as a short, card-friendly label.
 *
 * Examples:
 *   - Same day, same am/pm:   "April 13th, 6–8pm"
 *   - Same day, mixed am/pm:  "April 13th, 11am–1pm"
 *   - Same day, with minutes: "April 13th, 6:30–8pm"
 *   - Multi-day:              "April 13th – April 14th"
 *
 * Reads the local-timezone meta keys (`gatherpress_datetime_start` /
 * `gatherpress_datetime_end`) directly rather than going through the
 * Event class's unix-timestamp accessor — formatting from the unix
 * timestamp would silently shift hours away from the organizer's
 * intent for any event in a non-UTC timezone.
 */
function format_card_datetime( Event $event ): string {
	$post_id = $event->event ? (int) $event->event->ID : 0;
	if ( ! $post_id ) {
		return $event->get_display_datetime();
	}

	$start = (string) get_post_meta( $post_id, 'gatherpress_datetime_start', true );
	$end   = (string) get_post_meta( $post_id, 'gatherpress_datetime_end', true );

	if ( '' === $start || '' === $end ) {
		return $event->get_display_datetime();
	}

	if (
		! preg_match( '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):/', $start, $sm )
		|| ! preg_match( '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):/', $end, $em )
	) {
		return $event->get_display_datetime();
	}

	$fmt_date = static function ( int $year, int $month, int $day ): string {
		return gmdate( 'F jS', gmmktime( 12, 0, 0, $month, $day, $year ) );
	};

	$start_date = $fmt_date( (int) $sm[1], (int) $sm[2], (int) $sm[3] );
	$end_date   = $fmt_date( (int) $em[1], (int) $em[2], (int) $em[3] );

	if ( $start_date !== $end_date ) {
		return sprintf( '%s – %s', $start_date, $end_date );
	}

	$fmt_time = static function ( int $hour24, int $minute ): array {
		$meridiem = $hour24 >= 12 ? 'pm' : 'am';
		$hour12   = $hour24 % 12;
		if ( 0 === $hour12 ) {
			$hour12 = 12;
		}
		$mins = ( 0 === $minute ) ? '' : ':' . str_pad( (string) $minute, 2, '0', STR_PAD_LEFT );
		return array( (string) $hour12 . $mins, $meridiem );
	};

	[ $start_label, $start_meridiem ] = $fmt_time( (int) $sm[4], (int) $sm[5] );
	[ $end_label, $end_meridiem ]     = $fmt_time( (int) $em[4], (int) $em[5] );

	if ( $start_meridiem === $end_meridiem ) {
		return sprintf( '%s, %s–%s%s', $start_date, $start_label, $end_label, $end_meridiem );
	}

	return sprintf( '%s, %s%s–%s%s', $start_date, $start_label, $start_meridiem, $end_label, $end_meridiem );
}

/**
 * Pull a description excerpt out of an event's post_content for the card.
 *
 * Prefers the manual excerpt; falls back to concatenating `core/paragraph`
 * blocks (skipping the GatherPress metadata blocks that get seeded into
 * default event content).
 *
 * @param int $post_id    Event post ID.
 * @param int $word_limit Words to keep before truncating with an ellipsis.
 */
function extract_card_excerpt( int $post_id, int $word_limit = 22 ): string {
	$raw = (string) get_post_field( 'post_excerpt', $post_id );

	if ( '' === $raw ) {
		$paragraphs = array();
		foreach ( parse_blocks( (string) get_post_field( 'post_content', $post_id ) ) as $block ) {
			if ( 'core/paragraph' === $block['blockName'] ) {
				$paragraphs[] = wp_strip_all_tags( $block['innerHTML'] );
			}
		}
		$raw = trim( implode( ' ', array_filter( $paragraphs ) ) );
	}

	return $raw ? wp_trim_words( $raw, $word_limit, '…' ) : '';
}

/**
 * Render the `.groups-site-event-cards` grid for a query of events.
 *
 * Outputs nothing if the query has no posts (the caller is responsible
 * for showing an empty-state message). Each card links to the event
 * permalink and shows: media (featured image or placeholder), date,
 * title, optional excerpt, optional venue. The HTML is identical for
 * compact and expanded variants — the visual difference is driven by
 * the wrapper class names declared on the parent `.groups-site-event-cards`
 * element by the caller via `$opts['classes']`.
 *
 * @param WP_Query $query Event query (gatherpress_event posts).
 * @param array    $opts  Optional: `classes` (string) extra CSS classes,
 *                        `excerpt_words` (int) words to keep in excerpt,
 *                        `is_past` (bool) treat as past events for muted
 *                        visual styling.
 */
function render_event_cards( WP_Query $query, array $opts = array() ): void {
	if ( ! $query->have_posts() ) {
		return;
	}

	$opts = array_merge(
		array(
			'classes'       => '',
			'excerpt_words' => 22,
			'is_past'       => false,
		),
		$opts
	);

	$wrap_classes = trim( 'groups-site-event-cards ' . $opts['classes'] );
	if ( $opts['is_past'] ) {
		$wrap_classes .= ' groups-site-event-cards--past';
	}

	?>
	<div class="<?php echo esc_attr( $wrap_classes ); ?>">
	<?php
	foreach ( $query->posts as $event_post ) :
		$post_id = $event_post instanceof \WP_Post ? (int) $event_post->ID : (int) $event_post;
		if ( ! $post_id ) {
			continue;
		}

		$event     = new Event( $post_id );
		$rsvp      = new Rsvp( $post_id );
		$permalink = get_permalink( $post_id );
		$thumb     = get_the_post_thumbnail_url( $post_id, 'large' );
		$title     = get_the_title( $post_id );
		$datetime  = format_card_datetime( $event );
		$excerpt   = extract_card_excerpt( $post_id, (int) $opts['excerpt_words'] );
		$venue     = $event->get_venue_information();
		$venue_lbl = $venue['name'] ?? '';
		$responses = $rsvp->responses();
		$attending = (int) ( $responses['attending']['count'] ?? 0 );
		?>
		<a class="groups-site-event-card" href="<?php echo esc_url( $permalink ); ?>">
			<?php if ( $thumb ) : ?>
				<div class="groups-site-event-card__media" style="background-image:url(<?php echo esc_url( $thumb ); ?>);" aria-hidden="true"></div>
			<?php else : ?>
				<div class="groups-site-event-card__media groups-site-event-card__media--placeholder" aria-hidden="true"></div>
			<?php endif; ?>

			<div class="groups-site-event-card__body">
				<p class="groups-site-event-card__date"><?php echo esc_html( $datetime ); ?></p>
				<h3 class="groups-site-event-card__title"><?php echo esc_html( $title ); ?></h3>
				<?php if ( $excerpt ) : ?>
					<p class="groups-site-event-card__excerpt"><?php echo esc_html( $excerpt ); ?></p>
				<?php endif; ?>
				<?php if ( $attending > 0 ) : ?>
					<p class="groups-site-event-card__rsvp">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: formatted attendee count */
								_n( '%s going', '%s going', $attending, 'groups-site' ),
								number_format_i18n( $attending )
							)
						);
						?>
					</p>
				<?php endif; ?>
				<?php if ( $venue_lbl ) : ?>
					<p class="groups-site-event-card__venue">
						<span class="dashicons dashicons-location groups-site-event-card__icon" aria-hidden="true"></span>
						<?php echo esc_html( $venue_lbl ); ?>
					</p>
				<?php endif; ?>
			</div>
		</a>
	<?php endforeach; ?>
	</div>
	<?php
}
