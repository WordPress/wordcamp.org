<?php
/**
 * Title: Posts heading
 * Slug: groups-site/posts-heading
 * Categories: groups-site
 * Inserter: no
 *
 * The title of the blog posts index.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Patterns\PostsHeading;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:heading {"level":1,"fontSize":"heading-2","style":{"spacing":{"margin":{"bottom":"var:preset|spacing|50"}}}} -->
<h1 class="wp-block-heading has-heading-2-font-size" style="margin-bottom:var(--wp--preset--spacing--50)"><?php esc_html_e( 'Latest posts', 'wordcamporg' ); ?></h1>
<!-- /wp:heading -->
