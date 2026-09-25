<?php

namespace WordCamp\Tests;

use WP_UnitTestCase;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/gutenberg-tweaks.php';

/**
 * Tests that the full-page client-side navigation experiment stays off.
 *
 * @group mu-plugins
 * @group gutenberg-tweaks
 */
class Test_Gutenberg_Tweaks extends WP_UnitTestCase {
	/**
	 * A site that turned the experiment on doesn't get it.
	 *
	 * @covers \WordCamp\Gutenberg_Tweaks\disable_full_page_client_side_navigation
	 */
	public function test_full_page_client_side_navigation_is_removed() {
		update_option(
			'gutenberg-experiments',
			array( 'gutenberg-full-page-client-side-navigation' => '1' )
		);

		$this->assertSame( array(), get_option( 'gutenberg-experiments' ) );
	}

	/**
	 * The other experiments are left as they were stored.
	 *
	 * @covers \WordCamp\Gutenberg_Tweaks\disable_full_page_client_side_navigation
	 */
	public function test_other_experiments_are_untouched() {
		update_option(
			'gutenberg-experiments',
			array(
				'gutenberg-full-page-client-side-navigation' => '1',
				'gutenberg-form-blocks'                      => '1',
				'gutenberg-color-randomizer'                 => false,
				'gutenberg-block-experiments'                => '1',
			)
		);

		$this->assertSame(
			array(
				'gutenberg-form-blocks'       => '1',
				'gutenberg-color-randomizer'  => false,
				'gutenberg-block-experiments' => '1',
			),
			get_option( 'gutenberg-experiments' )
		);
	}
}
