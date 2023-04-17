<?php
/**
 * Google Fonts provider for WP Fonts API.
 *
 * Eventually this should be supported by core, and this file can be deprecated.
 */

if ( ! class_exists( 'WP_Fonts_Provider' ) ) {
	return;
}

add_action(
	'init',
	function() {
		if ( function_exists( 'wp_fonts' ) ) {
			wp_fonts()->register_provider( 'wordcamp-google', 'WC_Fonts_Provider_Google' );
		}
	}
);

class WC_Fonts_Provider_Google extends WP_Fonts_Provider {

	/**
	 * The provider's unique ID.
	 *
	 * @var string
	 */
	protected $id = 'wordcamp-google';

	/**
	 * Constructor.
	 */
	public function __construct() {
		if (
			function_exists( 'is_admin' ) && ! is_admin() &&
			function_exists( 'current_theme_supports' ) && ! current_theme_supports( 'html5', 'style' )
		) {
			$this->style_tag_atts = array( 'type' => 'text/css' );
		}
	}

	/**
	 * Build the `@import` statement for Google's font API.
	 *
	 * @return string The `@font-face` CSS.
	 */
	public function get_css() {
		$css = '';

		foreach ( $this->fonts as $font ) {
			$font_style = $font['font-style'] ?? 'normal';
			$font_weight = $font['font-weight'] ?? '400';
			// Rebuild the google font URL to explicitly load the enqueued font.
			$css .= sprintf(
				'@import url("https://fonts.googleapis.com/css2?family=%1$s:ital,wght@%2$s,%3$s");',
				urlencode( $font['font-family'] ),
				'normal' === $font_style ? '0' : '1',
				str_replace( ' ', '..', $font_weight )
			);
		}

		return $css;
	}
}
