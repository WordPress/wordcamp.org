<?php
/**
 * Title: Archive - events header
 * Slug: groups-site/archive-events-header
 * Categories: groups-site
 * Inserter: no
 *
 * The title and introduction at the top of the events archive.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Patterns\ArchiveEventsHeader;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|10"}},"layout":{"type":"constrained","justifyContent":"left"}} -->
<div class="wp-block-group">
	<!-- wp:heading {"level":1} -->
	<h1 class="wp-block-heading"><?php esc_html_e( 'Events', 'wordcamporg' ); ?></h1>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"fontSize":"small","textColor":"charcoal-3","className":"groups-site-events-intro"} -->
	<p class="groups-site-events-intro has-charcoal-3-color has-text-color has-small-font-size"><?php echo wp_kses_post( __( 'Meetups, workshops, and socials — see what&rsquo;s coming up, or browse everything the group has hosted.', 'wordcamporg' ) ); ?></p>
	<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
