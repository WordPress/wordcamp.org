<?php

namespace WordCamp\Groups\Tests;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/../../wporg-groups-frontend/tests/class-groups-testcase.php';
require_once dirname( __DIR__, 3 ) . '/sunrise-groups.php';

/**
 * @group groups
 */
class Test_Groups_Site_Directory extends Groups_TestCase {
	/**
	 * @var array `$_SERVER` before the test.
	 */
	protected $server = array();

	/**
	 * Load the theme's functions; `groups-site` isn't the active theme here.
	 *
	 * @param \WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		parent::wpSetUpBeforeClass( $factory );

		require_once SUT_WP_CONTENT_DIR . 'themes/groups-site/functions.php';
	}

	/**
	 * Remember the request.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->server = $_SERVER;
	}

	/**
	 * Put the request back.
	 */
	protected function tearDown(): void {
		$_SERVER = $this->server;

		parent::tearDown();
	}

	/**
	 * The theme's template choice and header menu for a request.
	 */
	protected function theme_for( string $uri ): array {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = $uri;

		$menus = \WordCamp\Groups\Site\add_local_navigation_menus( array() );

		return array(
			'templates' => \WordCamp\Groups\Site\directory_template_hierarchy( array( 'index.php' ) ),
			'menu'      => wp_list_pluck( $menus['local-navigation'], 'label' ),
		);
	}

	/**
	 * The list gets its own template and a header without a group's links.
	 */
	public function test_list_has_its_own_template_and_header() {
		$theme = $this->theme_for( '/group/' );

		$this->assertSame( array( 'groups-directory', 'index.php' ), $theme['templates'] );
		$this->assertNotContains( 'All Events', $theme['menu'] );
		$this->assertNotContains( 'Log in', $theme['menu'] );

		$breadcrumbs = \WordCamp\Groups\Site\filter_site_breadcrumbs( array() );

		$this->assertCount( 1, $breadcrumbs );
		$this->assertFalse( $breadcrumbs[0]['url'] );
	}

	/**
	 * A group's own pages keep their template and header.
	 */
	public function test_group_pages_are_unchanged() {
		$theme = $this->theme_for( '/group/sunshine-coast-qld/' );

		$this->assertSame( array( 'index.php' ), $theme['templates'] );
		$this->assertSame( array( 'All Events', 'Log in' ), $theme['menu'] );
	}
}
