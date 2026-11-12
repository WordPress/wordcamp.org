<?php
/**
 * The public list of every group, at `events.wordpress.org/group/`.
 *
 * A group could only be found by someone who already had its address: the
 * footer's "All groups" link and `/group/` itself both went to the events
 * home, which lists events and no groups (#2036).
 *
 * It's served from the network root, the one URL on the network that isn't a
 * group. That site has no content and no theme of its own, and everything
 * else on it redirects, so three things are settled here rather than in its
 * options table:
 *
 * - `sunrise-groups.php` lets the request through. `is_directory_request()`
 *   below is the same check, for code that runs once WordPress has loaded.
 * - The request is rendered with the `groups-site` theme, whose
 *   `templates/groups-directory.html` holds the page.
 * - The `wporg/groups-directory` block draws the list. It is registered here
 *   because the rest of this plugin's blocks only load alongside GatherPress,
 *   which the list doesn't need.
 *
 * Only groups on this network are listed. Most WordPress groups are still on
 * Meetup.com and aren't imported until they move, so the page says the list
 * is incomplete and points to events near the visitor, which covers both.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\Groups_Directory;

use function WordCamp\Groups\Archive\get_group_sites;
use function WordCamp\Groups\Frontend\Blocks\register_block;
use function WordCamp\Groups\Frontend\Group_Location\get_location_label;

defined( 'WPINC' ) || die();

/** The theme that renders the list, and the parent theme it needs. */
const THEME        = 'groups-site';
const PARENT_THEME = 'wporg-parent-2021';

/**
 * The search field's query var. `sunrise-groups.php` allows this one and no
 * other, so the two have to be kept in step.
 */
const SEARCH_QUERY_VAR = 'q';

/** Longest search the list will act on. Longer ones are cut, not refused. */
const SEARCH_MAX_LENGTH = 100;

/** Object cache group and key for the list. */
const CACHE_GROUP = 'wporg-groups-directory';
const CACHE_KEY   = 'groups';

/**
 * How long the list is cached. Building it reads a name, an address and a
 * location from every group's own tables, which a public page can't afford to
 * do on each view. A new or renamed group shows up within this long.
 */
const CACHE_TTL = 15 * MINUTE_IN_SECONDS;

/** Where the move from Meetup.com to these group sites was announced. */
const ANNOUNCEMENT_URL = 'https://make.wordpress.org/project/2026/07/09/meetup-com-gatherpress/';

/**
 * Settings a page can't render without, for a root site that doesn't have
 * them. See `filter_default_option()`.
 *
 * - Without `blog_charset` and `html_type` the page is sent with no content
 *   type and an empty `<meta charset>`, and browsers read it as Windows-1252.
 * - Without `blog_public` WordPress asks search engines not to index the
 *   page. The list is meant to be found, like the group sites it links to.
 */
const DEFAULT_OPTIONS = array(
	'blog_charset' => 'UTF-8',
	'html_type'    => 'text/html',
	'blog_public'  => '1',
);

/**
 * Bootstrap the list of groups.
 */
function bootstrap(): void {
	add_filter( 'pre_option_stylesheet', __NAMESPACE__ . '\filter_stylesheet' );
	add_filter( 'pre_option_template', __NAMESPACE__ . '\filter_template' );
	add_action( 'init', __NAMESPACE__ . '\register_directory_block' );
	add_action( 'wp', __NAMESPACE__ . '\remove_discovery_links' );

	foreach ( array_keys( DEFAULT_OPTIONS ) as $option ) {
		add_filter( "default_option_{$option}", __NAMESPACE__ . '\filter_default_option', 10, 2 );
	}

	wp_cache_add_global_groups( array( CACHE_GROUP ) );
}

/**
 * Whether the current request is for the list of groups.
 *
 * Asks `sunrise-groups.php`, which decided the same thing before WordPress
 * loaded. That keeps one definition of the request, and means nothing here
 * applies to the root site's admin, login, cron or events feed.
 */
