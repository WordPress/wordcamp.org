<?php
/**
 * The vetting screen on Central: one page for companies and their sponsorships.
 *
 * Its scripts and the PHP that loads them are built from `routes/` by `npm run build`, into `build/`.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting;

defined( 'WPINC' ) || die();

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
 * Adds the screen to Central's menu, for vetters.
 */
function add_app_page(): void {
	if ( ! is_central() ) {
		return;
	}

	add_menu_page(
		__( 'Sponsor Vetting', 'wordcamporg' ),
		__( 'Sponsor Vetting', 'wordcamporg' ),
		CAPABILITY,
		APP_PAGE,
		__NAMESPACE__ . '\render_app_page',
		'dashicons-shield',
		26
	);
}
// Ahead of the import screen, which is listed under it.
add_action( 'admin_menu', __NAMESPACE__ . '\add_app_page', 9 );

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

/**
 * Sends the addresses of the screens this one replaced to it, with the same record open.
 *
 * Vetters have those bookmarked, and core would answer them with an error now.
 */
function redirect_old_screens(): void {
	global $pagenow;

	if ( ! is_central() || ! in_array( $pagenow, array( 'edit.php', 'post.php', 'post-new.php' ), true ) ) {
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Which screen was asked for.
	$post_id   = absint( $_GET['post'] ?? 0 );
	$post_type = $post_id ? (string) get_post_type( $post_id ) : sanitize_key( $_GET['post_type'] ?? '' );
	// phpcs:enable

	$records = array(
		COMPANY_POST_TYPE     => 'company',
		SPONSORSHIP_POST_TYPE => 'sponsorship',
	);

	if ( isset( $records[ $post_type ] ) ) {
		wp_safe_redirect( get_app_url( $records[ $post_type ], $post_id ) );
		exit;
	}
}
add_action( 'admin_init', __NAMESPACE__ . '\redirect_old_screens' );
