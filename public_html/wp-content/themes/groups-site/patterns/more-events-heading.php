<?php
/**
 * Title: Event - more events heading
 * Slug: groups-site/more-events-heading
 * Categories: groups-site
 * Inserter: no
 *
 * The "More events from this group" heading above the related events on a
 * single event.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Patterns\MoreEventsHeading;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:heading {"level":2,"fontSize":"heading-3","style":{"spacing":{"margin":{"bottom":"0"}}}} -->
<h2 class="wp-block-heading has-heading-3-font-size" style="margin-bottom:0"><?php esc_html_e( 'More events from this group', 'wordcamporg' ); ?></h2>
<!-- /wp:heading -->