function is_directory_request(): bool {
	return GROUPS_ROOT_BLOG_ID === get_current_blog_id()
		&& function_exists( '\WordCamp\Sunrise\Groups\is_directory_request' )
		&& \WordCamp\Sunrise\Groups\is_directory_request();
}

/**
 * Render the list of groups with the group sites' theme.
 *
 * The root site was created as a placeholder and has no theme in its options
 * table, so WordPress would render the list with no theme at all. Answering
 * for the option here keeps the choice in code, where it deploys with the
 * template it depends on.
 *
 * @param mixed $stylesheet The value to use instead of the option, or false.
 *
 * @return mixed The group sites' theme for the list, the given value otherwise.
 */
function filter_stylesheet( $stylesheet ) {
	return is_directory_request() ? THEME : $stylesheet;
}

/**
 * Load that theme's parent too. See `filter_stylesheet()`.
 *
 * @param mixed $template The value to use instead of the option, or false.
 *
 * @return mixed The parent theme for the list, the given value otherwise.
 */
function filter_template( $template ) {
	return is_directory_request() ? PARENT_THEME : $template;
}

/**
 * Stand in for a setting the root site was never given.
 *
 * The root was created as a placeholder, without the settings every other
 * site gets when it's created. WordPress only asks for a default when a
 * setting is missing, so a root site that has one keeps it.
 *
 * @param mixed  $default_value The default WordPress would use.
 * @param string $option        The setting's name.
 *
 * @return mixed The list's default on the list, WordPress's otherwise.
 */
function filter_default_option( $default_value, $option ) {
	return is_directory_request() ? DEFAULT_OPTIONS[ $option ] : $default_value;
}

/**
 * Stop the list advertising addresses that don't answer.
 *
 * WordPress and GatherPress link every page to its site's feeds, calendars
 * and APIs. On the root site each of those redirects to the events home, so
 * a feed reader or a calendar that followed one would get a web page.
 */
function remove_discovery_links(): void {
	if ( ! is_directory_request() ) {
		return;
	}

	remove_action( 'wp_head', 'feed_links', 2 );
	remove_action( 'wp_head', 'feed_links_extra', 3 );
	remove_action( 'wp_head', 'rsd_link' );
	remove_action( 'wp_head', 'rest_output_link_wp_head' );
	remove_action( 'template_redirect', 'rest_output_link_header', 11 );

	if ( class_exists( '\GatherPress\Core\Calendar\Setup' ) ) {
		remove_action( 'wp_head', array( \GatherPress\Core\Calendar\Setup::get_instance(), 'alternate_links' ) );
	}
}

/**
 * Register the block that draws the list.
 */
function register_directory_block(): void {
	register_block( 'groups-directory' );
}

/**
 * The address of the list.
 */
function get_directory_url(): string {
	return get_home_url( GROUPS_ROOT_BLOG_ID, '/' );
}

/**
 * An address on the events site, which lists events whoever hosts them:
 * groups here, groups still on Meetup.com, and WordCamps.
 *
 * @param string $path Path on the events site, starting with a slash.
 */
function get_events_site_url( string $path = '/' ): string {
	if ( defined( 'EVENTS_ROOT_BLOG_ID' ) && get_site( EVENTS_ROOT_BLOG_ID ) ) {
		return get_home_url( EVENTS_ROOT_BLOG_ID, $path );
	}

	return 'https://events.wordpress.org' . $path;
}

/**
 * Every active group, as the list shows it, in alphabetical order.
 *
 * Archived groups are left out: their sites don't load. So is a group with no
 * name, which would be a row with nothing to click.
 *
 * @return array[] {
 *     @type int    $id       The group's site ID.
 *     @type string $name     The group's name, as plain text.
 *     @type string $url      The group's home page.
 *     @type string $location "City, Country" or "Online". Empty when the group hasn't said.
 * }
 */
