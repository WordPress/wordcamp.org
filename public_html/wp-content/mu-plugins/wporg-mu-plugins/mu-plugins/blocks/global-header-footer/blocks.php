<?php

namespace WordPressdotorg\MU_Plugins\Global_Header_Footer;

use Rosetta_Sites, WP_Post, WP_REST_Server, WP_Theme_JSON_Resolver;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/admin-bar.php';

add_action( 'init', __NAMESPACE__ . '\register_block_types' );
add_action( 'admin_bar_init', __NAMESPACE__ . '\remove_admin_bar_callback', 15 );
add_action( 'rest_api_init', __NAMESPACE__ . '\register_routes' );
add_filter( 'wp_enqueue_scripts', __NAMESPACE__ . '\register_block_assets', 200 ); // Always last.
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_compat_wp4_styles', 5 ); // Before any theme CSS.
add_action( 'wp_head', __NAMESPACE__ . '\preload_google_fonts' );
add_filter( 'style_loader_src', __NAMESPACE__ . '\update_google_fonts_url', 10, 2 );
add_filter( 'render_block_core/navigation-link', __NAMESPACE__ . '\swap_submenu_arrow_svg' );
add_filter( 'render_block_core/search', __NAMESPACE__ . '\swap_header_search_action', 10, 2 );
add_filter( 'render_block_data', __NAMESPACE__ . '\update_block_style_colors' );

/**
 * Register block types
 *
 * These are intentionally missing arguments like `title`, `category`, `icon`, etc, because we don't want them
 * showing up in the Block Inserter, regardless of which theme is running.
 */
function register_block_types() {
	register_block_type(
		__DIR__ . '/build/header/block.json',
		array(
			'render_callback' => __NAMESPACE__ . '\render_global_header',
		)
	);

	register_block_type(
		__DIR__ . '/build/footer/block.json',
		array(
			'render_callback' => __NAMESPACE__ . '\render_global_footer',
		)
	);
}

/**
 * Register the script & stylesheet for use in the blocks.
 */
function register_block_assets() {
	// Our custom login screen is technically a front-end page, so the script/style are enqueued by default.
	// That's unnecessary because the header/footer isn't rendered in there.
	if ( 'login.wordpress.org' === $_SERVER['SERVER_NAME'] ) {
		return;
	}

	$suffix = is_rtl() ? '-rtl' : '';

	// Load `block-library` styles first, so that our styles override them.
	$style_dependencies = array( 'wp-block-library' );
	if ( wp_style_is( 'wporg-global-fonts', 'registered' ) ) {
		$style_dependencies[] = 'wporg-global-fonts';
	}
	wp_register_style(
		'wporg-global-header-footer',
		plugins_url( "/build/style$suffix.css", __FILE__ ),
		$style_dependencies,
		filemtime( __DIR__ . "/build/style$suffix.css" )
	);

	wp_register_script(
		'wporg-global-header-script',
		plugins_url( '/js/view.js', __FILE__ ),
		array(),
		filemtime( __DIR__ . '/js/view.js' ),
		true
	);

	wp_localize_script(
		'wporg-global-header-script',
		'wporgGlobalHeaderI18n',
		array(
			'openSearchLabel' => __( 'Open Search', 'wporg' ),
			'closeSearchLabel' => __( 'Close Search', 'wporg' ),
			'overflowMenuLabel' => __( 'More menu', 'wporg' ),
		)
	);
}

/**
 * Remove the default margin-top added when the admin bar is used.
 *
 * The core handling uses `!important`, which overrides the sticky header offset in `common.pcss`.
 */
function remove_admin_bar_callback() {
	remove_action( 'gp_head', '_admin_bar_bump_cb' );
	remove_action( 'wp_head', '_admin_bar_bump_cb' );
}

/**
 * Register REST API routes, so non-WP applications can integrate it.
 */
