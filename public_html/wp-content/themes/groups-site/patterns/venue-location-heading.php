<?php
/**
 * Title: Venue location heading
 * Slug: groups-site/venue-location-heading
 * Categories: groups-site
 * Inserter: no
 *
 * The "Location" heading above a venue's address and map.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Patterns\VenueLocationHeading;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:heading {"level":2,"fontSize":"heading-4","style":{"spacing":{"margin":{"bottom":"var:preset|spacing|20"}}}} -->
<h2 class="wp-block-heading has-heading-4-font-size" style="margin-bottom:var(--wp--preset--spacing--20)"><?php esc_html_e( 'Location', 'wordcamporg' ); ?></h2>
<!-- /wp:heading -->
