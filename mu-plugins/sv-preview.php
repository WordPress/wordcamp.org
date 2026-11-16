<?php
/**
 * Plugin Name: Sponsor Vetting preview scaffolding
 * Description: Stands in for the parts of WordCamp.org that Sponsor Vetting relies on, for a Playground preview.
 *
 * Not part of the pull request: nothing here ships. Central is the main site, emails go to an outbox screen instead
 * of FreeScout, and the camps and sponsors are made up.
 *
 * @package WordCamp\Sponsor_Vetting\Preview
 */

declare( strict_types = 1 );

defined( 'WPINC' ) || die();

require_once __DIR__ . '/sv-preview/class-freescout.php';
require_once __DIR__ . '/sv-preview/sunrise.php';

/**
 * Central is the network's main site here.
 */
const WORDCAMP_ROOT_BLOG_ID = 1;

/**
 * Gets a camp site's `wordcamp` post from Central.
 *
 * @param int $site_id The camp site.
 * @return \WP_Post|null
 */
function sv_preview_get_wordcamp_post( int $site_id ): ?\WP_Post {
	switch_to_blog( WORDCAMP_ROOT_BLOG_ID );

	$posts = get_posts(
		array(
			'post_type'   => 'wordcamp',
			'post_status' => 'any',
			'meta_key'    => '_site_id',
			'meta_value'  => $site_id,
		)
	);

	restore_current_blog();

	return $posts[0] ?? null;
}

/**
 * Gets an event's name.
 *
 * @param int $site_id The camp site.
 * @return string
 */
function get_wordcamp_name( int $site_id = 0 ): string {
	$wordcamp = sv_preview_get_wordcamp_post( $site_id );

	return $wordcamp ? $wordcamp->post_title : (string) get_blog_option( $site_id, 'blogname' );
}

/**
 * Gets an event's email address.
 *
 * @param int $site_id The camp site.
 * @return string|false
 */
function get_wordcamp_email( int $site_id = 0 ): string|false {
	$wordcamp = sv_preview_get_wordcamp_post( $site_id );

	if ( ! $wordcamp ) {
		return false;
	}

	switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
	$email = get_post_meta( $wordcamp->ID, 'E-mail Address', true );
	restore_current_blog();

	return $email ? (string) $email : false;
}

/**
 * Registers the post types Sponsor Vetting reads: Central's events and each camp's sponsors.
 */
function sv_preview_register_post_types(): void {
	register_post_type(
		'wordcamp',
		array(
			'label'  => 'WordCamps',
			'public' => false,
		)
	);

	register_post_type(
		'wcb_sponsor',
		array(
			'label'    => 'Sponsors',
			'public'   => false,
			'show_ui'  => true,
			'supports' => array( 'title', 'editor' ),
		)
	);
}
add_action( 'init', 'sv_preview_register_post_types' );

/**
 * Loads Sponsor Vetting on every site, as its network activation does on WordCamp.org.
 */
function sv_preview_load_plugin(): void {
	$bootstrap = WP_PLUGIN_DIR . '/sponsor-vetting/bootstrap.php';

	if ( file_exists( $bootstrap ) ) {
		require_once $bootstrap;
	}
}
add_action( 'muplugins_loaded', 'sv_preview_load_plugin' );

/**
 * Lists the outbox under the vetting screen.
 */
function sv_preview_add_outbox_page(): void {
	if ( WORDCAMP_ROOT_BLOG_ID !== get_current_blog_id() ) {
		return;
	}

	add_submenu_page(
		'sponsor-vetting-wp-admin',
		'Preview outbox',
		'Preview outbox',
		'wordcamp_vet_sponsors', // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Sponsor Vetting's own.
		'sv-preview-outbox',
		'sv_preview_render_outbox_page'
	);
}
add_action( 'admin_menu', 'sv_preview_add_outbox_page', 20 );

/**
 * Renders the emails that would have gone to FreeScout, newest first, or the one a conversation link asks for.
 */
function sv_preview_render_outbox_page(): void {
	$outbox = array_reverse( (array) get_site_option( WordPressdotorg\MU_Plugins\Utilities\FreeScout::OUTBOX, array() ) );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Which email to show.
	$only = absint( $_GET['email'] ?? 0 );

	echo '<div class="wrap"><h1>Preview outbox</h1>';
	echo '<p>On WordCamp.org these go out through FreeScout. In this preview nothing is sent: they’re only kept here.</p>';

	if ( $only ) {
		$outbox = array_filter( $outbox, static fn( array $email ): bool => $only === (int) $email['id'] );
		echo '<p><a href="' . esc_url( remove_query_arg( 'email' ) ) . '">← All emails</a></p>';
	}

	if ( ! $outbox ) {
		echo '<p>No emails yet.</p>';
	}

	foreach ( $outbox as $email ) {
		$to = is_array( $email['to'] ) ? trim( sprintf( '%s %s <%s>', $email['to']['first_name'] ?? '', $email['to']['last_name'] ?? '', $email['to']['email'] ?? '' ) ) : (string) $email['to'];

		echo '<div class="card" style="max-width: 800px;">';
		echo '<h2>' . esc_html( (string) $email['subject'] ) . '</h2>';
		echo '<p><strong>To:</strong> ' . esc_html( $to );

		if ( ! empty( $email['cc'] ) ) {
			echo '<br><strong>Cc:</strong> ' . esc_html( implode( ', ', (array) $email['cc'] ) );
		}

		echo '<br><strong>Sent:</strong> ' . esc_html( wp_date( 'F j, Y g:i a', (int) $email['time'] ) ) . '</p>';
		echo '<hr>' . wp_kses_post( (string) $email['body'] );
		echo '</div>';
	}

	echo '</div>';
}

/**
 * Says in the toolbar that this is a preview, so nobody takes its records for real ones.
 *
 * @param \WP_Admin_Bar $admin_bar The toolbar.
 */
function sv_preview_add_toolbar_note( \WP_Admin_Bar $admin_bar ): void {
	$admin_bar->add_node(
		array(
			'id'    => 'sv-preview',
			'title' => 'Preview of PR #2372 · made-up data',
			'href'  => 'https://github.com/WordPress/wordcamp.org/pull/2372',
		)
	);
}
add_action( 'admin_bar_menu', 'sv_preview_add_toolbar_note', 100 );
