<?php
/**
 * Title: Become an organizer
 * Slug: groups-site/become-organizer
 * Categories: groups-site
 * Inserter: no
 *
 * Renders a small callout pointing visitors at the Meetup Organizer Handbook,
 * so someone browsing an event can find out how to help run a group or start
 * one. Used in the single event sidebar.
 *
 * Hidden from users who can already manage this group's events: they are
 * organizers, so the invitation is noise for them.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Patterns\BecomeOrganizer;

use function WordCamp\Groups\Frontend\Capabilities\current_user_can_manage_events;

defined( 'ABSPATH' ) || exit;

if ( current_user_can_manage_events() ) {
	return;
}
?>
<!-- wp:group {"className":"groups-site-become-organizer","backgroundColor":"blueberry-4","style":{"border":{"radius":"4px"},"spacing":{"margin":{"top":"var:preset|spacing|40"},"padding":{"top":"var:preset|spacing|40","bottom":"var:preset|spacing|40","left":"var:preset|spacing|40","right":"var:preset|spacing|40"},"blockGap":"var:preset|spacing|10"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group groups-site-become-organizer has-blueberry-4-background-color has-background" style="border-radius:4px;margin-top:var(--wp--preset--spacing--40);padding-top:var(--wp--preset--spacing--40);padding-right:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--40);padding-left:var(--wp--preset--spacing--40)">
	<!-- wp:heading {"level":2,"className":"groups-site-become-organizer__heading"} -->
	<h2 class="wp-block-heading groups-site-become-organizer__heading">Organize with us</h2>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"textColor":"charcoal-3","fontSize":"small"} -->
	<p class="has-charcoal-3-color has-text-color has-small-font-size">Help run this group, or start one in your own city.</p>
	<!-- /wp:paragraph -->

	<!-- wp:paragraph {"fontSize":"small","style":{"typography":{"fontWeight":"600"}}} -->
	<p class="has-small-font-size" style="font-weight:600"><a href="https://make.wordpress.org/community/handbook/meetup-organizer/">Become an organizer&nbsp;<span aria-hidden="true">↗</span></a></p>
	<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
