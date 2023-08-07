<?php
/**
 * Adds an admin page.
 */

namespace WordCamp\AttendeeSurvey\AdminPage;

defined( 'WPINC' ) || die();

use function WordCamp\AttendeeSurvey\{get_option_key};

add_action( 'init', __NAMESPACE__ . '\load' );

/**
 * Include the rest of the plugin.
 *
 * @return void
 */
function load() {
	add_action( 'admin_menu', __NAMESPACE__ . '\admin_menu' );
}

/**
 * Add a menu item.
 */
function admin_menu() {
	add_menu_page(
		__( 'WordCamp Attendee Survey', 'wordcamporg' ),
		__( 'Attendee Survey', 'wordcamporg' ),
		'manage_options',
		get_option_key(),
		__NAMESPACE__ . '\render_menu_page',
		'dashicons-feedback',
		58
	);
}

/**
 * Render the menu page.
 */
function render_menu_page() {
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Attendees', 'wordcamporg' ); ?></h1>
	</div>
	<?php
}
