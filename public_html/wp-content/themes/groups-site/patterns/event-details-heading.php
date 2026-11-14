<?php
/**
 * Title: Event - details heading
 * Slug: groups-site/event-details-heading
 * Categories: groups-site
 * Inserter: no
 *
 * The "Details" heading above an event's description.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Patterns\EventDetailsHeading;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:heading {"level":2,"fontSize":"heading-4","style":{"spacing":{"margin":{"bottom":"var:preset|spacing|20"}}}} -->
<h2 class="wp-block-heading has-heading-4-font-size" style="margin-bottom:var(--wp--preset--spacing--20)"><?php esc_html_e( 'Details', 'wordcamporg' ); ?></h2>
<!-- /wp:heading -->
