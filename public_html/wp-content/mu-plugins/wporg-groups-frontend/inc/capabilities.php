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
 * "Organizers", authors are "Event Organizers".
 *
 * Checked by role rather than by a raw capability (e.g. `edit_posts`) so
 * that a stray `contributor` account doesn't slip in — contributors also
 * have `edit_posts` in core, but this plugin treats them as plain
 * "Member" tier, not as event managers.
 */
const EVENT_MANAGER_ROLES = array( 'administrator', 'editor', 'author' );

/**
 * WordPress roles that make up the full "Organizer" tier — a narrower set
 * than `EVENT_MANAGER_ROLES`, since it excludes authors ("Event
 * Organizers"), who only manage their own events. Single source for the
 * role-set checked when deciding whether a member can be left without an
 * organizer, or whether they're shown the "Organizer" label.
 */
const ORGANIZER_ROLES = array( 'administrator', 'editor' );

/**
 * Translated display label for a member's role tier.
 *
 * Mirrors `Members_Controller::ROLE_LABELS`, which stays untranslated
 * because it's also read directly by `group-members/render.php` and
 * asserted against verbatim in `test-class-members-controller.php`'s REST
 * response checks. This is the translated counterpart for front-end markup
 * rendered directly by PHP (not consumed as REST JSON), such as the
 * `group-membership` block.
 */
function get_role_tier_label( string $role ): string {
	$labels = array(
		'administrator' => __( 'Organizer', 'wporg-groups-frontend' ),
		'editor'        => __( 'Organizer', 'wporg-groups-frontend' ),
		'author'        => __( 'Event Organizer', 'wporg-groups-frontend' ),
	);

	return $labels[ $role ] ?? __( 'Member', 'wporg-groups-frontend' );
}

/**
 * Whether the current user is allowed to create / edit events on this group.
 *
 * Authors ("Event Organizers") can create and manage their own events; the
 * REST layer's per-post capability checks (`edit_post`/`publish_post`)
 * already restrict them to events they own. Editors and administrators
 * ("Organizers") can manage everyone's events.
 *
 * Super admins are recognised explicitly because this is a role-array check
 * rather than a `current_user_can()` capability check (see the docblock on
 * `EVENT_MANAGER_ROLES`) — unlike `current_user_can_manage_group_settings()`,
 * it doesn't automatically pick up core's super-admin capability elevation.
 * Without this, a super admin whose nominal role on a given group is
 * `subscriber` (e.g. a deputy who isn't the group's own organizer) would see
 * the "Set up your group" button — gated by `current_user_can_manage_group_settings()`,
 * which does elevate — but the modal would render invisibly, because the
 * `wp-components`/`wp-block-editor` styles enqueued in `Modal::enqueue_supplementary_assets()`
 * are gated on this function.
 */
function current_user_can_manage_events(): bool {
	if ( ! is_user_logged_in() ) {
		return false;
	}

	$user_can = is_super_admin() || (bool) array_intersect( EVENT_MANAGER_ROLES, wp_get_current_user()->roles );

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
 * (venues, group info, member role management) — the full "Organizer"
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

/**
 * Group sites where members may change their own role tier, keyed by the
 * host the group lives on.
 *
 * Self-serve role switching is a beta-testing affordance, not a product
 * feature: promoting yourself to `editor` grants the full "Organizer" tier
 * (everyone's events, member roles, group info and design, ownership
 * transfer), so on a real community group it would be a privilege
 * escalation for any logged-in WordPress.org account that joined. The
 * allow-list keeps it to the groups that exist to be poked at.
 *
 * Keyed by host rather than by slug alone so a real community group that
 * happens to share a slug with a local fixture can't inherit it. Sites are
 * added here by code change on purpose — there's no admin toggle, because
 * nobody should be able to switch this on for a live group from the UI.
 *
 * @var array<string, string[]>
 */
const SELF_SERVE_ROLE_GROUPS = array(
	// The beta's public testing group (see the "Call for testing" post).
	'events.wordpress.org'  => array( 'internal-testing-group' ),

	// Local development and the PHPUnit fixture site.
	'events.wordpress.test' => array( 'sunshine-coast-qld' ),
);

/**
 * The `/group/{slug}/` segment identifying the current group site.
 *
 * Returns an empty string off the groups network (or on its `/group/`
 * root, which is the landing page rather than a group).
 */
function get_current_group_slug(): string {
	$site = get_site();

	if ( ! $site ) {
		return '';
	}

	$segments = explode( '/', trim( (string) $site->path, '/' ) );
	$slug     = (string) end( $segments );

	return 'group' === $slug ? '' : $slug;
}

/**
 * Whether this group site offers self-serve role switching.
 *
 * See `SELF_SERVE_ROLE_GROUPS` for why this is an allow-list.
 */
function self_serve_roles_enabled(): bool {
	$site    = get_site();
	$allowed = $site ? ( SELF_SERVE_ROLE_GROUPS[ $site->domain ] ?? array() ) : array();
	$slug    = get_current_group_slug();
	$enabled = '' !== $slug && in_array( $slug, $allowed, true );

	/**
	 * Filters whether members of this group can change their own role tier.
	 *
	 * Sandboxes and one-off testing sites can opt in through this filter
	 * rather than by editing `SELF_SERVE_ROLE_GROUPS`.
	 *
	 * @param bool $enabled Whether self-serve role switching is available.
	 */
	return (bool) apply_filters( 'wporg_groups_frontend_self_serve_roles_enabled', $enabled );
}

/**
 * Whether the current user may change their own role tier on this group.
 *
 * The authoritative check behind both the members-page UI and the
 * `POST /members/me/role` endpoint, so the button and the request that
 * button makes can't disagree about who's allowed.
 *
 * Administrators are excluded for the same reason `update_member_role()`
 * refuses to touch them: the group's admin account is provisioned in
 * wp-admin, and letting it demote itself from the front end could strand
 * the site with no one able to restore it.
 */
function current_user_can_switch_own_role(): bool {
	if ( ! is_user_logged_in() || ! self_serve_roles_enabled() ) {
		return false;
	}

	if ( ! is_user_member_of_blog() ) {
		return false;
	}

	return ! in_array( 'administrator', wp_get_current_user()->roles, true );
}
