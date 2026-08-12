<?php
/**
 * Title: Upcoming events (cards)
 * Slug: groups-site/upcoming-events-cards
 * Categories: groups-site
 * Inserter: no
 *
 * Renders a compact card grid of the next 3 upcoming events on the
 * current site. Used on the front page just below the hero. Both this
 * pattern and the archive's `archive-upcoming-events-cards` pattern
 * share their card markup via the `WordCamp\Groups\Site\Event_Cards` helper
 * — see `inc/event-cards.php`.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Patterns\UpcomingEventsCards;

use GatherPress\Core\Event\Query as Event_Query;
use function WordCamp\Groups\Site\Event_Cards\render_event_cards;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Event_Query::class ) ) {
	return;
}

$query = Event_Query::get_instance()->get_upcoming_events( 3 );

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
		'classes'       => 'groups-site-event-cards--compact',
		'excerpt_words' => 22,
	)
);

wp_reset_postdata();
