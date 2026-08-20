<?php
/**
 * Title: Event card
 * Slug: groups-site/event-card
 * Categories: groups-site
 * Inserter: no
 *
 * The single event card used inside `gatherpress-event-query` query loops —
 * the front page's "Upcoming events" section and the events archive both
 * reference this pattern from their `wp:post-template`, so the card is
 * defined once. Card-level polish (equal heights, bottom-pinned meta,
 * placeholder media, past-view muting) lives in `custom.css` under the
 * query-loop section.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Patterns\EventCard;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"style":{"spacing":{"blockGap":"0"},"border":{"width":"1px","color":"var:preset|color|light-grey-1","radius":"4px"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group has-border-color" style="border-color:var(--wp--preset--color--light-grey-1);border-width:1px;border-radius:4px">
	<!-- wp:post-featured-image {"isLink":true,"aspectRatio":"16/9","style":{"border":{"radius":{"topLeft":"3px","topRight":"3px","bottomLeft":"0px","bottomRight":"0px"}}}} /-->
	<!-- wp:group {"style":{"spacing":{"padding":{"top":"24px","right":"24px","bottom":"24px","left":"24px"},"blockGap":"var:preset|spacing|10"}},"layout":{"type":"constrained"}} -->
	<div class="wp-block-group" style="padding-top:24px;padding-right:24px;padding-bottom:24px;padding-left:24px">
		<!-- wp:gatherpress/event-date {"displayType":"start","startDateFormat":"M j, Y","showTimezone":"no","fontSize":"small","textColor":"blueberry-1","style":{"typography":{"fontWeight":"600","textTransform":"uppercase","letterSpacing":"0.04em"}}} /-->
		<!-- wp:post-title {"level":3,"isLink":true,"fontSize":"heading-6","style":{"spacing":{"margin":{"top":"0"}},"typography":{"lineHeight":"1.2"}}} /-->
		<!-- wp:post-excerpt {"moreText":"","excerptLength":25,"fontSize":"small","textColor":"charcoal-3"} /-->
		<!-- wp:group {"className":"groups-site-card-meta","style":{"spacing":{"blockGap":"4px"}},"layout":{"type":"constrained"}} -->
		<div class="wp-block-group groups-site-card-meta">
			<!-- wp:gatherpress/rsvp-count {"fontSize":"small","textColor":"charcoal-4","style":{"typography":{"fontWeight":"600"}}} /-->
			<!-- wp:gatherpress/venue -->
				<!-- wp:post-title {"level":4,"fontSize":"small","fontFamily":"inter","textColor":"charcoal-4","style":{"spacing":{"margin":{"top":"0","bottom":"0"}},"typography":{"fontWeight":"400","lineHeight":"1.55"}}} /-->
			<!-- /wp:gatherpress/venue -->
		</div>
		<!-- /wp:group -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
