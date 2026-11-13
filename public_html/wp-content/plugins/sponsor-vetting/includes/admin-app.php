<?php
/**
 * The vetting screen on Central: one page that lists the companies and opens one beside the list.
 *
 * Its scripts and the PHP that loads them are built from `routes/` by `npm run build`, into `build/`.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting;

defined( 'WPINC' ) || die();

/**
 * The screen's menu slug: its page in `package.json`, in the mode that keeps wp-admin's menu and toolbar.
 */
const APP_PAGE = 'sponsor-vetting-wp-admin';

/**
 * Loads the built screen, on its own page only.
 *
 * Before `init`, since the build registers its scripts as core sets up its own.
 */
function load_app(): void {
	$build = dirname( __DIR__ ) . '/build/build.php';

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Which screen this is.
	if ( is_central() && APP_PAGE === ( $_GET['page'] ?? '' ) && file_exists( $build ) ) {
		require_once $build;
	}
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\load_app' );

/**
 * Adds the screen to the Sponsor Vetting menu, beside the screens it will replace.
 */
function add_app_page(): void {
	if ( ! is_central() ) {
		return;
	}

	add_submenu_page(
		'edit.php?post_type=' . COMPANY_POST_TYPE,
		__( 'Sponsor Vetting', 'wordcamporg' ),
		__( 'New view (beta)', 'wordcamporg' ),
		CAPABILITY,
		APP_PAGE,
		__NAMESPACE__ . '\render_app_page'
	);
}
add_action( 'admin_menu', __NAMESPACE__ . '\add_app_page' );

/**
 * Renders the screen, or says what's missing when it hasn't been built.
 */
function render_app_page(): void {
	if ( function_exists( 'sponsor_vetting_sponsor_vetting_wp_admin_render_page' ) ) {
		sponsor_vetting_sponsor_vetting_wp_admin_render_page();
		return;
	}

	echo '<div class="wrap"><h1>' . esc_html__( 'Sponsor Vetting', 'wordcamporg' ) . '</h1>';
	wp_admin_notice(
		esc_html__( 'This screen hasn’t been built yet. Run “npm run build” in the plugin’s directory.', 'wordcamporg' ),
		array( 'type' => 'error' )
	);
	echo '</div>';
}
