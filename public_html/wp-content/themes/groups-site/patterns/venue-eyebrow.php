<?php
/**
 * Title: Venue eyebrow
 * Slug: groups-site/venue-eyebrow
 * Categories: groups-site
 * Inserter: no
 *
 * The "Venue" label above the title of a single venue.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Patterns\VenueEyebrow;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:paragraph {"fontSize":"small","textColor":"blueberry-1","style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.08em","fontWeight":"600"},"spacing":{"margin":{"bottom":"var:preset|spacing|10"}}}} -->
<p class="has-blueberry-1-color has-text-color has-small-font-size" style="margin-bottom:var(--wp--preset--spacing--10);font-weight:600;letter-spacing:0.08em;text-transform:uppercase"><?php esc_html_e( 'Venue', 'wordcamporg' ); ?></p>
<!-- /wp:paragraph -->
