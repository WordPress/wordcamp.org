<?php
/**
 * Capability helpers for the groups frontend mu-plugin.
 *
 * Centralises the "can this user manage events on this group?" check so the
 * block, the routing layer, and the form handler all agree.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\Capabilities;

defined( 'WPINC' ) || die();

/**
 * WordPress roles that can create and manage their own events on a group
 * site. Mirrors the role tiers surfaced elsewhere in this plugin (see
 * `Members_Controller::ROLE_LABELS`): administrators and editors are full
 * "Organisers", authors are "Event Organisers".
 *
 * Checked by role rather than by a raw capability (e.g. `edit_posts`) so
 * that a stray `contributor` account doesn't slip in — contributors also
 * have `edit_posts` in core, but this plugin treats them as plain
 * "Member" tier, not as event managers.
 */
const EVENT_MANAGER_ROLES = array( 'administrator', 'editor', 'author' );

/**
 * Whether the current user is allowed to create / edit events on this group.
 *
 * Authors ("Event Organisers") can create and manage their own events; the
 * REST layer's per-post capability checks (`edit_post`/`publish_post`)
 * already restrict them to events they own. Editors and administrators
 * ("Organisers") can manage everyone's events.
 */
function current_user_can_manage_events(): bool {
	if ( ! is_user_logged_in() ) {
		return false;
	}

	$user_can = (bool) array_intersect( EVENT_MANAGER_ROLES, wp_get_current_user()->roles );

	/**
	 * Filters the capability check used by the front-end event management UI.
	 *
	 * @param bool $allowed Whether the current user can manage events.
	 */
	return (bool) apply_filters(
		'wporg_groups_frontend_user_can_manage_events',
		$user_can
	);
}

/**
 * Whether the current user is allowed to access the group settings UI
 * (venues, group info, member role management) — the full "Organiser"
 * tier only (editors and administrators), distinct from event management.
 */
function current_user_can_manage_group_settings(): bool {
	if ( ! is_user_logged_in() ) {
		return false;
	}

	/**
	 * Filters the capability check used by the front-end group settings UI.
	 *
	 * @param bool $allowed Whether the current user can manage group settings.
	 */
	return (bool) apply_filters(
		'wporg_groups_frontend_user_can_manage_group_settings',
		current_user_can( 'edit_others_posts' )
	);
}
