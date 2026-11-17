<?php
/**
 * Title: Event - part of label
 * Slug: groups-site/event-part-of
 * Categories: groups-site
 * Inserter: no
 *
 * The "Part of" label before the group name in the single event hero.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Patterns\EventPartOf;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:paragraph {"textColor":"charcoal-3"} -->
<p class="has-charcoal-3-color has-text-color"><?php esc_html_e( 'Part of', 'wordcamporg' ); ?></p>
<!-- /wp:paragraph -->