function register_routes() {
	register_rest_route(
		'global-header-footer/v1',
		'header',
		array(
			array(
				'methods'  => WP_REST_Server::READABLE,
				'callback' => __NAMESPACE__ . '\rest_render_global_header',
				'permission_callback' => '__return_true',
			),
		)
	);

	register_rest_route(
		'global-header-footer/v1',
		'header/codex',
		array(
			array(
				'methods'  => WP_REST_Server::READABLE,
				'callback' => __NAMESPACE__ . '\rest_render_codex_global_header',
				'permission_callback' => '__return_true',
			),
		)
	);

	register_rest_route(
		'global-header-footer/v1',
		'header/planet',
		array(
			array(
				'methods'  => WP_REST_Server::READABLE,
				'callback' => __NAMESPACE__ . '\rest_render_planet_global_header',
				'permission_callback' => '__return_true',
			),
		)
	);

	register_rest_route(
		'global-header-footer/v1',
		'footer',
		array(
			array(
				'methods'  => WP_REST_Server::READABLE,
				'callback' => __NAMESPACE__ . '\rest_render_global_footer',
				'permission_callback' => '__return_true',
			),
		)
	);
}

/**
 * Filter the google fonts URL to use the "CSS2" version of the API.
 *
 * @param string $src    The source URL of the enqueued style.
 * @param string $handle The style's registered handle.
 * @return string Updated URL for `open-sans`.
 */
function update_google_fonts_url( $src, $handle ) {
	if ( 'open-sans' === $handle ) {
		return 'https://fonts.googleapis.com/css2?family=Open+Sans:ital,wght@0,300;0,400;0,600;1,300;1,400;1,600&display=swap';
	}
	return $src;
}

/**
 * Add preconnect resource hints for the Google Fonts API.
 */
function preload_google_fonts() {
	echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
	echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin> ';
}

/**
 * Output styles for themes that don't use `wp4-styles`. This provides compat with the classic header.php.
 */
function enqueue_compat_wp4_styles() {
	// See https://wordpress.slack.com/archives/C02QB8GMM/p1642056619063500
	if (
		( defined( 'FEATURE_2021_GLOBAL_HEADER_FOOTER' ) && ! FEATURE_2021_GLOBAL_HEADER_FOOTER ) &&
		( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST )
	) {
		return;
	}

	if ( defined( 'IS_WORDCAMP_NETWORK' ) ) {
		return;
	}

	if (
		( ! wp_is_block_theme() && ! current_theme_supports( 'wp4-styles' ) ) ||
		( defined( 'REST_REQUEST' ) && REST_REQUEST )
	) {
		$suffix = is_rtl() ? '-rtl' : '';

		wp_register_style(
			'wp4-styles',
			'https://s.w.org/style/wp4' . $suffix . '.css',
			array( 'open-sans' ),
			defined( 'WPORGPATH' ) ? filemtime( WPORGPATH . '/style/wp4' . $suffix . '.css' ) : gmdate( 'Y-m-d' )
		);

		wp_enqueue_style( 'wp4-styles' );
	}
}

/**
 * Remove the wrapping element to preserve markup.
 *
 * Core and Gutenberg add a wrapper `div` for backwards-compatibility, but that is unnecessary here, and breaks
 * CSS selectors.
 *
 * @see restore_inner_group_container()
 */
function remove_inner_group_container() {
	if ( wp_is_block_theme() ) {
		return;
	}

	remove_filter( 'render_block_core/group', 'wp_restore_group_inner_container' );
	remove_filter( 'render_block_core/group', 'gutenberg_restore_group_inner_container' );
}

/**
 * Restore the wrapping element to prevent side-effects on the content area.
 *
 * @see remove_inner_group_container()
 */
function restore_inner_group_container() {
	if ( wp_is_block_theme() ) {
		return;
	}

	if ( function_exists( 'gutenberg_restore_group_inner_container' ) ) {
		add_filter( 'render_block_core/group', 'gutenberg_restore_group_inner_container', 10, 2 );
	} else {
		add_filter( 'render_block_core/group', 'wp_restore_group_inner_container', 10, 2 );
	}
}

/**
 * Render the global header via a REST request.
 *
 * @return string
 */
