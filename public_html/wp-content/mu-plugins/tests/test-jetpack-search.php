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
	 * Provisioning turning the overlay on for the first time is blocked.
	 *
	 * @covers \WordCamp\Jetpack_Tweaks\Search\block_provisioned_enable
	 * @covers \WordCamp\Jetpack_Tweaks\Search\block_provisioned_overlay
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
	 * Provisioning switching an existing experience to the overlay is blocked too.
	 *
	 * @covers \WordCamp\Jetpack_Tweaks\Search\block_provisioned_overlay
	 */
	public function test_provisioning_cannot_update_to_overlay() {
		// Seed both options, the state of a site being re-provisioned.
		add_option( 'jetpack_search_experience', 'embedded' );
		add_option( 'instant_search_enabled', '' );

		$this->act_as_provisioning();
		update_option( 'jetpack_search_experience', 'overlay' );
		update_option( 'instant_search_enabled', true );

		$this->assertSame( '', get_option( 'jetpack_search_experience' ) );
		$this->assertFalse( (bool) get_option( 'instant_search_enabled' ) );
	}

	/**
	 * Re-provisioning a site that already has the overlay stored clears it.
	 *
	 * Core drops a write of the value already stored, so this needs the filter to run before that check.
	 *
	 * @covers \WordCamp\Jetpack_Tweaks\Search\block_provisioned_enable
	 * @covers \WordCamp\Jetpack_Tweaks\Search\block_provisioned_overlay
	 */
	public function test_reprovisioning_clears_stored_overlay() {
		add_option( 'jetpack_search_experience', 'overlay' );
		add_option( 'instant_search_enabled', true );

		$this->act_as_provisioning();

		// What `Module_Control::update_experience( 'overlay' )` writes.
		update_option( 'jetpack_search_experience', 'overlay' );
		update_option( 'instant_search_enabled', true );
		update_option( 'jetpack_search_experience', 'overlay' );

		$this->assertSame( '', get_option( 'jetpack_search_experience' ) );
		$this->assertFalse( (bool) get_option( 'instant_search_enabled' ) );
	}

	/**
	 * Provisioning can't pick the blocks-powered overlay either.
	 *
	 * @covers \WordCamp\Jetpack_Tweaks\Search\block_provisioned_overlay
	 */
	public function test_provisioning_cannot_pick_overlay_blocks() {
		$this->act_as_provisioning();
		update_option( 'jetpack_search_experience', 'overlay_blocks' );

		$this->assertSame( '', get_option( 'jetpack_search_experience' ) );
	}

	/**
	 * Provisioning picking a non-overlay experience is left alone.
	 *
	 * @covers \WordCamp\Jetpack_Tweaks\Search\block_provisioned_overlay
	 */
	public function test_provisioning_can_pick_other_experiences() {
		$this->act_as_provisioning();

		update_option( 'jetpack_search_experience', 'inline' );
		$this->assertSame( 'inline', get_option( 'jetpack_search_experience' ) );

		update_option( 'jetpack_search_experience', 'embedded' );
		$this->assertSame( 'embedded', get_option( 'jetpack_search_experience' ) );
	}

	/**
	 * An organizer's own request (not a signed connection-owner request) can turn either overlay on.
	 *
	 * @covers \WordCamp\Jetpack_Tweaks\Search\block_provisioned_enable
	 * @covers \WordCamp\Jetpack_Tweaks\Search\block_provisioned_overlay
	 */
	public function test_organizer_can_enable_overlay() {
		update_option( 'jetpack_search_experience', 'overlay' );
		update_option( 'instant_search_enabled', true );

		$this->assertSame( 'overlay', get_option( 'jetpack_search_experience' ) );
		$this->assertTrue( (bool) get_option( 'instant_search_enabled' ) );

		update_option( 'jetpack_search_experience', 'overlay_blocks' );
		$this->assertSame( 'overlay_blocks', get_option( 'jetpack_search_experience' ) );
	}

	/**
	 * Provisioning turning the overlay off goes through.
	 *
	 * @covers \WordCamp\Jetpack_Tweaks\Search\block_provisioned_enable
	 * @covers \WordCamp\Jetpack_Tweaks\Search\block_provisioned_overlay
	 */
	public function test_provisioning_can_turn_overlay_off() {
		update_option( 'jetpack_search_experience', 'overlay' );
		update_option( 'instant_search_enabled', true );

		$this->act_as_provisioning();
		update_option( 'jetpack_search_experience', '' );
		update_option( 'instant_search_enabled', false );

		$this->assertSame( '', get_option( 'jetpack_search_experience' ) );
		$this->assertFalse( (bool) get_option( 'instant_search_enabled' ) );
	}

	/**
	 * Without a Jetpack connection the identity check fails closed: nothing is blocked.
	 *
	 * @covers \WordCamp\Jetpack_Tweaks\Search\is_connection_owner_request
	 */
	public function test_identity_check_fails_closed_without_jetpack() {
		$this->assertFalse( \WordCamp\Jetpack_Tweaks\Search\is_connection_owner_request() );
	}
}
