<?php
/**
 * Theme-agnostic front end HTML templates.
 */

namespace WordCamp\Theme_Templates;
use WP_Post;
use WP_Service_Worker_Scripts;

defined( 'WPINC' ) || die();

add_filter( 'theme_page_templates',            __NAMESPACE__ . '\register_page_templates' );
add_filter( 'template_include',                __NAMESPACE__ . '\set_page_template_locations' );
add_filter( 'template_include',                __NAMESPACE__ . '\inject_offline_template', 20 );  // After others because being offline transcends other templates.
add_action( 'wp_enqueue_scripts',              __NAMESPACe__ . '\enqueue_template_assets' );
add_filter( 'wp_offline_error_precache_entry', __NAMESPACE__ . '\add_offline_template_cachebuster' );

/**
 * Add new templates to the Page Template dropdown in the editor.
 *
 * @param array $templates
 *
 * @return array
 */
function register_page_templates( $templates ) {
	/*
	 * Remove CampSite 2017's Day of template, since it's redundant, and having multiple templates in the menu
	 * would be confusing.
	 */
	if ( isset( $templates['templates/page-day-of.php'] ) ) {
		unset( $templates['templates/page-day-of.php'] );
	}

	$templates['day-of-event'] = __( 'Day of Event', 'wordcamporg' );

	natsort( $templates );
	return $templates;
}

/**
 * Tell WP where to load the templates from.
 *
 * @param string $template_path
 *
 * @return string
 */
function set_page_template_locations( $template_path ) {
	global $post;

	if ( ! $post instanceof WP_Post ) {
		return $template_path;
	}

	switch ( $post->_wp_page_template ) {
		case 'day-of-event':
			$template_path = __DIR__ . '/templates/day-of-event/day-of-event.php';
			break;
	}

	return $template_path;
}

/**
 * Enqueues JavaScript and CSS files for the active page template.
 */
function enqueue_template_assets() {
	global $post;

	if ( ! $post instanceof WP_Post ) {
		return;
	}

	switch ( $post->_wp_page_template ) {
		case 'day-of-event':
			// todo this has poor readability, we wouldn't really want to do all this in a `switch` for multiple entries
			// maybe move to separate function, but then it's kind of awkward to have this switch setup just for 1 template
			// maybe remove b/c YAGNI, but other places use `switch` too and it feels cleaner/better there.

			wp_enqueue_script(
				'day-of-event-template',
				plugins_url( '/templates/day-of-event/build/index.js', __FILE__ ),
				// phpcs:disable WordPress.WP.AlternativeFunctions -- Allow file_get_contents
				json_decode( file_get_contents( __DIR__ . '/templates/day-of-event/build/index.deps.json' ) ),
				// phpcs:enable
				filemtime( __DIR__ . '/templates/day-of-event/build/index.js' ),
				true
			);

			$config = array(
				'postsArchiveUrl' => esc_url( get_post_type_archive_link( 'post' ) ),
				'scheduleUrl'     => esc_url( site_url( __( 'schedule', 'wordcamporg' ) ) ),
			);

			$config_script = sprintf(
				'var dayOfEventConfig = JSON.parse( decodeURIComponent( \'%s\' ) );',
				rawurlencode( wp_json_encode( $config ) )
			);

			wp_add_inline_script( 'day-of-event-template', $config_script, 'before' );

			/*
			 * This depends on 'wp-components', but that is intentionally left out because the only part we need
			 * is `.components-spinner`, and it wouldn't be performant to bundle the entire file just for that. So
			 * instead that class is duplicated in `day-of-event.css`.
			 */
			wp_enqueue_style(
				'day-of-event-template',
				plugins_url( '/templates/day-of-event/day-of-event.css', __FILE__ ),
				array(),
				filemtime( __DIR__ . '/templates/day-of-event/day-of-event.css' )
			);

			break;
	}
}

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
	if ( is_offline() || is_500() ) {
		$template_path = __DIR__ . '/templates/offline.php';
	}

	return $template_path;
}

/**
 * Add a cache-buster to the offline template's pre-cache entry.
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
	if ( $entry && isset( $entry['revision'] ) ) {
		$entry['revision'] .= ';' . filemtime( __DIR__ . '/templates/offline.php' );
	}

	return $entry;
}
