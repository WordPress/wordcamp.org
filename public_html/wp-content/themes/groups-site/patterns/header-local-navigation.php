<?php
/**
 * Title: Local Navigation
 * Slug: groups-site/header-local-navigation
 * Inserter: no
 *
 * The wordpress.org-style sub-header rendered below the global header: the
 * group's name on the left, quick links on the right. The
 * `wporg/local-navigation-bar` block (registered globally from
 * `wporg-mu-plugins/pub-sync`) supplies the sticky behaviour, the W logo
 * mark that fades in on scroll, and the collapsed fallback menu at narrow
 * widths. The menu items come from the `wporg_block_navigation_menus`
 * filter in `functions.php`, so no per-site menu provisioning is needed.
 *
 * The bottom border only shows while the bar is sticking — the block's own
 * stylesheet suppresses it otherwise, when the global header's border
 * already separates it from the page.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Patterns\HeaderLocalNavigation;

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:wporg/local-navigation-bar {"className":"has-display-contents","backgroundColor":"white","textColor":"charcoal-1","fontSize":"small","style":{"border":{"bottom":{"color":"var:preset|color|light-grey-1","style":"solid","width":"1px"}}}} -->

	<!-- wp:site-title {"level":0,"fontSize":"small"} /-->

	<!-- wp:navigation {"menuSlug":"local-navigation","icon":"menu","textColor":"blueberry-1","overlayBackgroundColor":"white","overlayTextColor":"charcoal-1","layout":{"type":"flex","orientation":"horizontal"},"fontSize":"small"} /-->

<!-- /wp:wporg/local-navigation-bar -->