function rest_render_global_header( $request ) {

	// Remove the theme stylesheet from rest requests.
	add_filter( 'wp_enqueue_scripts', function() {
		remove_theme_support( 'wp4-styles' );

		wp_dequeue_style( 'wporg-style' );
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'open-sans' );
	}, 20 );

	// Serve the request as HTML.
	add_filter( 'rest_pre_serve_request', function( $served, $result ) {
		header( 'Content-Type: text/html' );
		header( 'X-Robots-Tag: noindex, follow' );

		echo $result->get_data();

		return true;
	}, 10, 2 );

	return do_blocks( '<!-- wp:wporg/global-header /-->' );
}

/**
 * Render the global header via a REST request for the Codex with appropriate tags.
 *
 * @return string
 */
function rest_render_codex_global_header( $request ) {
	add_action( 'wp_head', function() {
		echo '<!-- [codex head meta] -->', "\n";
	}, 1 );

	add_action( 'wp_head', function() {
		echo '<!-- [codex head scripts] -->', "\n";
	}, 100 );

	add_filter( 'body_class', function( $class ) {
		return [
			'wporg-responsive',
			'wporg-codex'
		];
	} );

	wp_enqueue_style( 'codex-wp4', 'https://s.w.org/style/codex-wp4.css', array( 'wp4-styles' ), 4 );

	// Remove <title> tags.
	remove_theme_support( 'title-tag' );

	$markup = rest_render_global_header( $request );
	$markup = preg_replace( '!<html[^>]+>!i', '<!-- [codex head html] -->', $markup );

	return $markup;
}

/**
 * Render the global header via a REST request for use with Planet.
 *
 * @return string
 */
function rest_render_planet_global_header( $request ) {
	add_filter( 'pre_get_document_title', function() {
		return 'Planet &mdash; WordPress.org';
	} );

	add_filter( 'wporg_canonical_url', function() {
		return 'https://planet.wordpress.org/';
	} );

	add_filter( 'body_class', function( $class ) {
		return [
			'wporg-responsive',
			'wporg-planet'
		];
	} );

	return rest_render_global_header( $request );
}

/**
 * Render the global header in a block context.
 *
 * @param array $attributes The block attributes.
 *
 * @return string Returns the block content.
 */
function render_global_header( $attributes = array() ) {
	remove_inner_group_container();

	if ( is_rosetta_site() ) {
		$menu_items   = get_rosetta_menu_items();
		$locale_title = get_rosetta_name();
		$show_search  = false;
	} else {
		$menu_items   = get_global_menu_items();
		$locale_title = '';
		$show_search  = true;
	}

	// Preload the menu font.
	if ( is_callable( 'global_fonts_preload' ) ) {
		/* translators: Subsets can be any of cyrillic, cyrillic-ext, greek, greek-ext, vietnamese, latin, latin-ext.  */
		$subsets = _x( 'latin', 'Global menu font subsets, comma separated', 'wporg' );
		global_fonts_preload( 'Inter', $subsets );
	}

	// The mobile Get WordPress button needs to be in both menus.
	$menu_items[] = array(
		'title'   => esc_html_x( 'Get WordPress', 'Menu item title', 'wporg' ),
		'url'     => get_download_url(),
		'type'    => 'custom',
		'classes' => 'global-header__mobile-get-wordpress global-header__get-wordpress',
	);

	$menu_items = set_current_item_class( $menu_items );

	/*
	 * Render the block mockup first, in case anything in that process adds hooks to `wp_head`.
	 * Allow multiple includes to allow for the double `site-header-offset` workaround.
	 */
	ob_start();
	require_once __DIR__ . '/header.php';
	$markup = do_blocks( ob_get_clean() );

	restore_inner_group_container();

	$is_rest_request = defined( 'REST_REQUEST' ) && REST_REQUEST;

	/*
	 * Render the classic markup second, so the `wp_head()` call will execute callbacks that blocks added above.
	 *
	 * API requests also need `<head>` etc so they can get the styles.
	 */
	$head_markup = '';
	if ( ! wp_is_block_theme() || $is_rest_request ) {
		ob_start();
		require_once __DIR__ . '/classic-header.php';
		$head_markup = ob_get_clean();
	}

	$wrapper_attributes = get_block_wrapper_attributes(
		array( 'class' => 'global-header wp-block-group' )
	);
	return sprintf(
		'%1$s<header %2$s>%3$s</header>%4$s',
		$head_markup,
		$wrapper_attributes,
		$markup,
		wp_kses_post( render_header_alert_banner() )
	);
}

