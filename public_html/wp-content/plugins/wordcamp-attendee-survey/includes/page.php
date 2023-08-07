<?php
/**
 * Adds an admin page.
 */

namespace WordCamp\AttendeeSurvey\Page;

defined( 'WPINC' ) || die();

use function WordCamp\AttendeeSurvey\{get_option_key};

add_action( 'init', __NAMESPACE__ . '\load' );

/**
 * Include the rest of the plugin.
 *
 * @return void
 */
function load() {
	// Check if the page exists, and add it if not.
	add_action( 'init', __NAMESPACE__ . '\add_page' );
}

/**
 * Create the Survey page, save ID into an option.
 *
 * @return void
 */
function add_page() {
	$page_id = get_option( get_option_key() );
	if ( $page_id ) {
		return;
	}

	$content .= '<!-- wp:paragraph -->';
	$content .= '<p>';
	$content .= __( 'Insert survey here', 'wordcamporg' );
	$content .= '</p>';
	$content .= '<!-- /wp:paragraph -->';

	$page_id = wp_insert_post( array(
		'post_title'   => __( 'Attendee Survey', 'wordcamporg' ),
		/* translators: Page slug for the attendee survey. */
		'post_name'    => __( 'attendee survey', 'wordcamporg' ),
		'post_content' => $content,
		'post_status'  => 'draft',
		'post_type'    => 'page',
	) );

	if ( $page_id > 0 ) {
		update_option( get_option_key(), $page_id );
	}
}
