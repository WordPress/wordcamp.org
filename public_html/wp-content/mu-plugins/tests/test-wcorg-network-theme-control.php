<?php

namespace WordCamp\Groups\Tests;

use function WordCamp\Themes\Network\filter_prepared_themes;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/../wporg-groups-frontend/tests/class-groups-testcase.php';

/**
 * @group mu-plugins
 * @group groups
 */
class Test_WCORG_Network_Theme_Control extends Groups_TestCase {
	/**
	 * @var \WP_Network
	 */
	private $original_current_site;

	/**
	 * `groups-site` (and its `wporg-parent-2021` parent) only exist in this
	 * repo's `wp-content/themes`, not in the vanilla WP install that the
	 * test suite runs against. Register the real theme directory so
	 * `switch_theme( 'groups-site' )` resolves to the actual theme instead
	 * of a nonexistent one.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		parent::wpSetUpBeforeClass( $factory );

		register_theme_directory( SUT_WP_CONTENT_DIR . 'themes' );
		search_theme_directories( true );
	}

	protected function setUp(): void {
		parent::setUp();

		$this->original_current_site = $GLOBALS['current_site'];
	}

	protected function tearDown(): void {
		$GLOBALS['current_site'] = $this->original_current_site;

		parent::tearDown();
	}

	/**
	 * `get_current_network_id()` reflects `$GLOBALS['current_site']`, i.e.
	 * the network the request was bootstrapped for -- it does *not* change
	 * when `switch_to_blog()` moves to a site on a different network.
	 * That matches WordCamp.org's real architecture (each network is a
	 * separate domain/request), so simulate "being on network X" by
	 * swapping this global directly, rather than relying on
	 * `switch_to_blog()` alone.
	 */
	private function set_current_network( int $network_id ) {
		$GLOBALS['current_site'] = get_network( $network_id );
	}

	/**
	 * @covers \WordCamp\Themes\Network\filter_prepared_themes()
	 */
	public function test_restricted_theme_hidden_on_wrong_network() {
		$this->set_current_network( WORDCAMP_NETWORK_ID );

		$prepared = filter_prepared_themes( array(
			'groups-site'     => array( 'id' => 'groups-site' ),
			'twentytwentyone' => array( 'id' => 'twentytwentyone' ),
		) );

		$this->assertArrayNotHasKey( 'groups-site', $prepared );
		$this->assertArrayHasKey( 'twentytwentyone', $prepared );
	}

	/**
	 * @covers \WordCamp\Themes\Network\filter_prepared_themes()
	 */
	public function test_restricted_theme_visible_on_its_own_network() {
		$this->set_current_network( GROUPS_NETWORK_ID );

		$prepared = filter_prepared_themes( array(
			'groups-site'     => array( 'id' => 'groups-site' ),
			'twentytwentyone' => array( 'id' => 'twentytwentyone' ),
		) );

		$this->assertArrayHasKey( 'groups-site', $prepared );
		$this->assertArrayHasKey( 'twentytwentyone', $prepared );
	}

	/**
	 * `after_switch_theme` doesn't fire synchronously from `switch_theme()`
	 * -- core defers it to `check_theme_switched()`, hooked on `init` at
	 * priority 99, so it runs on the *next* WP load after the switch (see
	 * `theme_switched` option). Call it directly to simulate that next load
	 * within the same test.
	 */
	public function test_activation_on_wrong_network_is_reverted() {
		switch_to_blog( self::$central_site_id );
		$this->set_current_network( WORDCAMP_NETWORK_ID );

		$previous_stylesheet = get_stylesheet();

		switch_theme( 'groups-site' );
		check_theme_switched();

		$this->assertNotSame( 'groups-site', get_stylesheet() );
		$this->assertSame( $previous_stylesheet, get_stylesheet() );

		switch_theme( $previous_stylesheet );
		restore_current_blog();
	}

	/**
	 * @covers \WordCamp\Themes\Network\revert_wrong_network_activation()
	 */
	public function test_activation_on_its_own_network_is_not_reverted() {
		// Groups_TestCase::setUp() already switches to the groups-network fixture site.
		$this->set_current_network( GROUPS_NETWORK_ID );

		$previous_stylesheet = get_stylesheet();

		switch_theme( 'groups-site' );
		check_theme_switched();

		$this->assertSame( 'groups-site', get_stylesheet() );

		switch_theme( $previous_stylesheet );
	}
}
