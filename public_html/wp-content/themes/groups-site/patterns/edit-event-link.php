<?php
/**
 * Title: Edit event link
 * Slug: groups-site/edit-event-link
 * Categories: groups-site
 * Inserter: no
 *
 * Renders an "Edit event" button below the title on a single-event view,
 * but only for users who can manage events on this group. Clicking the
 * button opens the same React modal used for creation, in `edit` mode and
 * pre-loaded with the current event's data.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Patterns\EditEventLink;

use function WordCamp\Groups\Frontend\Capabilities\current_user_can_manage_events;

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can_manage_events() ) {
	return;
}

$current_post = get_post();
if ( ! $current_post || 'gatherpress_event' !== $current_post->post_type ) {
	return;
}
?>
<!-- wp:html -->
<button
	type="button"
	class="groups-site-edit-event-link"
	data-wporg-groups-modal="edit"
	data-wporg-groups-event-id="<?php echo (int) $current_post->ID; ?>"
	style="background:none;border:none;padding:0;color:var(--wp--preset--color--blueberry-1);font:inherit;font-size:14px;cursor:pointer;text-decoration:underline;"
>&#9998; Edit this event</button>
<!-- /wp:html -->
