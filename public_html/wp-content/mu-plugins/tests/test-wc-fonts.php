<?php

require_once WP_PLUGIN_DIR . '/wc-fonts/wc-fonts.php';

/**
 * @group mu-plugins
 * @group wc-fonts
 */
class Test_WordCamp_Fonts_Plugin extends WP_UnitTestCase {
	/**
	 * @covers WordCamp_Fonts_Plugin::normalize_theme_font_families
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

		$this->assertSame( array(), $plugin->normalize_theme_font_families( $incoming, $saved ) );
	}

	/**
	 * @covers WordCamp_Fonts_Plugin::normalize_theme_font_families
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

		$this->assertSame( $incoming, $plugin->normalize_theme_font_families( $incoming, $saved ) );
	}

	/**
	 * @covers WordCamp_Fonts_Plugin::normalize_theme_font_families
	 */
	public function test_superset_theme_font_saves_are_left_unchanged() {
		$plugin = new WordCamp_Fonts_Plugin();

		$incoming = array(
			array(
				'slug' => 'fira-code',
			),
			array(
				'slug' => 'manrope',
			),
		);
		$saved    = array(
			array(
				'slug' => 'manrope',
			),
		);

		$this->assertSame( $incoming, $plugin->normalize_theme_font_families( $incoming, $saved ) );
	}

	/**
	 * @covers WordCamp_Fonts_Plugin::normalize_theme_font_families
	 */
	public function test_identical_theme_font_saves_are_left_unchanged() {
		$plugin = new WordCamp_Fonts_Plugin();

		$incoming = array(
			array(
				'slug' => 'manrope',
			),
		);

		$this->assertSame( $incoming, $plugin->normalize_theme_font_families( $incoming, $incoming ) );
	}

	/**
	 * @covers WordCamp_Fonts_Plugin::normalize_theme_font_families
	 */
	public function test_incomparable_theme_font_saves_keep_multiple_shared_fonts() {
		$plugin = new WordCamp_Fonts_Plugin();

		$incoming = array(
			array(
				'slug' => 'fira-code',
			),
			array(
				'slug' => 'manrope',
			),
			array(
				'slug' => 'inter',
			),
		);
		$saved    = array(
			array(
				'slug' => 'manrope',
			),
			array(
				'slug' => 'inter',
			),
			array(
				'slug' => 'source-serif-4',
			),
		);

		$this->assertSame(
			array(
				array(
					'slug' => 'manrope',
				),
				array(
					'slug' => 'inter',
				),
			),
			$plugin->normalize_theme_font_families( $incoming, $saved )
		);
	}
}
