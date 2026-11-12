<?php
/**
 * The public list of every group, at `/group/` (#2036).
 *
 * Served from the network root, a placeholder site with no theme or settings
 * of its own, so this file supplies both for that one request.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\Groups_Directory;

use function WordCamp\Groups\Archive\get_group_sites;
use function WordCamp\Groups\Frontend\Blocks\register_block;
use function WordCamp\Groups\Frontend\Group_Location\get_location_label;

defined( 'WPINC' ) || die();

const THEME        = 'groups-site';
const PARENT_THEME = 'wporg-parent-2021';

// `sunrise-groups.php` allows this query var and no other; keep the two in step.
const SEARCH_QUERY_VAR  = 'q';
const SEARCH_MAX_LENGTH = 100;

const CACHE_GROUP = 'wporg-groups-directory';
const CACHE_KEY   = 'groups';

// Building the list switches to every group's site, which is too slow for every view.
const CACHE_TTL = 15 * MINUTE_IN_SECONDS;

const ANNOUNCEMENT_URL = 'https://make.wordpress.org/project/2026/07/09/meetup-com-gatherpress/';

// Without a charset the page is read as Windows-1252; without `blog_public` it is noindexed.
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

	foreach ( array_keys( DEFAULT_OPTIONS ) as $option ) {
		add_filter( "default_option_{$option}", __NAMESPACE__ . '\filter_default_option', 10, 2 );
	}

	wp_cache_add_global_groups( array( CACHE_GROUP ) );
}

/**
 * Whether the current request is for the list, as `sunrise-groups.php` decided before WordPress loaded.
 */
function is_directory_request(): bool {
	return GROUPS_ROOT_BLOG_ID === get_current_blog_id()
		&& function_exists( '\WordCamp\Sunrise\Groups\is_directory_request' )
		&& \WordCamp\Sunrise\Groups\is_directory_request();
}

/**
 * Render the list with the group sites' theme: the root site has none of its own.
 *
 * @param mixed $stylesheet
 * @return mixed
 */
function filter_stylesheet( $stylesheet ) {
	return is_directory_request() ? THEME : $stylesheet;
}

/**
 * Load that theme's parent too.
 *
 * @param mixed $template
 * @return mixed
 */
function filter_template( $template ) {
	return is_directory_request() ? PARENT_THEME : $template;
}

/**
 * Default a setting the root site lacks. WordPress only asks when one is missing, so an existing one is kept.
 *
 * @param mixed  $default_value
 * @param string $option
 * @return mixed
 */
function filter_default_option( $default_value, $option ) {
	return is_directory_request() ? DEFAULT_OPTIONS[ $option ] : $default_value;
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
 * An address on the events site.
 */
function get_events_site_url( string $path = '/' ): string {
	if ( defined( 'EVENTS_ROOT_BLOG_ID' ) && get_site( EVENTS_ROOT_BLOG_ID ) ) {
		return get_home_url( EVENTS_ROOT_BLOG_ID, $path );
	}

	return 'https://events.wordpress.org' . $path;
}

/**
 * Every active, named group in alphabetical order, each with an `id`, `name`, `url` and `location`.
 */
function get_groups(): array {
	$groups = wp_cache_get( CACHE_KEY, CACHE_GROUP );

	if ( is_array( $groups ) ) {
		return $groups;
	}

	$groups = array();

	foreach ( get_group_sites() as $site ) {
		switch_to_blog( (int) $site->blog_id );

		// Stored escaped; decoded so it's searched and sorted as it reads.
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
 * Lowercase text and strip its accents, for comparing.
 */
function normalize( string $text ): string {
	return mb_strtolower( remove_accents( $text ) );
}

/**
 * Core's Search block, pointed at the list's query var instead of `s`.
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
