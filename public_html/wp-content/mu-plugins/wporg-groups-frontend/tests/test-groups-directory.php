<?php

namespace WordCamp\Groups\Tests;

use WordCamp\Groups\Frontend\Group_Location;
use WordCamp\Groups\Frontend\Groups_Directory;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';
require_once dirname( __DIR__, 3 ) . '/sunrise-groups.php';

/**
 * @group groups
 */
class Test_Groups_Directory extends Groups_TestCase {
	/**
	 * @var int[] Group site IDs, keyed by a short name.
	 */
	protected static $groups = array();

	/**
	 * @var array `$_SERVER` before the test.
	 */
	protected $server = array();

	/**
	 * Create the groups once; a site is slow to build.
	 *
	 * @param \WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		parent::wpSetUpBeforeClass( $factory );

		$groups = array(
			'berlin'   => array( 'WordPress Berlin', 'physical', 'Berlin', 'DE' ),
			'istanbul' => array( 'WP Meetup İstanbul', 'physical', 'İstanbul', 'TR' ),
			'online'   => array( 'GatherPress Huddle', 'online', '', '' ),
			'archived' => array( 'Archived Hamburg', 'physical', 'Hamburg', 'DE' ),
		);

		foreach ( $groups as $key => list( $name, $type, $city, $country ) ) {
			$site_id = $factory->blog->create(
				array(
					'domain'     => 'events.wordpress.test',
					'path'       => "/group/directory-{$key}/",
					'network_id' => GROUPS_NETWORK_ID,
				)
			);

			update_blog_option( $site_id, 'blogname', $name );
			update_site_meta( $site_id, 'wporg_group_location_type', $type );

			if ( $city ) {
				update_site_meta( $site_id, 'wporg_group_location_city', $city );
				update_site_meta( $site_id, 'wporg_group_location_country', $country );
			}

			self::$groups[ $key ] = $site_id;
		}

		wp_update_site( self::$groups['archived'], array( 'archived' => 1 ) );
	}

	/**
	 * Remove the groups.
	 */
	public static function wpTearDownAfterClass() {
		array_map( 'wp_delete_site', self::$groups );

		self::$groups = array();

		parent::wpTearDownAfterClass();
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
	 * Make the current request look like one for the given address.
	 */
	protected function set_request( string $uri, string $method = 'GET' ): void {
		$_SERVER['REQUEST_METHOD'] = $method;
		$_SERVER['REQUEST_URI']    = $uri;
		$_GET                      = array();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Mirrors the code under test.
		parse_str( (string) parse_url( $uri, PHP_URL_QUERY ), $_GET );
	}

	/**
	 * Render the list page for a request.
	 */
	protected function render( string $uri ): string {
		$this->set_request( $uri );

		return do_blocks( '<!-- wp:wporg/groups-directory /-->' );
	}

	/**
	 * The groups a rendered page links to, in page order.
	 */
	protected function listed( string $html ): array {
		$positions = array();

		foreach ( self::$groups as $key => $site_id ) {
			$position = strpos( $html, 'href="' . esc_url( get_home_url( $site_id, '/' ) ) . '"' );

			if ( false !== $position ) {
				$positions[ $key ] = $position;
			}
		}

		asort( $positions );

		return array_keys( $positions );
	}

	/**
	 * Only a plain request for the list gets past the root site's redirect.
	 *
	 * @covers \WordCamp\Sunrise\Groups\is_directory_request
	 */
	public function test_only_the_list_gets_past_the_redirect() {
		$allowed = array(
			array( 'GET', '/group/' ),
			array( 'HEAD', '/group/' ),
			array( 'GET', '/group/?' . Groups_Directory\SEARCH_QUERY_VAR . '=berlin' ),
			array( 'GET', '/group/?' . Groups_Directory\PAGE_QUERY_VAR . '=2' ),
			array( 'GET', '/group/?q=berlin&paged=2' ),
			array( 'GET', '/group/?paged=two' ),
		);

		$redirected = array(
			array( 'POST', '/group/' ),
			array( 'GET', '/group/index.php' ),
			array( 'GET', '/group/some-page/' ),
			array( 'GET', '/group/?rest_route=/wp/v2/users' ),
			array( 'GET', '/group/?q=berlin&rest_route=/wp/v2/users' ),
			array( 'GET', '/group/?feed=rss2' ),
			array( 'GET', '/group/?p=1' ),
			array( 'GET', '/group/?q[]=berlin' ),
			array( 'GET', '/group/?paged[]=2' ),
		);

		foreach ( $allowed as list( $method, $uri ) ) {
			$this->set_request( $uri, $method );
			$this->assertTrue( \WordCamp\Sunrise\Groups\is_directory_request(), "$method $uri" );
		}

		foreach ( $redirected as list( $method, $uri ) ) {
			$this->set_request( $uri, $method );
			$this->assertFalse( \WordCamp\Sunrise\Groups\is_directory_request(), "$method $uri" );
		}
	}

	/**
	 * The root site gets the group theme and a charset for the list, and for nothing else.
	 */
	public function test_root_site_is_themed_only_for_the_list() {
		delete_option( 'blog_charset' );

		$this->set_request( '/group/wp-login.php' );

		$this->assertNotSame( Groups_Directory\THEME, get_option( 'stylesheet' ) );
		$this->assertFalse( get_option( 'blog_charset' ) );

		$this->set_request( '/group/' );

		$this->assertSame( Groups_Directory\THEME, get_option( 'stylesheet' ) );
		$this->assertSame( Groups_Directory\PARENT_THEME, get_option( 'template' ) );
		$this->assertSame( 'UTF-8', get_option( 'blog_charset' ) );
	}

	/**
	 * The list keeps its address and loads when the root site's home is another site, as on production.
	 */
	public function test_list_loads_with_the_root_home_elsewhere() {
		$url = Groups_Directory\get_directory_url();

		update_option( 'home', 'https://events.wordpress.test' );

		$this->assertSame( $url, Groups_Directory\get_directory_url() );

		// There, WordPress reads `/group/` as a page named "group".
		$this->set_request( '/group/' );

		$this->assertSame( array(), apply_filters( 'request', array( 'pagename' => 'group' ) ) );

		$this->set_request( '/group/wp-login.php' );

		$this->assertSame( array( 'pagename' => 'group' ), apply_filters( 'request', array( 'pagename' => 'group' ) ) );
	}

	/**
	 * The list links to every active group, by name, with its location.
	 */
	public function test_list_shows_active_groups_by_name() {
		$html = $this->render( '/group/' );

		$this->assertSame( array( 'online', 'berlin', 'istanbul' ), $this->listed( $html ) );
		$this->assertStringContainsString( 'Berlin, Germany', $html );
	}

	/**
	 * A search looks in the name, the city and the country.
	 *
	 * @dataProvider data_searches
	 */
	public function test_search_narrows_the_list( string $term, array $expected ) {
		$this->assertSame( $expected, $this->listed( $this->render( '/group/?q=' . rawurlencode( $term ) ) ) );
	}

	/**
	 * Searches and the groups they should list.
	 */
	public function data_searches(): array {
		return array(
			'name'               => array( 'huddle', array( 'online' ) ),
			'city'               => array( 'berlin', array( 'berlin' ) ),
			'country'            => array( 'Germany', array( 'berlin' ) ),
			'without the accent' => array( 'istanbul', array( 'istanbul' ) ),
			'several words'      => array( 'Germany, Berlin', array( 'berlin' ) ),
			'archived group'     => array( 'hamburg', array() ),
			'no match'           => array( 'nowhereville', array() ),
		);
	}

	/**
	 * What was searched for comes back in the search field as text, not markup.
	 */
	public function test_search_term_is_escaped() {
		$html  = $this->render( '/group/?q=' . rawurlencode( '"><script>alert(1)</script>' ) );
		$tags  = new \WP_HTML_Tag_Processor( $html );
		$value = null;

		while ( $tags->next_tag( 'input' ) ) {
			if ( Groups_Directory\SEARCH_QUERY_VAR === $tags->get_attribute( 'name' ) ) {
				$value = $tags->get_attribute( 'value' );
			}
		}

		$this->assertSame( '">', $value );
		$this->assertStringNotContainsString( '<script>', $html );
	}

	/**
	 * The map marks every group that matches the search and has coordinates, linked by name.
	 */
	public function test_map_marks_matching_groups_with_coordinates() {
		update_site_meta(
			self::$groups['berlin'],
			Group_Location\COORDINATES_META_KEY,
			array(
				'latitude'  => 52.52,
				'longitude' => 13.405,
			)
		);

		// Stands in for the shared block, to see what it's given.
		$registry = \WP_Block_Type_Registry::get_instance();
		$real     = $registry->is_registered( 'wporg/google-map' ) ? $registry->unregister( 'wporg/google-map' ) : null;
		$markers  = null;

		register_block_type(
			'wporg/google-map',
			array(
				'render_callback' => static function ( array $attributes ) use ( &$markers ): string {
					$markers = $attributes['markers'];
					return '';
				},
			)
		);

		$this->render( '/group/' );
		$all = $markers;

		$markers = null;
		$this->render( '/group/?q=istanbul' );
		$none = $markers;

		$registry->unregister( 'wporg/google-map' );

		if ( $real ) {
			$registry->register( $real );
		}

		$this->assertSame( array( 'WordPress Berlin' ), wp_list_pluck( $all, 'title' ) );
		$this->assertSame( get_home_url( self::$groups['berlin'], '/' ), $all[0]['url'] );
		$this->assertSame( 'Berlin, Germany', $all[0]['meetup'] );
		$this->assertSame( 52.52, $all[0]['latitude'] );
		$this->assertNull( $none, 'No map without a marker.' );
	}

	/**
	 * A long list is split into pages that keep the search, and a page past the end shows the last one.
	 */
	public function test_long_list_is_paged() {
		$groups = array();

		for ( $i = 1; $i <= Groups_Directory\GROUPS_PER_PAGE + 1; $i++ ) {
			$groups[] = array(
				'id'          => $i,
				'name'        => sprintf( 'Group %02d', $i ),
				'url'         => "https://example.test/{$i}/",
				'location'    => 'Berlin, Germany',
				'coordinates' => null,
			);
		}

		wp_cache_set( Groups_Directory\CACHE_KEY, $groups, Groups_Directory\CACHE_GROUP );

		$last  = sprintf( 'Group %02d', Groups_Directory\GROUPS_PER_PAGE );
		$extra = sprintf( 'Group %02d', Groups_Directory\GROUPS_PER_PAGE + 1 );
		$url   = Groups_Directory\get_directory_url();

		$first = $this->render( '/group/' );

		$this->assertStringContainsString( $last, $first );
		$this->assertStringNotContainsString( $extra, $first );
		$this->assertStringContainsString( esc_url( $url . '?paged=2' ), $first );

		$second = $this->render( '/group/?q=group&paged=2' );

		$this->assertStringContainsString( $extra, $second );
		$this->assertStringNotContainsString( 'Group 01', $second );
		$this->assertStringContainsString( 'href="' . esc_url( $url . '?q=group' ) . '"', $second, 'Page 1 keeps the search.' );

		$this->assertStringContainsString( $extra, $this->render( '/group/?paged=99' ) );
	}
}
