<?php
/**
 * Title: Flyer - location label
 * Slug: groups-site/flyer-location-label
 * Categories: groups-site
 * Inserter: no
 *
 * The "Location" label above the venue on an event's printable flyer.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Patterns\FlyerLocationLabel;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:paragraph {"className":"groups-site-event-label","textColor":"charcoal-4","fontSize":"extra-small","style":{"typography":{"fontWeight":"600"},"spacing":{"margin":{"top":"0","bottom":"0"}}}} -->
<p class="groups-site-event-label has-charcoal-4-color has-text-color has-extra-small-font-size" style="margin-top:0;margin-bottom:0;font-weight:600"><?php esc_html_e( 'Location', 'wordcamporg' ); ?></p>
<!-- /wp:paragraph -->
