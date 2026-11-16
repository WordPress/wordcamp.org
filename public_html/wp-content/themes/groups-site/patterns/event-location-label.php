<?php
/**
 * Title: Event - location label
 * Slug: groups-site/event-location-label
 * Categories: groups-site
 * Inserter: no
 *
 * The "Location" label above the venue in the single event info card.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Patterns\EventLocationLabel;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:paragraph {"className":"groups-site-event-label","textColor":"charcoal-4","fontSize":"extra-small","style":{"typography":{"fontWeight":"600"},"spacing":{"margin":{"top":"0","bottom":"var:preset|spacing|10"}}}} -->
<p class="groups-site-event-label has-charcoal-4-color has-text-color has-extra-small-font-size" style="margin-top:0;margin-bottom:var(--wp--preset--spacing--10);font-weight:600"><?php esc_html_e( 'Location', 'wordcamporg' ); ?></p>
<!-- /wp:paragraph -->
