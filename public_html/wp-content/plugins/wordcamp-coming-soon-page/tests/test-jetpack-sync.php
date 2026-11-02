<?php

namespace WordCamp\Coming_Soon_Page\Tests;

use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * @group coming-soon-page
 *
 * @covers WCCSP_Settings::prevent_post_sync
 */
class Test_Jetpack_Sync extends WP_UnitTestCase {
	/**
	 * Ask the filter the way Jetpack does before sending a post.
	 *
	 * @return bool
	 */
	protected function sync_prevented() {
		return (bool) apply_filters( 'jetpack_sync_prevent_sending_post_data', false, get_post( self::factory()->post->create() ) );
	}

	/**
	 * Post data follows the setting.
	 */
	public function test_post_data_follows_the_setting() {
		update_option( 'wccsp_settings', array( 'enabled' => 'on' ) );
		$this->assertTrue( $this->sync_prevented() );

		update_option( 'wccsp_settings', array( 'enabled' => 'off' ) );
		$this->assertFalse( $this->sync_prevented() );
	}

	/**
	 * Another plugin's decision to withhold a post is kept.
	 */
	public function test_keeps_an_earlier_prevent() {
		update_option( 'wccsp_settings', array( 'enabled' => 'off' ) );
		add_filter( 'jetpack_sync_prevent_sending_post_data', '__return_true', 5 );

		$this->assertTrue( $this->sync_prevented() );
	}
}