/**
 * Determine if the current site is a Rosetta site (e.g., `es-mx.wordpress.org`).
 *
 * This returns `false` for `translate.wordpress.org`; it's part of the Rosetta network, but isn't a Rosetta site.
 *
 * @return bool
 */
function is_rosetta_site() {
	global $rosetta;

	return $rosetta instanceof Rosetta_Sites;
}

/**
 * Get the standard items for the global header menu.
 *
 * These are used on all sites, except Rosetta.
 *
 * @return array[]
 */
function get_global_menu_items() {
	$global_items = array(
		array(
			'title'   => esc_html_x( 'News', 'Menu item title', 'wporg' ),
			'url'     => 'https://wordpress.org/news/',
			'type'    => 'custom',
		),
		array(
			'title'   => esc_html_x( 'Download & Extend', 'Menu item title', 'wporg' ),
			'url'     => 'https://wordpress.org/download/',
			'type'    => 'custom',
			'submenu' => array(
				array(
					'title' => esc_html_x( 'Get WordPress', 'Menu item title', 'wporg' ),
					'url'   => 'https://wordpress.org/download/',
					'type'  => 'custom',
				),
				array(
					'title' => esc_html_x( 'Themes', 'Menu item title', 'wporg' ),
					'url'   => 'https://wordpress.org/themes/',
					'type'  => 'custom',
				),
				array(
					'title' => esc_html_x( 'Patterns', 'Menu item title', 'wporg' ),
					'url'   => 'https://wordpress.org/patterns/',
					'type'  => 'custom',
				),
				array(
					'title' => esc_html_x( 'Plugins', 'Menu item title', 'wporg' ),
					'url'   => 'https://wordpress.org/plugins/',
					'type'  => 'custom',
				),
				array(
					'title' => esc_html_x( 'Mobile', 'Menu item title', 'wporg' ),
					'url'   => 'https://wordpress.org/mobile/',
					'type'  => 'custom',
				),
				array(
					'title' => esc_html_x( 'Hosting', 'Menu item title', 'wporg' ),
					'url'   => 'https://wordpress.org/hosting/',
					'type'  => 'custom',
				),
				array(
					'title' => esc_html_x( 'Openverse ↗︎', 'Menu item title', 'wporg' ),
					'url'   => 'https://openverse.org/',
					'type'  => 'custom',
				),
			),
		),
		array(
			'title'   => esc_html_x( 'Learn', 'Menu item title', 'wporg' ),
			'url'     => 'https://learn.wordpress.org/',
			'type'    => 'custom',
			'submenu' => array(
				array(
					'title' => esc_html_x( 'Learn WordPress', 'Menu item title', 'wporg' ),
					'url'   => 'https://learn.wordpress.org/',
					'type'  => 'custom',
				),
				array(
					'title' => esc_html_x( 'Documentation', 'Menu item title', 'wporg' ),
					'url'   => 'https://wordpress.org/documentation/',
					'type'  => 'custom',
				),
				array(
					'title' => esc_html_x( 'Forums', 'Menu item title', 'wporg' ),
					'url'   => 'https://wordpress.org/support/forums/',
					'type'  => 'custom',
				),
				array(
					'title' => esc_html_x( 'Developers', 'Menu item title', 'wporg' ),
					'url'   => 'https://developer.wordpress.org/',
					'type'  => 'custom',
				),
				array(
					'title' => esc_html_x( 'WordPress.tv ↗︎', 'Menu item title', 'wporg' ),
					'url'   => 'https://wordpress.tv/',
					'type'  => 'custom',
				),
			),
		),
		array(
			'title'   => esc_html_x( 'Community', 'Menu item title', 'wporg' ),
			'url'     => 'https://make.wordpress.org/',
			'type'    => 'custom',
			'submenu' => array(
				array(
					'title' => esc_html_x( 'Make WordPress', 'Menu item title', 'wporg' ),
					'url'   => 'https://make.wordpress.org/',
					'type'  => 'custom',
				),
				array(
					'title' => esc_html_x( 'Photo Directory', 'Menu item title', 'wporg' ),
					'url'   => 'https://wordpress.org/photos/',
					'type'  => 'custom',
				),
				array(
					'title' => esc_html_x( 'Five for the Future', 'Menu item title', 'wporg' ),
					'url'   => 'https://wordpress.org/five-for-the-future/',
					'type'  => 'custom',
				),
				array(
					'title' => esc_html_x( 'WordCamp ↗︎', 'Menu item title', 'wporg' ),
					'url'   => 'https://central.wordcamp.org/',
					'type'  => 'custom',
				),
				array(
					'title' => esc_html_x( 'Meetups ↗︎', 'Menu item title', 'wporg' ),
					'url'   => 'https://www.meetup.com/pro/wordpress/',
					'type'  => 'custom',
				),
				array(
					'title' => esc_html_x( 'Job Board ↗︎', 'Menu item title', 'wporg' ),
					'url'   => 'https://jobs.wordpress.net/',
					'type'  => 'custom',
				),
			),
		),
		array(
			'title'   => esc_html_x( 'About', 'Menu item title', 'wporg' ),
			'url'     => 'https://wordpress.org/about/',
			'type'    => 'custom',
			'submenu' => array(
				array(
					'title' => esc_html_x( 'About WordPress', 'Menu item title', 'wporg' ),
					'url'   => 'https://wordpress.org/about/',
					'type'  => 'custom',
				),
				array(
					'title' => esc_html_x( 'Showcase', 'Menu item title', 'wporg' ),
					'url'   => 'https://wordpress.org/showcase/',
					'type'  => 'custom',
				),
				array(
					'title' => esc_html_x( 'Enterprise', 'Menu item title', 'wporg' ),
					'url'   => 'https://wordpress.org/enterprise/',
					'type'  => 'custom',
				),
				array(
					'title' => esc_html_x( 'Gutenberg ↗︎', 'Menu item title', 'wporg' ),
					'url'   => 'https://wordpress.org/gutenberg/',
					'type'  => 'custom',
				),
				array(
					'title' => esc_html_x( 'WordPress Swag Store ↗︎', 'Menu item title', 'wporg' ),
					'url'   => 'https://mercantile.wordpress.org/',
					'type'  => 'custom',
				),
			),
		),
	);

	return $global_items;
}

