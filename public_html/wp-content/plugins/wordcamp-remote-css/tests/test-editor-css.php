<?php

namespace WordCamp\RemoteCSS;
use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * Class Test_Editor_CSS
 *
 * @group remote-css
 */
class Test_Editor_CSS extends WP_UnitTestCase {
	/**
	 * Test that the cached CSS is added to the editor styles
	 *
	 * @covers \WordCamp\RemoteCSS\add_cached_css_to_editor()
	 */
	public function test_cached_css_added_to_editor_styles() {
		$existing_style = array( 'css' => 'body { color: blue; }' );

		self::factory()->post->create( array(
			'post_type'    => POST_TYPE,
			'post_name'    => SAFE_CSS_POST_SLUG,
			'post_status'  => 'private',
			'post_content' => 'p { color: red; }',
		) );

		$settings = add_cached_css_to_editor( array( 'styles' => array( $existing_style ) ) );

		$this->assertSame(
			array(
				$existing_style,
				array(
					'css'            => 'p { color: red; }',
					'__unstableType' => 'theme',
					'isGlobalStyles' => false,
				),
			),
			$settings['styles']
		);
	}

	/**
	 * Test that nothing is added when there isn't any cached CSS
	 *
	 * @covers \WordCamp\RemoteCSS\add_cached_css_to_editor()
	 */
	public function test_nothing_added_when_cached_css_empty() {
		$settings = array( 'styles' => array() );

		$this->assertSame( $settings, add_cached_css_to_editor( $settings ) );
	}

	/**
	 * Test that the editor still loads when the cached CSS post can't be created
	 *
	 * @covers \WordCamp\RemoteCSS\add_cached_css_to_editor()
	 */
	public function test_settings_unchanged_when_css_post_cannot_be_created() {
		$settings = array( 'styles' => array() );

		add_filter( 'wp_insert_post_empty_content', '__return_true' );

		$this->assertSame( $settings, add_cached_css_to_editor( $settings ) );
	}
}
