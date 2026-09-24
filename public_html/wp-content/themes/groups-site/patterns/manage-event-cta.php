<?php
/**
 * Title: Manage event CTA
 * Slug: groups-site/manage-event-cta
 * Categories: groups-site
 * Inserter: no
 *
 * Renders a "Create event" button in the header for users with the
 * `edit_others_posts` capability. Visitors and unprivileged users see
 * nothing. Used inside the theme's header template part.
 *
 * Uses a `<button>` with `data-wporg-groups-modal="create"` so the
 * front-end React modal app (in the wporg-groups-frontend mu-plugin)
 * can attach a click handler and open the create-event modal in place.
 * No URL — there's no `/create-event/` page anymore.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Patterns\ManageEventCta;

use function WordCamp\Groups\Frontend\Capabilities\current_user_can_manage_events;

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can_manage_events() ) {
	return;
}
?>
<!-- wp:html -->
<button
	type="button"
	class="wp-block-button__link wp-element-button has-white-color has-blueberry-1-background-color has-text-color has-background groups-site-header-cta"
	data-wporg-groups-modal="create"
	style="padding:10px 18px;font-size:14px;font-weight:600;border-radius:2px;border:none;cursor:pointer;"
>+ Create event</button>
<!-- /wp:html -->
