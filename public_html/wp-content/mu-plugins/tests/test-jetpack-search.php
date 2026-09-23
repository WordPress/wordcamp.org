<?php

namespace WordCamp\Tests;

use WP_UnitTestCase;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/jetpack-tweaks/search.php';

/**
 * Tests that WordPress.com provisioning can't turn on the Jetpack Search overlay.
 *
 * @group mu-plugins
 * @group jetpack
 */
class Test_Jetpack_Search extends WP_UnitTestCase {
	/**
	 * Treat every option write in the test as coming from provisioning.
	 */
	protected function act_as_provisioning() {
		add_filter( 'wcorg_jetpack_search_is_provisioning_request', '__return_true' );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		remove_filter( 'wcorg_jetpack_search_is_provisioning_request', '__return_true' );
		delete_option( 'instant_search_enabled' );
		delete_option( 'jetpack_search_experience' );

		parent::tear_down();
	}

	/**
	 * Provisioning turning the overlay on for the first time is reverted.
	 *
	 * @covers \WordCamp\Jetpack_Tweaks\Search\maybe_revert_provisioned_enable
	 * @covers \WordCamp\Jetpack_Tweaks\Search\maybe_revert_provisioned_overlay
	 */
	public function test_provisioning_cannot_add_overlay() {
		$this->act_as_provisioning();

		// The order Jetpack's `enable_instant_search()` writes them in.
		update_option( 'jetpack_search_experience', 'overlay' );
		update_option( 'instant_search_enabled', true );

		$this->assertSame( '', get_option( 'jetpack_search_experience' ) );
		$this->assertFalse( (bool) get_option( 'instant_search_enabled' ) );
	}

	/**
	 * Provisioning switching an existing experience to the overlay is reverted too.
	 *
	 * @covers \WordCamp\Jetpack_Tweaks\Search\maybe_revert_provisioned_overlay
	 */
	public function test_provisioning_cannot_update_to_overlay() {
		// Seed both options so the writes below go through the update hooks, the path a re-provisioned site takes.
		add_option( 'jetpack_search_experience', 'embedded' );
		add_option( 'instant_search_enabled', '' );

		$this->act_as_provisioning();
		update_option( 'jetpack_search_experience', 'overlay' );
		update_option( 'instant_search_enabled', true );

		$this->assertSame( '', get_option( 'jetpack_search_experience' ) );
		$this->assertFalse( (bool) get_option( 'instant_search_enabled' ) );
	}

	/**
	 * Provisioning picking a non-overlay experience is left alone.
	 *
	 * @covers \WordCamp\Jetpack_Tweaks\Search\maybe_revert_provisioned_overlay
	 */
	public function test_provisioning_can_pick_other_experiences() {
		$this->act_as_provisioning();

		update_option( 'jetpack_search_experience', 'inline' );
		$this->assertSame( 'inline', get_option( 'jetpack_search_experience' ) );

		update_option( 'jetpack_search_experience', 'embedded' );
		$this->assertSame( 'embedded', get_option( 'jetpack_search_experience' ) );
	}

	/**
	 * An organizer's own request (not a signed connection-owner request) can turn the overlay on.
	 *
	 * @covers \WordCamp\Jetpack_Tweaks\Search\maybe_revert_provisioned_enable
	 * @covers \WordCamp\Jetpack_Tweaks\Search\maybe_revert_provisioned_overlay
	 */
	public function test_organizer_can_enable_overlay() {
		update_option( 'jetpack_search_experience', 'overlay' );
		update_option( 'instant_search_enabled', true );

		$this->assertSame( 'overlay', get_option( 'jetpack_search_experience' ) );
		$this->assertTrue( (bool) get_option( 'instant_search_enabled' ) );
	}

	/**
	 * Provisioning turning the overlay off is not something to revert.
	 *
	 * @covers \WordCamp\Jetpack_Tweaks\Search\maybe_revert_provisioned_enable
	 * @covers \WordCamp\Jetpack_Tweaks\Search\maybe_revert_provisioned_overlay
	 */
	public function test_provisioning_can_turn_overlay_off() {
		update_option( 'jetpack_search_experience', 'overlay' );
		update_option( 'instant_search_enabled', true );

		// A revert would call `update_option()` with the value just written, which core drops as a no-op, so the
		// end state alone can't tell a revert from no revert. Count the writes instead: `pre_update_option_*` runs
		// before that no-op check, so it sees every call, and only the test's own write is expected.
		$writes = array();
		$count  = function ( $value, $old_value, $option ) use ( &$writes ) {
			$writes[ $option ] = ( $writes[ $option ] ?? 0 ) + 1;
			return $value;
		};
		add_filter( 'pre_update_option_jetpack_search_experience', $count, 10, 3 );
		add_filter( 'pre_update_option_instant_search_enabled', $count, 10, 3 );

		$this->act_as_provisioning();
		update_option( 'jetpack_search_experience', '' );
		update_option( 'instant_search_enabled', false );

		remove_filter( 'pre_update_option_jetpack_search_experience', $count, 10 );
		remove_filter( 'pre_update_option_instant_search_enabled', $count, 10 );

		$this->assertSame( '', get_option( 'jetpack_search_experience' ) );
		$this->assertFalse( (bool) get_option( 'instant_search_enabled' ) );
		$this->assertSame( 1, $writes['jetpack_search_experience'], 'The hook re-wrote the experience option.' );
		$this->assertSame( 1, $writes['instant_search_enabled'], 'The hook re-wrote the legacy boolean.' );
	}

	/**
	 * Without a Jetpack connection the identity check fails closed: nothing is reverted.
	 *
	 * @covers \WordCamp\Jetpack_Tweaks\Search\is_connection_owner_request
	 */
	public function test_identity_check_fails_closed_without_jetpack() {
		$this->assertFalse( \WordCamp\Jetpack_Tweaks\Search\is_connection_owner_request() );
	}
}
