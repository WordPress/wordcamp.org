<?php

namespace WordCamp\Tests;

use WP_UnitTestCase;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/jetpack-tweaks/search.php';

/**
 * Class Test_Jetpack_Search
 *
 * @group mu-plugins
 * @group jetpack-tweaks
 * @group jetpack-search
 *
 * @package WordCamp\Tests
 */
class Test_Jetpack_Search extends WP_UnitTestCase {

	/**
	 * Reset the opt-in filter between tests.
	 */
	public function tear_down() {
		remove_all_filters( 'wordcamp_enable_jetpack_instant_search' );

		parent::tear_down();
	}

	/**
	 * The overlay is reported as disabled even when the option is stored as enabled.
	 *
	 * The provisioning flow writes `instant_search_enabled = true` to the database, so this
	 * is the real-world state we need to override.
	 */
	public function test_instant_search_disabled_when_option_enabled() {
		update_option( 'instant_search_enabled', true );

		$this->assertFalse( (bool) get_option( 'instant_search_enabled' ) );
	}

	/**
	 * The overlay stays disabled even if a caller requests a truthy default for an unset option.
	 */
	public function test_instant_search_default_is_disabled() {
		delete_option( 'instant_search_enabled' );

		$this->assertFalse( (bool) get_option( 'instant_search_enabled', true ) );
	}

	/**
	 * A site can opt back in via the `wordcamp_enable_jetpack_instant_search` filter.
	 */
	public function test_instant_search_can_be_re_enabled() {
		update_option( 'instant_search_enabled', true );
		add_filter( 'wordcamp_enable_jetpack_instant_search', '__return_true' );

		$this->assertTrue( (bool) get_option( 'instant_search_enabled' ) );
	}
}
