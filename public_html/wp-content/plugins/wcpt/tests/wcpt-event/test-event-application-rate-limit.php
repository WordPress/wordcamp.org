<?php

namespace WordCamp\WCPT\Tests;
use WordPress_Community\Applications\WordCamp_Application;
use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * Tests for the application submitter rate limit.
 *
 * @group wcpt
 */
class Test_Event_Application_Rate_Limit extends WP_UnitTestCase {

	const LIMIT = 3;
	const IP    = '203.0.113.99';

	/**
	 * The application under test.
	 *
	 * @var WordCamp_Application
	 */
	protected $application;

	/**
	 * The address in place before the test replaced it.
	 *
	 * @var string|null
	 */
	protected $original_remote_addr;

	/**
	 * Set up the application and a known submitter address.
	 */
	public function set_up() {
		parent::set_up();

		$this->application          = new WordCamp_Application();
		$this->original_remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;
		$_SERVER['REMOTE_ADDR']     = self::IP;
	}

	/**
	 * Put the address back. The harness resets `$_GET`, `$_POST` and `$_REQUEST`
	 * between tests, but leaves `$_SERVER` alone, so this would otherwise leak into
	 * every class that runs after it.
	 */
	public function tear_down() {
		if ( null === $this->original_remote_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->original_remote_addr;
		}

		parent::tear_down();
	}

	/**
	 * Create applications attributed to the fixture address.
	 *
	 * @param int $count How many to create.
	 *
	 * @return int[] The created post IDs.
	 */
	protected function create_applications( $count ) {
		$ids = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$id = self::factory()->post->create( array(
				'post_type'   => WCPT_POST_TYPE_ID,
				'post_status' => WCPT_DEFAULT_STATUS,
			) );

			add_post_meta( $id, '_application_submitter_ip_address', self::IP );
			$ids[] = $id;
		}

		return $ids;
	}

	/**
	 * @covers WordPress_Community\Applications\Event_Application::is_rate_limited
	 */
	public function test_not_limited_below_the_threshold() {
		$this->create_applications( self::LIMIT - 1 );

		$this->assertFalse( $this->application->is_rate_limited() );
	}

	/**
	 * @covers WordPress_Community\Applications\Event_Application::is_rate_limited
	 */
	public function test_limited_at_the_threshold() {
		$this->create_applications( self::LIMIT );

		$this->assertTrue( $this->application->is_rate_limited() );
	}

	/**
	 * The cap is per address, so another submitter is unaffected.
	 *
	 * @covers WordPress_Community\Applications\Event_Application::is_rate_limited
	 */
	public function test_other_addresses_are_not_limited() {
		$this->create_applications( self::LIMIT );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';

		$this->assertFalse( $this->application->is_rate_limited() );
	}

	/**
	 * Trashing the applications must not hand the submitter a fresh allowance.
	 *
	 * @covers WordPress_Community\Applications\Event_Application::is_rate_limited
	 */
	public function test_trashed_applications_still_count() {
		foreach ( $this->create_applications( self::LIMIT ) as $id ) {
			wp_trash_post( $id );
		}

		$this->assertTrue( $this->application->is_rate_limited() );
	}

	/**
	 * Applications older than the window do not count.
	 *
	 * @covers WordPress_Community\Applications\Event_Application::is_rate_limited
	 */
	public function test_older_applications_are_outside_the_window() {
		$stale = gmdate( 'Y-m-d H:i:s', time() - ( 2 * HOUR_IN_SECONDS ) );

		foreach ( $this->create_applications( self::LIMIT ) as $id ) {
			wp_update_post( array(
				'ID'            => $id,
				'post_date'     => $stale,
				'post_date_gmt' => $stale,
			) );
		}

		$this->assertFalse( $this->application->is_rate_limited() );
	}

	/**
	 * The cap counts by post status, so it only works where they are registered.
	 * The Campus Connect handler registers them before calling this, because the
	 * events network does not.
	 *
	 * @covers WordPress_Community\Applications\Event_Application::is_rate_limited
	 */
	public function test_counting_depends_on_registered_statuses() {
		$this->create_applications( self::LIMIT );

		$this->assertTrue( $this->application->is_rate_limited() );
		$this->assertContains( WCPT_DEFAULT_STATUS, get_post_stati() );
	}
}
