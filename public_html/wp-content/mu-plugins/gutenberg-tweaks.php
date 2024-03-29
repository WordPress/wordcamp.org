<?php

namespace WordCamp\Gutenberg_Tweaks;

defined( 'WPINC' ) || die();

add_filter( 'classic_editor_network_default_settings', __NAMESPACE__ . '\classic_editor_default_settings' );
add_filter( 'classic_editor_enabled_editors_for_post_type', __NAMESPACE__ . '\disable_editors_by_post_type', 10, 2 );
add_action( 'after_setup_theme', __NAMESPACE__ . '\enable_block_templates' );
add_filter( 'font_dir', __NAMESPACE__ . '\fonts_in_uploads' );

/**
 * Configure the default settings for the Classic Editor
 */
function classic_editor_default_settings( $defaults ) {
	$defaults['editor']      = 'block';
	$defaults['allow-users'] = true;

	return $defaults;
}

/**
 * Remove editor options (classic or Gutenberg) in post types that don't support them.
 *
 * @param array  $editors
 * @param string $post_type
 *
 * @return array
 */
function disable_editors_by_post_type( $editors, $post_type ) {
	/*
	 * These post-types should only be edited in Gutenberg.
	 * @todo Uncomment these as the metaboxes are converted into gutenberg-native panels.
	 */
	$gutenberg_only = array(
		'wcb_session',
		'wcb_speaker',
		// 'wcb_sponsor',
		'mes', // Metaboxes not converted yet, but has other custom Gutenberg UI.
		'wcb_organizer',
		'wcb_volunteer',
	);

	/*
	 * These have custom interfaces/interactions that haven't been ported to Gutenberg yet.
	 */
	$classic_only = array();

	if ( defined( 'WCPT_POST_TYPE_ID' ) ) {
		$classic_only[] = WCPT_POST_TYPE_ID;
	}

	// Currently not necessary to set on these post types, they don't support gutenberg. This is either because
	// they don't support the `editor`, or they have `public`/`show_in_rest` set to false:
	// WCPT_MEETUP_SLUG, Payment CPTs, CampTix CPTs.

	if ( in_array( $post_type, $gutenberg_only ) ) {
		$editors['classic_editor'] = false;
	}
	if ( in_array( $post_type, $classic_only ) ) {
		$editors['block_editor'] = false;
	}
	return $editors;
}

/**
 * Enable Templates and Template Parts post types for all themes.
 */
function enable_block_templates() {
	add_theme_support( 'block-templates' );
}

/**
 * Store fonts in the Upload Directory.
 *
 * @see wp_get_font_dir()
 *
 * @param array $fonts_dir The fonts directory, expected to reference wp-content/fonts/$site/.
 * @return array The uploads fonts directory, expected to reference wp-content/uploads/$site/fonts.
 */
function fonts_in_uploads( $fonts_dir ) {

	// This is fragile, but wp_get_font_dir doesn't pass it's raw wp_upload_dir() value through.
	remove_filter( 'upload_dir', 'wp_get_font_dir' );
	$upload_dir = wp_upload_dir();
	add_filter( 'upload_dir', 'wp_get_font_dir' );

	$fonts_dir['basedir'] = $upload_dir['basedir'] . '/fonts';
	$fonts_dir['baseurl'] = $upload_dir['baseurl'] . '/fonts';
	$fonts_dir['path']    = $fonts_dir['basedir'];
	$fonts_dir['url']     = $fonts_dir['baseurl'];

	return $fonts_dir;
}
