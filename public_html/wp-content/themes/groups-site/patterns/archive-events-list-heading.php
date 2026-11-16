<?php
/**
 * Title: Archive - event list heading
 * Slug: groups-site/archive-events-list-heading
 * Categories: groups-site
 * Inserter: no
 *
 * The screen-reader-only heading that introduces the list of events in the
 * archive.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Patterns\ArchiveEventsListHeading;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:heading {"className":"screen-reader-text"} -->
<h2 class="wp-block-heading screen-reader-text"><?php esc_html_e( 'Event list', 'wordcamporg' ); ?></h2>
<!-- /wp:heading -->