/**
 * Rosetta sites each have their own menus, rather than using the global menu items.
 *
 * It's a combination of the items that site admins add to the "Rosetta" menu, and some items that are added
 * programmatically to all sites.
 *
 * @return array[]
 */
function get_rosetta_menu_items() : array {
	/** @var Rosetta_Sites $rosetta */
	global $rosetta;

	switch_to_blog( $rosetta->get_root_site_id() );

	// `Rosetta_Sites::wp_nav_menu_objects` sometimes removes redundant items, but sometimes returns early.
	$database_items = wp_get_nav_menu_items( get_nav_menu_locations()['rosetta_main'] );
	$database_items = array_filter( $database_items, __NAMESPACE__ . '\is_valid_rosetta_menu_item' );

	$mock_args                    = (object) array( 'theme_location' => 'rosetta_main' );
	$database_and_hardcoded_items = $rosetta->wp_nav_menu_objects( $database_items, $mock_args );
	$normalized_items             = normalize_rosetta_items( $database_and_hardcoded_items );

	restore_current_blog();

	return $normalized_items;
}

/**
 * Fetch the Rosetta site name.
 *
 * @return string
 */
function get_rosetta_name() : string {
	/** @var Rosetta_Sites $rosetta */
	global $rosetta;

	return get_blog_option( $rosetta->get_root_site_id(), 'blogname' );
}

