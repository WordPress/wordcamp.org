<?php
/**
 * Network-admin tool for provisioning new Group sites.
 *
 * Deputies/Program Managers can create a fully configured Group site
 * (`events.wordpress.org/group/{slug}/`) from Network Admin > Groups, instead
 * of hand-running `wp_insert_site()`/`wp site create` on the sandbox.
 *
 * Only loaded on the Groups network, via
 * `load-other-mu-plugins.php::wcorg_include_network_only_plugins()`.
 *
 * @package WordCamp\Groups
 */

namespace WordCamp\Groups\Site_Provisioning;

use WordCamp\Logger;
use WP_Error;

defined( 'WPINC' ) || die();

const PAGE_SLUG    = 'add-group-site';
const NONCE_ACTION = 'wcorg_create_group_site';
const NONCE_NAME   = 'wcorg_create_group_site_nonce';

add_action( 'network_admin_menu', __NAMESPACE__ . '\register_admin_page' );
add_action( 'admin_init', __NAMESPACE__ . '\handle_form_submission' );

/**
 * Add the "Add Group Site" screen under Network Admin > Groups.
 */
function register_admin_page() {
	add_submenu_page(
		\WordCamp\Groups\Archive\PAGE_SLUG,
		__( 'Add Group Site', 'wordcamporg' ),
		__( 'Add Group Site', 'wordcamporg' ),
		'manage_sites',
		PAGE_SLUG,
		__NAMESPACE__ . '\render_admin_page'
	);
}

/**
 * Handle the create-site form submission.
 *
 * Runs on `admin_init` so it completes before `render_admin_page()` renders
 * on the same request -- no redirect needed, `settings_errors()` picks up
 * whatever this adds to the global settings-errors list.
 */
