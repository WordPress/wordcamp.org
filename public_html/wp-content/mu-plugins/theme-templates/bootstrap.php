<?php
/**
 * Theme-agnostic front end HTML templates.
 *
 * Offline: Load a template to use for the "offline" PWA page.
 *
 * Block templates: Load special page templates for custom WordCamp content types.
 * The templates are defined in this folder and loaded on all block-based themes
 * (at the time of writing this, twentytwentytwo and twentytwentythree).
 */

namespace WordCamp\Theme_Templates;
use WP_Post;
use WP_Service_Worker_Scripts;
use WordCamp\Blocks\Utilities as BlockUtilities;

defined( 'WPINC' ) || die();

/**
 * Actions & filters.
 */
add_filter( 'template_include', __NAMESPACE__ . '\inject_offline_template', 20 );  // After others because being offline transcends other templates.
add_filter( 'wp_offline_error_precache_entry', __NAMESPACE__ . '\add_offline_template_cachebuster' );
add_filter( 'get_block_templates', __NAMESPACE__ . '\inject_templates', 10, 3 );
add_filter( 'get_block_template', __NAMESPACE__ . '\inject_template', 10, 3 );

/**
 * Inject the offline template when the service worker pre-caches the response to offline requests.
 *
 * This is a dynamic template like search, 404, etc, rather than a page template.
 *
 * @param string $template_path
 *
 * @return string
 */
function inject_offline_template( $template_path ) {
	if ( function_exists( 'is_offline' ) && ( \is_offline() || \is_500() ) ) {
		$template_path = __DIR__ . '/templates/offline.php';
	}

	return $template_path;
}

/**
 * Get the offline page.
 *
 * This is a page created when the WordCamp site is created, so it should exist with this post meta.
 *
 * @return WP_Post|false
 */
function get_offline_page() {
	$found_pages = get_posts( array(
		'posts_per_page' => 1,
		'post_type'      => 'page',
		'orderby'        => 'date',
		'order'          => 'asc',
		'meta_key'       => 'wc_page_offline',
		'meta_value'     => 'yes',
	) );
	if ( count( $found_pages ) ) {
		return $found_pages[0];
	}

	return false;
}

/**
 * Get the offline page content, if the page exists. Otherwise, use simple defaults.
 *
 * @return array {
 *     Content for the offline template.
 *
 *     @type string $title   Title of the page, or a default title.
 *     @type string $content Content of the page, or the PWA error callback.
 * }
 */
function get_offline_content() {
	$page = get_offline_page();
	if ( $page ) {
		require_once WP_CONTENT_DIR . '/mu-plugins/blocks/includes/content.php';

		return array(
			'title'   => apply_filters( 'the_title', $page->post_title, $page->ID ),
			'content' => BlockUtilities\get_all_the_content( $page ),
		);
	}

	ob_start();
	wp_service_worker_error_message_placeholder();
	$offline_content = ob_get_clean();

	return array(
		'title'   => esc_html__( 'Offline', 'wordcamporg' ),
		'content' => $offline_content,
	);
}

/**
 * Add the Offline page content as a revision parameter, to bust the cache when the page is changed.
 *
 * @param array|false $entry {
 *     Offline error precache entry.
 *
 *     @type string $url      URL to page that shows the offline error template.
 *     @type string $revision Revision for the template. This defaults to the template and stylesheet names, with their respective theme versions.
 * }
 *
 * @return array|false
 */
function add_offline_template_cachebuster( $entry ) {
	$page = get_offline_content();

	if ( $entry && isset( $entry['revision'] ) ) {
		$entry['revision'] .= ';' . filemtime( __DIR__ . '/templates/offline.php' ) . md5( $page['content'] );
	}

	return $entry;
}

/**
 * Inject the WC-specific templates into the available templates list.
 *
 * @param WP_Block_Template[] $query_result Array of found block templates.
 * @param array               $query Arguments to retrieve templates.
 * @param string              $template_type wp_template or wp_template_part.
 * @return WP_Block_Template[]
 */
