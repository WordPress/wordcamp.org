<?php
/**
 * Title: Event - date and time label
 * Slug: groups-site/event-date-label
 * Categories: groups-site
 * Inserter: no
 *
 * The "Date and time" label above an event's date on the single event page
 * and its printable flyer.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Patterns\EventDateLabel;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:paragraph {"className":"groups-site-event-label","textColor":"charcoal-4","fontSize":"extra-small","style":{"typography":{"fontWeight":"600"},"spacing":{"margin":{"top":"0","bottom":"0"}}}} -->
<p class="groups-site-event-label has-charcoal-4-color has-text-color has-extra-small-font-size" style="margin-top:0;margin-bottom:0;font-weight:600"><?php esc_html_e( 'Date and time', 'wordcamporg' ); ?></p>
<!-- /wp:paragraph -->
