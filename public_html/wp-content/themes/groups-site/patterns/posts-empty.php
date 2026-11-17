<?php
/**
 * Title: Posts empty state
 * Slug: groups-site/posts-empty
 * Categories: groups-site
 * Inserter: no
 *
 * The no-results message for the blog posts index.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Patterns\PostsEmpty;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:paragraph -->
<p><?php esc_html_e( 'No content found.', 'wordcamporg' ); ?></p>
<!-- /wp:paragraph -->
