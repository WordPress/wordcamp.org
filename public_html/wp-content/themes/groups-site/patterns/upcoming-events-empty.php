<?php
/**
 * Title: Upcoming events empty state
 * Slug: groups-site/upcoming-events-empty
 * Categories: groups-site
 * Inserter: no
 *
 * The no-results message for the front page's upcoming events list.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Patterns\UpcomingEventsEmpty;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:paragraph {"textColor":"charcoal-4"} -->
<p class="has-charcoal-4-color has-text-color"><?php esc_html_e( 'No upcoming events yet. Check back soon.', 'wordcamporg' ); ?></p>
<!-- /wp:paragraph -->