/**
 * Determines if a Rosetta menu item is valid.
 *
 * Some items saved in Rosetta nav menus are redundant, because the global header already includes Download and
 * Home links (via the logo).
 *
 * @param WP_Post $menu_item
 *
 * @return bool
 */
function is_valid_rosetta_menu_item( $item ) {
	/*
	 * Cover full URLs like `https://ar.wordpress.org/` and `https://ar.wordpress.org/download/`; and relative
	 * ones like  `/` and `/download/`.
	 */
	$redundant_slugs = array( '/download/', '/txt-download/', '/', "/{$_SERVER['HTTP_HOST']}/" );

	// Not using `basename()` because that would match `/foo/download`
	$irrelevant_url_parts = array( 'http://', 'https://', $_SERVER['HTTP_HOST'] );

	$item_slug = str_replace( $irrelevant_url_parts, '', $item->url );
	$item_slug = trailingslashit( $item_slug );

	return ! in_array( $item_slug, $redundant_slugs, true );
}

/**
 * Normalize the data to be consistent with the format of `get_global_menu_items()`.
 *
 * @param object[] $rosetta_items Some are `WP_Post`, and some are `stdClass` that are mocking a `WP_Post`.
 *
 * @return array
 */
function normalize_rosetta_items( $rosetta_items ) {
	$normalized_items = array();
	$parent_indices   = array();

	// Standardise the menu classes.
	foreach ( $rosetta_items as $index => $item ) {
		$rosetta_items[ $index ]->classes  = implode( ' ', (array) $item->classes );
	}

	// Assign the top-level menu items.
	foreach ( $rosetta_items as $index => $item ) {
		$top_level_item = empty( $item->menu_item_parent );

		if ( ! $top_level_item ) {
			continue;
		}

		// Track the indexes of parent items, so the submenu can be built later on.
		$parent_indices[ $item->ID ] = $index;
		$normalized_items[ $index ]  = (array) $item;
	}

	// Add all submenu items.
	foreach ( $rosetta_items as $index => $item ) {
		$top_level_item = empty( $item->menu_item_parent );

		if ( $top_level_item ) {
			continue;
		}

		// Page has a parent that is not in the menu?
		if ( ! isset( $parent_indices[ $item->menu_item_parent ] ) ) {
			continue;
		}

		$parent_index = $parent_indices[ $item->menu_item_parent ];

		$normalized_items[ $parent_index ]['submenu'][] = array(
			'title' => $item->title,
			'url'   => $item->url,
			'type'  => $item->type,
		);
	}

	return $normalized_items;
}

/**
 * Retrieve the URL of the home page.
 *
 * Most of the time it will just be `w.org/`, but Rosetta sites use the URL of the "root site" homepage.
 */
function get_home_url() {
	/** @var Rosetta_Sites $rosetta */
	global $rosetta;

	$url = false;

	if ( is_rosetta_site() ) {
		$root_site = $rosetta->get_root_site_id();
		$url       = \get_home_url( $root_site, '/' );
	}

	if ( ! $url ) {
		$url = 'https://wordpress.org/';
	}

	return $url;
}

/**
 * Retrieve the URL to download WordPress.
 *
 * Rosetta sites sometimes have a localized page, rather than the main English one.
 *
 * @todo Make DRY with `Rosetta_Sites::wp_nav_menu_objects()` and `WordPressdotorg\MainTheme\get_downloads_url()`.
 * There are some differences between these three that need to be reconciled, though.
 */
function get_download_url() {
	/** @var Rosetta_Sites $rosetta */
	global $rosetta;

	$url = false;

	if ( is_rosetta_site() ) {
		$root_site = $rosetta->get_root_site_id();

		switch_to_blog( $root_site );

		$download = get_page_by_path( 'download' );

		if ( ! $download ) {
			$download = get_page_by_path( 'txt-download' );
		}
		if ( ! $download ) {
			$download = get_page_by_path( 'releases' );
		}

		if ( $download ) {
			$url = get_permalink( $download );
		}

		restore_current_blog();
	}

	if ( ! $url ) {
		$url = 'https://wordpress.org/download/';
	}

	return $url;
}

