<?php
/**
 * Title: Archive — upcoming events (expanded cards)
 * Slug: groups-site/archive-upcoming-events-cards
 * Categories: groups-site
 * Inserter: no
 *
 * Renders the "Upcoming" section of the events archive page as an
 * expanded card grid (more padding, longer description preview) showing
 * up to 50 events. Sister pattern to `archive-past-events-cards`.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Patterns\ArchiveUpcomingEventsCards;

use GatherPress\Core\Blocks\Event_Query;
use function WordCamp\Groups\Site\Event_Cards\render_event_cards;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Event_Query::class ) ) {
	return;
}

$query = Event_Query::get_instance()->get_upcoming_events( 50 );

if ( ! $query->have_posts() ) {
	?>
	<!-- wp:paragraph {"textColor":"charcoal-4"} -->
	<p class="has-charcoal-4-color has-text-color">No upcoming events scheduled. Check back soon.</p>
	<!-- /wp:paragraph -->
	<?php
	wp_reset_postdata();
	return;
}

render_event_cards(
	$query,
	array(
		'classes'       => 'groups-site-event-cards--expanded',
		'excerpt_words' => 50,
	)
);

wp_reset_postdata();
