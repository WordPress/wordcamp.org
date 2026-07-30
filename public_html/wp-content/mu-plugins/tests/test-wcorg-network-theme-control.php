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

	/**
	 * Stash the current network global, so `set_current_network()` can
	 * restore it in `tearDown()`.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->original_current_site = $GLOBALS['current_site'];
	}

	/**
	 * Restore the network global that `set_current_network()` overwrote,
	 * and clear the request-scoped "did this request just switch a theme"
	 * flag `mark_theme_switched_this_request()` sets, so neither leaks into
	 * other tests -- PHPUnit runs the whole suite in one process, unlike
	 * the real one-flag-per-HTTP-request lifecycle this is designed for.
	 */
	protected function tearDown(): void {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring original state.
		$GLOBALS['current_site'] = $this->original_current_site;
		unset( $GLOBALS['wcorg_network_theme_control_switched_this_request'] );

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
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Necessary for testing multisite global state.
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

		// Already back on $previous_stylesheet -- the backstop reverted it above.
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

		// Restore the previous theme and let the deferred switch clear itself,
		// so the `theme_switched` option doesn't leak into other tests.
		switch_theme( $previous_stylesheet );
		check_theme_switched();
	}

	/**
	 * The actual gap `prevent_wrong_network_theme_boot()` closes: on the
	 * *next* request after a wrong-network switch -- after `switch_theme()`
	 * ran on a previous request, but before *this* request's
	 * `check_theme_switched()`/`after_switch_theme` gets a chance to run --
	 * `get_stylesheet()` and `get_template()` must already report the
	 * previous theme, not the restricted one. Otherwise the restricted
	 * theme's `functions.php` would load once regardless of the deferred
	 * backstop.
	 *
	 * @covers \WordCamp\Themes\Network\prevent_wrong_network_theme_boot()
	 */
	public function test_stylesheet_reports_previous_theme_before_backstop_runs() {
		switch_to_blog( self::$central_site_id );
		$this->set_current_network( WORDCAMP_NETWORK_ID );

		$previous_stylesheet = get_stylesheet();
		$previous_template   = get_template();

		switch_theme( 'groups-site' );

		// A real "next request" wouldn't have this set at all -- unlike
		// PHPUnit, which shares one process across the switch above and the
		// assertions below, a fresh request never ran the switch itself.
		unset( $GLOBALS['wcorg_network_theme_control_switched_this_request'] );

		// No `check_theme_switched()` call here -- this is the point.
		$this->assertSame( $previous_stylesheet, get_stylesheet() );
		$this->assertSame( $previous_template, get_template() );

		// Now let the deferred backstop persist the correction, so
		// `theme_switched` doesn't leak into other tests.
		check_theme_switched();
		restore_current_blog();
	}

	/**
	 * On the theme's own network, `prevent_wrong_network_theme_boot()` must
	 * not interfere -- `get_stylesheet()` should report the just-switched-to
	 * restricted theme immediately, same as core's default behavior.
	 *
	 * @covers \WordCamp\Themes\Network\prevent_wrong_network_theme_boot()
	 */
	public function test_stylesheet_not_overridden_on_correct_network() {
		// Groups_TestCase::setUp() already switches to the groups-network fixture site.
		$this->set_current_network( GROUPS_NETWORK_ID );

		$previous_stylesheet = get_stylesheet();

		switch_theme( 'groups-site' );

		$this->assertSame( 'groups-site', get_stylesheet() );

		switch_theme( $previous_stylesheet );
		check_theme_switched();
	}

	/**
	 * Regression test: the request that itself calls `switch_theme()` to a
	 * wrong-network restricted theme must still see `get_stylesheet()`
	 * report that theme immediately afterwards -- e.g. `wp theme activate`
	 * (and wp-admin) checks `get_stylesheet()` right after switching to
	 * confirm success, and would otherwise wrongly report failure even
	 * though the switch did take effect (and will be reverted, correctly,
	 * starting from the *next* request).
	 *
	 * @covers \WordCamp\Themes\Network\mark_theme_switched_this_request()
	 * @covers \WordCamp\Themes\Network\prevent_wrong_network_theme_boot()
	 */
	public function test_stylesheet_not_overridden_for_the_request_performing_the_switch() {
		switch_to_blog( self::$central_site_id );
		$this->set_current_network( WORDCAMP_NETWORK_ID );

		switch_theme( 'groups-site' );

		$this->assertSame( 'groups-site', get_stylesheet() );

		check_theme_switched();
		restore_current_blog();
	}
}