/**
 * Render a banner that other plugins can add alerts to.
 */
function render_header_alert_banner() {
	$markup = '';
	$alerts = apply_filters( 'wporg_global_header_alert_markup', '' );

	if ( $alerts ) {
		$markup = sprintf(
			'<div class="global-header__alert-banner">%s</div>',
			$alerts
		);
	}

	return $markup;
}

/**
 * Render the global footer via a REST request.
 *
 * @return string
 */
function rest_render_global_footer( $request ) {

	/*
	 * Render the header but discard the markup, so that any header styles/scripts
	 * required are then available for output in the footer.
	 */
	do_blocks( '<!-- wp:wporg/global-header /-->' );

	// Serve the request as HTML
	add_filter( 'rest_pre_serve_request', function( $served, $result ) {
		header( 'Content-Type: text/html' );
		header( 'X-Robots-Tag: noindex, follow' );

		echo $result->get_data();

		return true;
	}, 10, 2 );

	return do_blocks( '<!-- wp:wporg/global-footer /-->' );
}

/**
 * Render the global footer in a block context.
 *
 * @param array    $attributes Block attributes.
 * @param string   $content    Block default content.
 * @param WP_Block $block      Block instance.
 *
 * @return string Returns the block markup.
 */
function render_global_footer( $attributes, $content, $block ) {
	remove_inner_group_container();

	if ( is_rosetta_site() ) {
		$locale_title = get_rosetta_name();
		add_filter( 'render_block_data', __NAMESPACE__ . '\localize_nav_links' );
	} else {
		$locale_title = '';
	}

	// Render the block mockup first, because `wp_render_layout_support_flag()` adds callbacks to `wp_footer`.
	ob_start();
	require_once __DIR__ . '/footer.php';
	$markup = do_blocks( ob_get_clean() );

	restore_inner_group_container();

	$is_rest_request = defined( 'REST_REQUEST' ) && REST_REQUEST;

	// Render the classic markup second, so the `wp_footer()` call will execute callbacks that blocks added.
	$footer_markup = '';
	if ( ! wp_is_block_theme() || $is_rest_request ) {
		ob_start();
		require_once __DIR__ . '/classic-footer.php';
		$footer_markup = ob_get_clean();
	}

	remove_filter( 'render_block_data', __NAMESPACE__ . '\localize_nav_links' );

	$wrapper_attributes = get_block_wrapper_attributes(
		array( 'class' => 'global-footer wp-block-group' )
	);
	return sprintf(
		'<footer %1$s>%2$s</footer>%3$s',
		$wrapper_attributes,
		$markup,
		$footer_markup,
	);
}

/**
 * Convert the `style` attribute on the footer block to use color settings.
 *
 * @param array $block The parsed block data.
 *
 * @return array
 */
function update_block_style_colors( $block ) {
	if (
		! empty( $block['blockName'] ) &&
		in_array( $block['blockName'], [ 'wporg/global-footer', 'wporg/global-header' ], true ) &&
		! empty( $block['attrs']['style'] )
	) {
		if ( 'black-on-white' === $block['attrs']['style'] ) {
			$block['attrs']['textColor']       = 'charcoal-2';
			$block['attrs']['backgroundColor'] = 'white';
		} elseif ( 'white-on-blue' === $block['attrs']['style'] ) {
			$block['attrs']['textColor']       = 'white';
			$block['attrs']['backgroundColor'] = 'blueberry-1';
		}
	}

	return $block;
}


/**
 * Localise a `core/navigation-link` block link to point to the Rosetta site resource.
 *
 * Unfortunately WordPress doesn't have a block-specific pre- filter, only a block-specific post-filter.
 * That's why we specifically check for the blockName here.
 *
 * @param array $block The parsed block data.
 *
 * @return array
 */