function handle_form_submission() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified with check_admin_referer() below.
	if ( empty( $_POST['wcorg_create_group_site'] ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_sites' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to create sites on this network.', 'wordcamporg' ) );
	}

	check_admin_referer( NONCE_ACTION, NONCE_NAME );

	$title           = isset( $_POST['group_title'] ) ? sanitize_text_field( wp_unslash( $_POST['group_title'] ) ) : '';
	$slug            = isset( $_POST['group_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['group_slug'] ) ) : '';
	$organizer_login = isset( $_POST['organizer_login'] ) ? sanitize_user( wp_unslash( $_POST['organizer_login'] ), true ) : '';
	$timezone_string = isset( $_POST['timezone_string'] ) ? sanitize_text_field( wp_unslash( $_POST['timezone_string'] ) ) : '';

	$result = create_group_site( $title, $slug, $organizer_login, $timezone_string );

	if ( is_wp_error( $result ) ) {
		add_settings_error( 'wcorg-add-group-site', $result->get_error_code(), $result->get_error_message() );
		return;
	}

	add_settings_error(
		'wcorg-add-group-site',
		'success',
		sprintf(
			/* translators: 1: Site dashboard URL, 2: Site front-end URL. */
			__( 'Group site created. <a href="%1$s">Dashboard</a> | <a href="%2$s">Visit site</a>', 'wordcamporg' ),
			esc_url( get_admin_url( $result ) ),
			esc_url( get_home_url( $result ) )
		),
		'success'
	);
}

/**
 * Render the "Add Group Site" form.
 */
function render_admin_page() {
	if ( ! current_user_can( 'manage_sites' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to create sites on this network.', 'wordcamporg' ) );
	}

	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Add Group Site', 'wordcamporg' ); ?></h1>

		<?php settings_errors( 'wcorg-add-group-site' ); ?>

		<form method="post" action="">
			<?php wp_nonce_field( NONCE_ACTION, NONCE_NAME ); ?>
			<input type="hidden" name="wcorg_create_group_site" value="1" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="group_title"><?php esc_html_e( 'Group name', 'wordcamporg' ); ?></label></th>
					<td><input name="group_title" type="text" id="group_title" class="regular-text" required="required" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="group_slug"><?php esc_html_e( 'Slug', 'wordcamporg' ); ?></label></th>
					<td>
						<code><?php echo esc_html( get_network( GROUPS_NETWORK_ID )->domain . '/group/' ); ?></code>
						<input name="group_slug" type="text" id="group_slug" class="regular-text" required="required" />
						<code>/</code>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="organizer_login"><?php esc_html_e( 'Lead organizer (WordPress.org username)', 'wordcamporg' ); ?></label></th>
					<td><input name="organizer_login" type="text" id="organizer_login" class="regular-text" required="required" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="timezone_string"><?php esc_html_e( 'Timezone', 'wordcamporg' ); ?></label></th>
					<td>
						<select id="timezone_string" name="timezone_string">
							<?php echo wp_timezone_choice( '', get_user_locale() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core function, already escaped. ?>
						</select>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Create Group Site', 'wordcamporg' ) ); ?>
		</form>
	</div>
	<?php
}

/**
 * Create and configure a new Group site.
 *
 * Callers MUST verify the `manage_sites` capability before calling this --
 * it performs no capability check of its own, matching the convention in
 * `WordCamp_New_Site::_create_site()`.
 *
 * @param string $title           The group's display name.
 * @param string $slug            The desired `/group/{slug}/` path segment.
 * @param string $organizer_login The WordPress.org username of the lead organizer.
 * @param string $timezone_string Optional. A PHP timezone identifier (e.g. `Australia/Brisbane`).
 *
 * @return int|WP_Error The new site's ID, or a WP_Error on failure.
 */
function create_group_site( string $title, string $slug, string $organizer_login, string $timezone_string = '' ) {
	$slug = sanitize_title( $slug );

	if ( '' === $slug || ! preg_match( '/^[\w-]+$/', $slug ) ) {
		Logger\log( 'invalid_slug', compact( 'title', 'slug', 'organizer_login' ) );
		return new WP_Error( 'invalid_slug', __( 'Please enter a valid slug.', 'wordcamporg' ) );
	}

	$network = get_network( GROUPS_NETWORK_ID );
	$path    = "/group/{$slug}/";

	if ( domain_exists( $network->domain, $path, GROUPS_NETWORK_ID ) ) {
		Logger\log( 'slug_taken', compact( 'title', 'slug', 'organizer_login' ) );
		return new WP_Error( 'slug_taken', __( 'That slug is already in use by another group.', 'wordcamporg' ) );
	}

	$organizer = get_user_by( 'login', $organizer_login );

	if ( ! $organizer ) {
		Logger\log( 'organizer_not_found', compact( 'title', 'slug', 'organizer_login' ) );
		return new WP_Error( 'organizer_not_found', __( 'No user was found with that WordPress.org username.', 'wordcamporg' ) );
	}

	if ( $timezone_string && ! in_array( $timezone_string, timezone_identifiers_list(), true ) ) {
		Logger\log( 'invalid_timezone', compact( 'title', 'slug', 'organizer_login', 'timezone_string' ) );
		return new WP_Error( 'invalid_timezone', __( 'Please select a valid timezone.', 'wordcamporg' ) );
	}

	$site_id = wp_insert_site(
		array(
			'domain'     => $network->domain,
			'path'       => $path,
			'title'      => $title,
			'network_id' => GROUPS_NETWORK_ID,
			'user_id'    => $organizer->ID,
		)
	);

	if ( is_wp_error( $site_id ) ) {
		Logger\log( 'insert_site_failed', compact( 'title', 'slug', 'organizer_login', 'site_id' ) );
		return $site_id;
	}

	switch_to_blog( $site_id );

	switch_theme( 'groups-site' );

	update_option( 'siteurl', set_url_scheme( get_option( 'siteurl' ), 'https' ) );
	update_option( 'home', set_url_scheme( get_option( 'home' ), 'https' ) );

	if ( $timezone_string ) {
		update_option( 'timezone_string', $timezone_string );
	}

	// `wp_initialize_site()` seeds every new site with generic WP boilerplate
	// ("Hello world!", "Sample Page") via `wp_install_defaults()`. A group
	// site should start from the group template, not stock WP content.
	foreach ( array( array( 'post', 'hello-world' ), array( 'page', 'sample-page' ) ) as list( $post_type, $post_name ) ) {
		$stub = get_page_by_path( $post_name, OBJECT, $post_type );
		if ( $stub ) {
			wp_delete_post( $stub->ID, true );
		}
	}

	// `templates/page-members.html` in the `groups-site` theme only resolves
	// at `/members/` if a page with this slug actually exists.
	$members_page = wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_title'  => __( 'Members', 'wordcamporg' ),
			'post_name'   => 'members',
			'post_status' => 'publish',
			'post_author' => $organizer->ID,
		),
		true
	);

	if ( is_wp_error( $members_page ) ) {
		Logger\log( 'members_page_failed', compact( 'title', 'slug', 'organizer_login', 'site_id' ) );
	}

	// The front page teases the About page, but nothing else creates one.
	// Seed it as a draft: visitors see nothing until the organizer publishes,
	// and the organizer starts from example prose instead of a blank editor.
	// The editor's note goes last so an untouched publish leads with the
	// example prose, not the note.
	$about_content = sprintf(
		'<!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph --><!-- wp:paragraph --><p><em>%s</em></p><!-- /wp:paragraph -->',
		esc_html__( 'We are a local community for everyone who uses, builds, or is simply curious about WordPress — all skill levels welcome.', 'wordcamporg' ),
		esc_html__( 'Our events are a place to share practical knowledge, meet people nearby, and learn together. Join us at an upcoming event and say hello!', 'wordcamporg' ),
		esc_html__( 'Editor’s note: this is example text — replace it with your group’s own story, then delete this note. The first few paragraphs of this page also appear on your group’s home page.', 'wordcamporg' )
	);

	$about_page = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_title'   => __( 'About', 'wordcamporg' ),
			'post_name'    => 'about',
			'post_status'  => 'draft',
			'post_author'  => $organizer->ID,
			'post_content' => $about_content,
		),
		true
	);

	if ( is_wp_error( $about_page ) ) {
		Logger\log( 'about_page_failed', compact( 'title', 'slug', 'organizer_login', 'site_id' ) );
	}

	restore_current_blog();

	Logger\log( 'finished', compact( 'title', 'slug', 'organizer_login', 'site_id' ) );

	return $site_id;
}
