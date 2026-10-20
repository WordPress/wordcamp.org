<?php
/**
 * Title: View all events link
 * Slug: groups-site/view-all-events-link
 * Categories: groups-site
 * Inserter: no
 *
 * Renders a "View all events →" link that resolves to the registered event
 * archive. Used on the front page next to the "Upcoming events" section
 * heading so the link works on path-based multisite installs.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Patterns\ViewAllEventsLink;

defined( 'ABSPATH' ) || exit;

$events_url = get_post_type_archive_link( 'gatherpress_event' ) ?: home_url( '/event/' );
?>
<!-- wp:paragraph {"fontSize":"small"} -->
<p class="has-small-font-size"><a href="<?php echo esc_url( $events_url ); ?>">View all events &rarr;</a></p>
<!-- /wp:paragraph -->