function get_groups(): array {
	$groups = wp_cache_get( CACHE_KEY, CACHE_GROUP );

	if ( is_array( $groups ) ) {
		return $groups;
	}

	$groups = array();

	foreach ( get_group_sites() as $site ) {
		switch_to_blog( (int) $site->blog_id );

		// Stored escaped; kept as plain text so it's searched and sorted as
		// the visitor reads it, and escaped once on the way out.
		$name = trim( html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

		if ( '' !== $name ) {
			$groups[] = array(
				'id'       => (int) $site->blog_id,
				'name'     => $name,
				'url'      => home_url( '/' ),
				'location' => get_location_label(),
			);
		}

		restore_current_blog();
	}

	usort(
		$groups,
		static function ( array $a, array $b ): int {
			return strnatcasecmp( remove_accents( $a['name'] ), remove_accents( $b['name'] ) );
		}
	);

	wp_cache_set( CACHE_KEY, $groups, CACHE_GROUP, CACHE_TTL );

	return $groups;
}

/**
 * What the visitor searched the list for.
 */
function get_search_term(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A public, read-only search.
	$term = $_GET[ SEARCH_QUERY_VAR ] ?? '';

	if ( ! is_string( $term ) ) {
		return '';
	}

	return mb_substr( trim( sanitize_text_field( wp_unslash( $term ) ) ), 0, SEARCH_MAX_LENGTH );
}

/**
 * Keep the groups whose name or location contains every word of a search.
 *
 * Matching is by word so that "berlin germany" and "Germany, Berlin" both find
 * a group in Berlin, and ignores case and accents so that "istanbul" finds a
 * group in İstanbul.
 *
 * @param array[] $groups Groups, as `get_groups()` returns them.
 * @param string  $term   What the visitor searched for.
 *
 * @return array[] The matching groups, in the order given. All of them for an empty search.
 */
function search_groups( array $groups, string $term ): array {
	$words = preg_split( '/[\s,]+/u', normalize( $term ), -1, PREG_SPLIT_NO_EMPTY );

	if ( ! $words ) {
		return $groups;
	}

	return array_values(
		array_filter(
			$groups,
			static function ( array $group ) use ( $words ): bool {
				$text = normalize( $group['name'] . ' ' . $group['location'] );

				foreach ( $words as $word ) {
					if ( ! str_contains( $text, $word ) ) {
						return false;
					}
				}

				return true;
			}
		)
	);
}

/**
 * Reduce text to what a search compares: no accents, no capitals.
 *
 * @param string $text Text to compare.
 */
function normalize( string $text ): string {
	return mb_strtolower( remove_accents( $text ) );
}

/**
 * The list's search field.
 *
 * It is core's Search block, so it looks and behaves like the search on a
 * group's events page, with two changes. The block searches a site's posts
 * with `s`; this one searches the list with `SEARCH_QUERY_VAR`, and shows
 * what was searched for.
 *
 * @param string $term What the visitor searched for.
 *
 * @return string The form's HTML.
 */
function render_search_form( string $term ): string {
	$form = render_block(
		array(
			'blockName'    => 'core/search',
			'attrs'        => array(
				'label'          => __( 'Search groups', 'wordcamporg' ),
				'showLabel'      => false,
				// Short enough to fit the field on a 320px screen.
				'placeholder'    => __( 'Name, city, or country…', 'wordcamporg' ),
				'width'          => 100,
				'widthUnit'      => '%',
				'buttonText'     => __( 'Search', 'wordcamporg' ),
				'buttonPosition' => 'button-inside',
				'buttonUseIcon'  => true,
				'className'      => 'is-style-secondary-search-control',
			),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		)
	);

	$tags = new \WP_HTML_Tag_Processor( $form );

	while ( $tags->next_tag() ) {
		if ( 'FORM' === $tags->get_tag() ) {
			$tags->set_attribute( 'action', get_directory_url() );
		} elseif ( 'INPUT' === $tags->get_tag() && 's' === $tags->get_attribute( 'name' ) ) {
			$tags->set_attribute( 'name', SEARCH_QUERY_VAR );
			$tags->set_attribute( 'value', $term );
		}
	}

	return $tags->get_updated_html();
}
