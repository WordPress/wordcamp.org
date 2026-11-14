<?php
/**
 * Title: Upcoming events heading
 * Slug: groups-site/upcoming-events-heading
 * Categories: groups-site
 * Inserter: no
 *
 * The "Upcoming events" section heading on the front page.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Patterns\UpcomingEventsHeading;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:heading {"level":2,"fontSize":"heading-3","style":{"spacing":{"margin":{"bottom":"0"}}}} -->
<h2 class="wp-block-heading has-heading-3-font-size" style="margin-bottom:0"><?php esc_html_e( 'Upcoming events', 'wordcamporg' ); ?></h2>
<!-- /wp:heading -->
