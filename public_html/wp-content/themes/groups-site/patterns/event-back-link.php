<?php
/**
 * Title: Event back link
 * Slug: groups-site/event-back-link
 * Categories: groups-site
 * Inserter: no
 *
 * Renders an "← All events" link that resolves to the current site's events
 * archive (`home_url('/event/')`). Used at the top of single-event and
 * single-venue templates so the link works on path-based multisite installs.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Patterns\EventBackLink;

defined( 'ABSPATH' ) || exit;

$events_url = home_url( '/event/' );
?>
<!-- wp:paragraph {"fontSize":"small","style":{"spacing":{"margin":{"bottom":"var:preset|spacing|30"}}}} -->
<p class="has-small-font-size" style="margin-bottom:var(--wp--preset--spacing--30)"><a href="<?php echo esc_url( $events_url ); ?>">&larr; All events</a></p>
<!-- /wp:paragraph -->
