<?php
/**
 * Title: Discussion heading
 * Slug: groups-site/discussion-heading
 * Categories: groups-site
 * Inserter: no
 *
 * The "Discussion" heading above the comments on posts and events.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Patterns\DiscussionHeading;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:heading {"level":2,"fontSize":"heading-4","style":{"spacing":{"margin":{"bottom":"var:preset|spacing|20"}}}} -->
<h2 class="wp-block-heading has-heading-4-font-size" style="margin-bottom:var(--wp--preset--spacing--20)"><?php esc_html_e( 'Discussion', 'wordcamporg' ); ?></h2>
<!-- /wp:heading -->
