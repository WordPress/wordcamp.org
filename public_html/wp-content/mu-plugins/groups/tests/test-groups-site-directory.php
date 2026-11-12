<?php

namespace WordCamp\Groups\Tests;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/../../wporg-groups-frontend/tests/class-groups-testcase.php';
require_once dirname( __DIR__, 3 ) . '/sunrise-groups.php';

/**
 * Coverage for how the `groups-site` theme draws the list of every group.
 *
 * @group groups
 */
class Test_Groups_Site_Directory extends Groups_TestCase {

	const THEME_DIR = SUT_WP_CONTENT_DIR . 'themes/groups-site/';

	/**
	 * @var array `$_SERVER` and `$_GET` before the test.
	 */
	protected $superglobals = array();

	/**
	 * Load the theme's functions; `groups-site` isn't the active theme here.
	 *
	 * @param \WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		parent::wpSetUpBeforeClass( $factory );

		require_once self::THEME_DIR . 'functions.php';
	}

	/**
	 * Remember the request as it was.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->superglobals = array( $_SERVER, $_GET );
	}

	/**
	 * Put the request back.
	 */
	protected function tearDown(): void {
		list( $_SERVER, $_GET ) = $this->superglobals;

		parent::tearDown();
	}

	/**
	 * Make the current request look like one for the given address.
	 */
	protected function set_request( string $uri ): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = $uri;
		$_GET                      = array();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Mirrors the code under test.
		parse_str( (string) parse_url( $uri, PHP_URL_QUERY ), $_GET );
	}

	/**
	 * The theme knows the list from a group's page.
	 */
	public function test_theme_recognizes_the_list() {
		$this->set_request( '/group/' );
		$this->assertTrue( \WordCamp\Groups\Site\is_groups_directory() );

		$this->set_request( '/group/?q=berlin' );
		$this->assertTrue( \WordCamp\Groups\Site\is_groups_directory() );

		$this->set_request( '/group/sunshine-coast-qld/' );
		$this->assertFalse( \WordCamp\Groups\Site\is_groups_directory() );
	}

	/**
	 * The list gets its own template, and a group's pages keep theirs.
	 */
	public function test_list_has_its_own_template() {
		$templates = array( 'front-page.php', 'home.php', 'index.php' );

		$this->set_request( '/group/sunshine-coast-qld/' );
		$this->assertSame( $templates, \WordCamp\Groups\Site\directory_template_hierarchy( $templates ) );

		$this->set_request( '/group/' );
		$this->assertSame(
			array_merge( array( 'groups-directory' ), $templates ),
			\WordCamp\Groups\Site\directory_template_hierarchy( $templates )
		);
	}

	/**
	 * That template is the theme's header and footer around the list block.
	 */
	public function test_template_reuses_the_header_and_footer() {
		$blocks = parse_blocks( file_get_contents( self::THEME_DIR . 'templates/groups-directory.html' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a template from disk.
		$names  = array();
		$parts  = array();

		$walk = static function ( array $blocks ) use ( &$walk, &$names, &$parts ) {
			foreach ( $blocks as $block ) {
				if ( 'core/template-part' === $block['blockName'] ) {
					$parts[] = $block['attrs']['slug'];
				}

				$names[] = $block['blockName'];

				$walk( $block['innerBlocks'] );
			}
		};
		$walk( $blocks );

		$this->assertSame( array( 'header', 'footer' ), $parts );
		$this->assertContains( 'wporg/groups-directory', $names );

		$html = file_get_contents( self::THEME_DIR . 'templates/groups-directory.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a template from disk.

		$this->assertSame( 1, substr_count( $html, '<h1' ) );
		$this->assertStringContainsString( 'groups-site-page-header', $html );
	}

	/**
	 * The list's navigation only leads to the events site.
	 */
	public function test_list_navigation_leads_to_events() {
		$this->set_request( '/group/' );

		$menus = \WordCamp\Groups\Site\add_local_navigation_menus(
			array(
				'global-navigation' => array(
					array(
						'label' => 'Kept',
						'url'   => 'https://example.org/',
					),
				),
			)
		);

		$this->assertSame( array( 'Upcoming events' ), wp_list_pluck( $menus['local-navigation'], 'label' ) );
		$this->assertStringEndsWith( '/upcoming-events/', $menus['local-navigation'][0]['url'] );
		$this->assertStringNotContainsString( '/group/', $menus['local-navigation'][0]['url'] );
		$this->assertArrayHasKey( 'global-navigation', $menus );
	}

	/**
	 * A group's own navigation is unchanged.
	 */
	public function test_group_navigation_is_unchanged() {
		$this->set_request( '/group/sunshine-coast-qld/' );

		$menus = \WordCamp\Groups\Site\add_local_navigation_menus( array() );

		$this->assertSame( array( 'All Events', 'Log in' ), wp_list_pluck( $menus['local-navigation'], 'label' ) );
	}

	/**
	 * The list is named in the breadcrumb, not after the placeholder site.
	 */
	public function test_list_breadcrumb_names_the_list() {
		$this->set_request( '/group/' );

		$breadcrumbs = \WordCamp\Groups\Site\filter_site_breadcrumbs(
			array(
				array(
					'url'   => home_url( '/' ),
					'title' => get_bloginfo( 'name' ),
				),
				array(
					'url'   => false,
					'title' => 'Hello world!',
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'url'   => false,
					'title' => 'WordPress Groups',
				),
			),
			$breadcrumbs
		);
	}

	/**
	 * So is its document title.
	 */
	public function test_list_document_title_names_the_list() {
		$parts = array(
			'title' => 'Hello world!',
			'site'  => 'Placeholder',
		);

		$this->set_request( '/group/sunshine-coast-qld/' );
		$this->assertSame( $parts, \WordCamp\Groups\Site\directory_document_title( $parts ) );

		$this->set_request( '/group/' );
		$this->assertSame( array( 'title' => 'WordPress Groups' ), \WordCamp\Groups\Site\directory_document_title( $parts ) );
	}

	/**
	 * Every group's footer links to the list.
	 */
	public function test_footer_links_to_the_list() {
		$footer = file_get_contents( self::THEME_DIR . 'parts/footer.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a template part from disk.

		$this->assertStringContainsString( '<a href="/group/">All groups', $footer );
	}
}
