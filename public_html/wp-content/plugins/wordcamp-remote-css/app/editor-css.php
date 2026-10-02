<?php

namespace WordCamp\RemoteCSS;

defined( 'WPINC' ) || die();

if ( is_configured() ) {
	add_filter( 'block_editor_settings_all', __NAMESPACE__ . '\add_cached_css_to_editor' );
}

/**
 * Add the cached CSS to the block editor
 *
 * Passing the CSS through the editor settings (instead of enqueueing the stylesheet) lets the editor load it into
 * the iframed canvas, or scope it to `.editor-styles-wrapper` when the canvas isn't iframed, so it doesn't affect
 * the rest of wp-admin.
 *
 * @param array $settings
 *
 * @return array
 */
function add_cached_css_to_editor( $settings ) {
	$safe_css = get_safe_css_post()->post_content;

	if ( ! empty( $safe_css ) ) {
		$settings['styles'][] = array(
			'css'            => $safe_css,
			'__unstableType' => 'theme',
			'isGlobalStyles' => false,
		);
	}

	return $settings;
}
