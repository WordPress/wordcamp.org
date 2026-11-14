<?php
/**
 * Title: Groups directory header
 * Slug: groups-site/groups-directory-header
 * Categories: groups-site
 * Inserter: no
 *
 * The title and introduction at the top of the groups directory.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Patterns\GroupsDirectoryHeader;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|10"}},"layout":{"type":"constrained","justifyContent":"left"}} -->
<div class="wp-block-group">
	<!-- wp:heading {"level":1} -->
	<h1 class="wp-block-heading"><?php esc_html_e( 'All groups', 'wordcamporg' ); ?></h1>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"fontSize":"small","textColor":"charcoal-3"} -->
	<p class="has-charcoal-3-color has-text-color has-small-font-size"><?php esc_html_e( 'Local WordPress communities that host meetups, workshops, and socials. Find one near you and join in.', 'wordcamporg' ); ?></p>
	<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