function localize_nav_links( $block ) {
	if (
		! empty( $block['blockName'] ) &&
		'core/navigation-link' === $block['blockName'] &&
		! empty( $block['attrs']['url'] )
	) {
		$block['attrs']['url'] = get_localized_footer_link( $block['attrs']['url'] );
	}

	return $block;
}

/**
 * Get a localized variant of a link included in the global footer.
 *
 * @param string $url The URL as it is in the menu.
 *
 * @return string Replacement URL, which may be localised.
 */
function get_localized_footer_link( $url ) {
	global $rosetta;
	if ( empty( $rosetta->current_site_domain ) ) {
		return $url;
	}

	switch ( $url ) {
		case 'https://wordpress.org/showcase/':
		case 'https://wordpress.org/hosting/':
			return $url;

		case 'https://wordpress.org/support/':
			// Check if support forum exists.
			if ( ! $rosetta->has_support_forum() ) {
				return $url;
			}
			break;

		case 'https://learn.wordpress.org/':
			return add_query_arg( 'locale', get_locale(), $url );
	}

	return str_replace( 'https://wordpress.org/', 'https://' . $rosetta->current_site_domain . '/', $url );
}

/**
 * Check if the current site is part of the w.org network.
 *
 * These blocks are used on some sites (like profiles.w.org) that are running WP, but in a different network.
 * In those sites, some things need to behave differently (e.g., because `switch_to_blog()` wouldn't work).
 */
function is_wporg_network() {
	if ( 'local' === wp_get_environment_type() ) {
		return false;
	}

	return defined( 'WPORGPATH' ) && 0 === strpos( $_SERVER['SCRIPT_FILENAME'], WPORGPATH );
}

/**
 * Set the menu active state for the currently selected menu item.
 *
 * @param array $menu_items The menu menu items.
 *
 * @return array The altered menu items.
 */
function set_current_item_class( $menu_items ) {
	$host        = strtolower( $_SERVER['HTTP_HOST'] ); // phpcs:ignore
	$uri         = strtolower( $_SERVER['REQUEST_URI'] ); // phpcs:ignore
	$current_url = "https://{$host}{$uri}";

	foreach ( $menu_items as & $item ) {
		if ( ! empty( $item['submenu'] ) ) {
			foreach ( $item['submenu'] as & $subitem ) {
				if ( $current_url === $subitem['url'] ) {
					$subitem['classes'] = trim( ( $subitem['classes'] ?? '' ) . ' current-menu-item' );
					break;
				}
			}
		}

		if ( $current_url === $item['url'] ) {
			$item['classes'] = trim( ( $item['classes'] ?? '' ) . ' current-menu-item' );
		}
	}

	return $menu_items;
}

/**
 * Replace the current submenu down-arrow with a custom icon.
 *
 * @param string $block_content The block content about to be appended.
 * @return string The filtered block content.
 */
function swap_submenu_arrow_svg( $block_content ) {
	return str_replace( block_core_navigation_link_render_submenu_icon(), "<svg width='10' height='7' viewBox='0 0 10 7' stroke-width='1.2' xmlns='http://www.w3.org/2000/svg'><path d='M0.416667 1.33325L5 5.49992L9.58331 1.33325'></path></svg>", $block_content );
}

/**
 * Replace the search action url with the custom attribute.
 *
 * @param string $block_content The block content about to be appended.
 * @param array  $block         The block details.
 * @return string The filtered block content.
 */
function swap_header_search_action( $block_content, $block ) {
	if ( ! empty( $block['attrs']['formAction'] ) ) {
		$block_content = str_replace(
			'action="' . esc_url( home_url( '/' ) ) . '"',
			'action="' . esc_url( $block['attrs']['formAction'] ) . '"',
			$block_content
		);
	}

	return $block_content;
}

/**
 * Translate the tagline with the necessary text domain.
 */
function get_cip_text() {
	$english    = 'Code is Poetry.';
	$translated = __( 'Code is Poetry.', 'wporg' );

	if ( $translated === $english && is_rosetta_site() ) {
		$translated = __( 'Code is Poetry.', 'rosetta' );
	}

	return $translated;
}
