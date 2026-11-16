<?php
/**
 * Title: Search empty state
 * Slug: groups-site/search-empty
 * Categories: groups-site
 * Inserter: no
 *
 * The no-results message on the search results page.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Patterns\SearchEmpty;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:paragraph {"textColor":"charcoal-4"} -->
<p class="has-charcoal-4-color has-text-color"><?php esc_html_e( 'No results found. Try a different search term.', 'wordcamporg' ); ?></p>
<!-- /wp:paragraph -->
