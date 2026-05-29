<?php

require_once WP_PLUGIN_DIR . '/wc-fonts/wc-fonts.php';

/**
 * @group mu-plugins
 * @group wc-fonts
 */
class Test_WordCamp_Fonts_Plugin extends WP_UnitTestCase {
	/**
	 * @covers WordCamp_Fonts_Plugin::get_normalized_theme_font_families
	 */
	public function test_incomparable_theme_font_saves_keep_only_shared_fonts() {
		$plugin = new WordCamp_Fonts_Plugin();

		$incoming = array(
			array(
				'slug' => 'fira-code',
			),
		);
		$saved    = array(
			array(
				'slug' => 'manrope',
			),
		);

		$this->assertSame( array(), $plugin->get_normalized_theme_font_families( $incoming, $saved ) );
	}

	/**
	 * @covers WordCamp_Fonts_Plugin::get_normalized_theme_font_families
	 */
	public function test_subset_theme_font_saves_are_left_unchanged() {
		$plugin = new WordCamp_Fonts_Plugin();

		$incoming = array(
			array(
				'slug' => 'manrope',
			),
		);
		$saved    = array(
			array(
				'slug' => 'fira-code',
			),
			array(
				'slug' => 'manrope',
			),
		);

		$this->assertSame( $incoming, $plugin->get_normalized_theme_font_families( $incoming, $saved ) );
	}
}
