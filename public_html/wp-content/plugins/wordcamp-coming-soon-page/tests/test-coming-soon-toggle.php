<?php

namespace WordCamp\Coming_Soon_Page\Tests;

use WCCSP_Customizer;
use WP_Error;
use WP_UnitTestCase;
use WP_UnitTest_Factory;

defined( 'WPINC' ) || die();

/**
 * @group coming-soon-page
 *
 * @covers WCCSP_Customizer::maybe_prevent_disable
 */
class Test_Coming_Soon_Toggle extends WP_UnitTestCase {
	/**
	 * @var int
	 */
	protected static $admin_user_id;

	/**
	 * @var int
	 */
	protected static $organizer_user_id;

	/**
	 * @var WCCSP_Customizer
	 */
	protected static $customizer;

	/**
	 * Set up shared fixtures.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		self::$admin_user_id = $factory->user->create( array(
			'role' => 'administrator',
		) );

		self::$organizer_user_id = $factory->user->create( array(
			'role' => 'editor',
		) );

		self::$customizer = new WCCSP_Customizer();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		parent::tear_down();

		// Reset to a non-privileged user.
		wp_set_current_user( 0 );
	}

	/**
	 * Helper to create a mock customizer that returns the specified status from get_status().
	 *
	 * @param string|null $status The WordCamp post status.
	 *
	 * @return WCCSP_Customizer
	 */
	protected function get_customizer_with_status( $status ) {
		$mock = $this->getMockBuilder( WCCSP_Customizer::class )
			->onlyMethods( array( 'get_status' ) )
			->getMock();

		$mock->method( 'get_status' )->willReturn( $status );

		return $mock;
	}

	/**
	 * Grant the wrangler capability to all capability checks for the current user.
	 *
	 * @param bool[]   $allcaps All capabilities for the user.
	 * @param string[] $caps    Required primitive capabilities.
	 * @param array    $args    Arguments for the capability check.
	 *
	 * @return bool[]
	 */
	public function grant_wrangler_cap( $allcaps, $caps, $args ) {
		$allcaps['wordcamp_wrangle_wordcamps'] = true;
		return $allcaps;
	}

	/**
	 * Test that an admin/wrangler can always toggle the coming-soon page regardless of status.
	 */
	public function test_wrangler_can_always_enable() {
		wp_set_current_user( self::$admin_user_id );
		add_filter( 'user_has_cap', array( $this, 'grant_wrangler_cap' ), 10, 3 );

		$customizer = $this->get_customizer_with_status( 'wcpt-closed' );
		$result     = $customizer->maybe_prevent_disable( true, 'on' );

		remove_filter( 'user_has_cap', array( $this, 'grant_wrangler_cap' ), 10 );
		$this->assertTrue( $result );
	}

	/**
	 * Test that an admin/wrangler can always disable the coming-soon page regardless of status.
	 */
	public function test_wrangler_can_always_disable() {
		wp_set_current_user( self::$admin_user_id );
		add_filter( 'user_has_cap', array( $this, 'grant_wrangler_cap' ), 10, 3 );

		$customizer = $this->get_customizer_with_status( 'wcpt-pre-planning' );
		$result     = $customizer->maybe_prevent_disable( true, 'off' );

		remove_filter( 'user_has_cap', array( $this, 'grant_wrangler_cap' ), 10 );
		$this->assertTrue( $result );
	}

	/**
	 * Test that a non-admin cannot enable coming-soon on a closed event.
	 */
	public function test_organizer_cannot_enable_on_closed_event() {
		wp_set_current_user( self::$organizer_user_id );

		$customizer = $this->get_customizer_with_status( 'wcpt-closed' );
		$result     = $customizer->maybe_prevent_disable( true, 'on' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wcpt-closed', $result->get_error_code() );
	}

	/**
	 * Test that a non-admin can enable coming-soon for a scheduled event.
	 */
	public function test_organizer_can_enable_on_scheduled_event() {
		wp_set_current_user( self::$organizer_user_id );

		$customizer = $this->get_customizer_with_status( 'wcpt-scheduled' );
		$result     = $customizer->maybe_prevent_disable( true, 'on' );

		$this->assertTrue( $result );
	}

	/**
	 * Test that a non-admin can enable coming-soon for a pre-planning event.
	 */
	public function test_organizer_can_enable_on_pre_planning_event() {
		wp_set_current_user( self::$organizer_user_id );

		$customizer = $this->get_customizer_with_status( 'wcpt-pre-planning' );
		$result     = $customizer->maybe_prevent_disable( true, 'on' );

		$this->assertTrue( $result );
	}

	/**
	 * Test that a non-admin can disable coming-soon when status is wcpt-scheduled.
	 */
	public function test_organizer_can_disable_on_scheduled_event() {
		wp_set_current_user( self::$organizer_user_id );

		$customizer = $this->get_customizer_with_status( 'wcpt-scheduled' );
		$result     = $customizer->maybe_prevent_disable( true, 'off' );

		$this->assertTrue( $result );
	}

	/**
	 * Test that a non-admin can disable coming-soon when status is wcpt-closed.
	 */
	public function test_organizer_can_disable_on_closed_event() {
		wp_set_current_user( self::$organizer_user_id );

		$customizer = $this->get_customizer_with_status( 'wcpt-closed' );
		$result     = $customizer->maybe_prevent_disable( true, 'off' );

		$this->assertTrue( $result );
	}

	/**
	 * Test that a non-admin cannot disable coming-soon when status is not scheduled or closed.
	 */
	public function test_organizer_cannot_disable_on_pre_planning_event() {
		wp_set_current_user( self::$organizer_user_id );

		$customizer = $this->get_customizer_with_status( 'wcpt-pre-planning' );
		$result     = $customizer->maybe_prevent_disable( true, 'off' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wcpt-not-in-schedule', $result->get_error_code() );
	}

	/**
	 * Test that a non-admin cannot disable coming-soon when status is needs-vetting.
	 */
	public function test_organizer_cannot_disable_on_needs_vetting_event() {
		wp_set_current_user( self::$organizer_user_id );

		$customizer = $this->get_customizer_with_status( 'wcpt-needs-vetting' );
		$result     = $customizer->maybe_prevent_disable( true, 'off' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wcpt-not-in-schedule', $result->get_error_code() );
	}
}