function inject_templates( $query_result, $query, $template_type ) {
	if ( ! site_supports_block_templates() ) {
		return $query_result;
	}

	if ( 'wp_template' !== $template_type ) {
		return $query_result;
	}

	$existing_templates = wp_list_pluck( $query_result, 'slug' );

	foreach ( array( 'wcb_organizer', 'wcb_session', 'wcb_speaker', 'wcb_sponsor' ) as $type ) {
		if (
			// If there are no existing (user-created) templates.
			! in_array( 'single-' . $type, $existing_templates, true ) &&
			// and this request is either for all templates or just this specific post type.
			( empty( $query ) || is_request_for_post_type( $query, $type) )
		) {
			$template = get_wordcamp_block_template( $type );
			if ( $template ) {
				$query_result[] = $template;
			}
		}
	}

	return $query_result;
}

/**
 * Helper function to check the template query.
 *
 * @param array  $query Arguments to retrieve templates.
 * @param string $type  A WordCamp post type slug.
 * @return boolean
 */
function is_request_for_post_type( $query, $type ) {
	if ( isset( $query['slug__in'] ) && in_array( 'single-' . $type, $query['slug__in'], true ) ) {
		return true;
	}
	return isset( $query['post_type'] ) && $type === $query['post_type'];
}

/**
 * Provide a custom template if the requested template is a WordCamp post type.
 *
 * If the local site has overwritten this to create a custom template, this
 * filter never runs, so we don't need to worry about overriding a user template.
 *
 * @param WP_Block_Template|null $block_template The found block template, or null if there isn't one.
 * @param string                 $id             Template unique identifier (example: theme_slug//template_slug).
 * @param array                  $template_type  Template type: `'wp_template'` or '`wp_template_part'`.
 * @return WP_Block_Template|null
 */
function inject_template( $block_template, $id, $template_type ) {
	if ( ! site_supports_block_templates() ) {
		return $block_template;
	}

	foreach ( array( 'wcb_organizer', 'wcb_session', 'wcb_speaker', 'wcb_sponsor' ) as $type ) {
		// For example, this matches `twentytwentythree//single-wcb_organizer`.
		if ( str_ends_with( $id, 'single-' . $type ) ) {
			$template = get_wordcamp_block_template( $type );
			if ( $template ) {
				return $template;
			}
		}
	}

	return $block_template;
}

/**
 * Get a template for a given WordCamp post type.
 *
 * These templates are local PHP files, so that we can support i18n.
 *
 * @param string $post_type
 * @return WP_Block_Template|null
 */
function get_wordcamp_block_template( $post_type = '' ) {
	$labels = array(
		'wcb_organizer' => __( 'Single item: Organizer', 'wordcamporg' ),
		'wcb_session' => __( 'Single item: Session', 'wordcamporg' ),
		'wcb_speaker' => __( 'Single item: Speaker', 'wordcamporg' ),
		'wcb_sponsor' => __( 'Single item: Sponsor', 'wordcamporg' ),
	);

	$template_file = __DIR__ . "/block-templates/single-{$post_type}.php";
	if ( ! file_exists( $template_file ) ) {
		return null;
	}

	$template = _build_block_template_result_from_file(
		array(
			'slug'  => "single-{$post_type}",
			'path'  => $template_file,
			'theme' => get_stylesheet(),
			'type'  => 'wp_template',
			'title' => $labels[ $post_type ] ?? '',
		),
		'wp_template'
	);

	// By default, the template is read directly from the file, which works for HTML.
	// To allow php (i18n), we can overwrite the content property with output buffering.
	ob_start();
	include $template_file;
	$template->content = traverse_and_serialize_blocks(
		parse_blocks( ob_get_clean() ),
		'_inject_theme_attribute_in_template_part_block'
	);

	return $template;
}


/**
 * Check whether this site supports and uses the new block templates.
 *
 * @return boolean
 */
function site_supports_block_templates() {
	return wp_is_block_theme() && ! wcorg_skip_feature( 'wcpt_block_templates' );
}
