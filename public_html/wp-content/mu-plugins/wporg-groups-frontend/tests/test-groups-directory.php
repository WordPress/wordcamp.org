<?php

namespace WordCamp\Groups\Tests;

use WordCamp\Groups\Frontend\Groups_Directory;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';
require_once dirname( __DIR__, 3 ) . '/sunrise-groups.php';

/**
 * Coverage for the list of every group, at `/group/`.
 *
 * @group groups
 */
class Test_Groups_Directory extends Groups_TestCase {
	/**
	 * @var int[] Group site IDs, keyed by a short name.
	 */
	protected static $groups = array();

	/**
	 * @var array `$_SERVER` and `$_GET` before the test.
	 */
	protected $superglobals = array();

	/**
	 * Create the groups once; the list never includes the fixture's root site.
	 *
	 * @param \WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		parent::wpSetUpBeforeClass( $factory );

		$groups = array(
			'berlin'   => array( 'WordPress Berlin', 'physical', 'Berlin', 'DE' ),
			'istanbul' => array( 'WP Meetup İstanbul', 'physical', 'İstanbul', 'TR' ),
			'online'   => array( 'GatherPress Huddle', 'online', '', '' ),
			'club'     => array( "Tom & Jerry's Club", '', '', '' ),
			'ankara'   => array( 'ankara wordpress', 'physical', 'Ankara', 'TR' ),
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

			// Through the option, so the name is stored escaped as on a real site.
			update_blog_option( $site_id, 'blogname', $name );

			if ( $type ) {
				update_site_meta( $site_id, 'wporg_group_location_type', $type );
			}

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
		foreach ( self::$groups as $site_id ) {
			wp_delete_site( $site_id );
		}

		self::$groups = array();

		parent::wpTearDownAfterClass();
	}

	/**
	 * Start each test with no cached list.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->superglobals = array( $_SERVER, $_GET );

		wp_cache_delete( Groups_Directory\CACHE_KEY, Groups_Directory\CACHE_GROUP );
	}

	/**
	 * Put the request back.
	 */
	protected function tearDown(): void {
		list( $_SERVER, $_GET ) = $this->superglobals;

		wp_cache_delete( Groups_Directory\CACHE_KEY, Groups_Directory\CACHE_GROUP );

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
	 * The names on the list, in order.
	 */
	protected function names( array $groups ): array {
		return wp_list_pluck( $groups, 'name' );
	}

	/**
	 * Render the block.
	 */
	protected function render(): string {
		return do_blocks( '<!-- wp:wporg/groups-directory /-->' );
	}

	/**
	 * Only a plain request for the list gets past the root's redirect.
	 *
	 * @covers \WordCamp\Sunrise\Groups\is_directory_request
	 */
	public function test_sunrise_lets_only_the_list_through() {
		$allowed = array(
			array( 'GET', '/group/' ),
			array( 'HEAD', '/group/' ),
			array( 'GET', '/group/?q=berlin' ),
			array( 'GET', '/group/?q=' ),
			array( 'GET', '/group/?q=s%C3%A3o+paulo%2C+brazil' ),
		);

		$redirected = array(
			array( 'POST', '/group/' ),
			array( 'POST', '/group/?q=berlin' ),
			array( 'PUT', '/group/' ),
			array( 'DELETE', '/group/' ),
			array( '', '/group/' ),
			array( 'GET', '/group' ),
			array( 'GET', '/group/index.php' ),
			array( 'GET', '/group/some-page/' ),
			array( 'GET', '/group/wp-json/' ),
			array( 'GET', '/group/?rest_route=/wp/v2/users' ),
			array( 'GET', '/group/?q=berlin&rest_route=/wp/v2/users' ),
			array( 'GET', '/group/?feed=rss2' ),
			array( 'GET', '/group/?p=1' ),
			array( 'GET', '/group/?s=berlin' ),
			array( 'GET', '/group/?author=1' ),
			array( 'GET', '/group/?q[]=berlin' ),
			array( 'GET', '/group/?q[rest_route]=/wp/v2/users' ),
			array( 'GET', '/group/?Q=berlin' ),
			array( 'GET', '/group/?utm_source=newsletter' ),
			array( 'GET', '/elsewhere/' ),
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
	 * The query var the redirect allows is the one the search field submits.
	 *
	 * @covers \WordCamp\Sunrise\Groups\is_directory_request
	 */
	public function test_sunrise_allows_the_search_fields_query_var() {
		$this->set_request( '/group/?' . Groups_Directory\SEARCH_QUERY_VAR . '=berlin' );

		$this->assertTrue( \WordCamp\Sunrise\Groups\is_directory_request() );
	}

	/**
	 * Letting the list through leaves the rest of the root's allowlist alone.
	 *
	 * @covers \WordCamp\Sunrise\Groups\is_reachable_root_endpoint
	 */
	public function test_the_list_is_not_a_reachable_root_endpoint() {
		$this->set_request( '/group/?q=berlin' );

		$this->assertFalse( \WordCamp\Sunrise\Groups\is_reachable_root_endpoint() );
	}

	/**
	 * The list is a request for `/group/` on the root site, and nowhere else.
	 */
	public function test_the_list_is_only_on_the_root_site() {
		$this->set_request( '/group/' );
		$this->assertTrue( Groups_Directory\is_directory_request() );

		switch_to_blog( self::$groups['berlin'] );
		$this->assertFalse( Groups_Directory\is_directory_request() );
		restore_current_blog();

		$this->set_request( '/group/wp-login.php' );
		$this->assertFalse( Groups_Directory\is_directory_request() );
	}

	/**
	 * The list is drawn with the group sites' theme, and only the list.
	 */
	public function test_the_list_uses_the_group_theme() {
		$stylesheet = get_option( 'stylesheet' );
		$template   = get_option( 'template' );

		$this->assertNotSame( Groups_Directory\THEME, $stylesheet );

		$this->set_request( '/group/' );

		$this->assertSame( Groups_Directory\THEME, get_option( 'stylesheet' ) );
		$this->assertSame( Groups_Directory\PARENT_THEME, get_option( 'template' ) );

		$this->set_request( '/group/wp-login.php' );

		$this->assertSame( $stylesheet, get_option( 'stylesheet' ) );
		$this->assertSame( $template, get_option( 'template' ) );
	}

	/**
	 * The parent theme named here is the one the theme itself asks for.
	 */
	public function test_parent_theme_matches_the_theme() {
		$headers = get_file_data(
			SUT_WP_CONTENT_DIR . 'themes/' . Groups_Directory\THEME . '/style.css',
			array( 'template' => 'Template' )
		);

		$this->assertSame( Groups_Directory\PARENT_THEME, $headers['template'] );
	}

	/**
	 * A missing setting gets a default on the list; an existing one is kept.
	 */
	public function test_missing_settings_get_a_default_on_the_list() {
		delete_option( 'blog_charset' );
		delete_option( 'html_type' );
		delete_option( 'blog_public' );

		$this->assertFalse( get_option( 'blog_charset' ) );

		$this->set_request( '/group/' );

		$this->assertSame( 'UTF-8', get_option( 'blog_charset' ) );
		$this->assertSame( 'text/html', get_option( 'html_type' ) );
		$this->assertSame( '1', get_option( 'blog_public' ) );

		update_option( 'blog_public', '0' );

		$this->assertSame( '0', (string) get_option( 'blog_public' ) );
	}

	/**
	 * Active groups are listed by name; archived groups and the root are not.
	 */
	public function test_lists_active_groups_alphabetically() {
		$groups = Groups_Directory\get_groups();

		$this->assertSame(
			array( 'ankara wordpress', 'GatherPress Huddle', "Tom & Jerry's Club", 'WordPress Berlin', 'WP Meetup İstanbul' ),
			$this->names( $groups )
		);

		$ids = wp_list_pluck( $groups, 'id' );

		$this->assertNotContains( self::$groups['archived'], $ids );
		$this->assertNotContains( GROUPS_ROOT_BLOG_ID, $ids );
	}

	/**
	 * Each group carries its own address and location.
	 */
	public function test_group_has_its_address_and_location() {
		$groups = array_column( Groups_Directory\get_groups(), null, 'id' );

		$berlin = $groups[ self::$groups['berlin'] ];

		$this->assertSame( get_home_url( self::$groups['berlin'], '/' ), $berlin['url'] );
		$this->assertStringEndsWith( '/group/directory-berlin/', $berlin['url'] );
		$this->assertSame( 'Berlin, Germany', $berlin['location'] );

		$this->assertSame( 'Online', $groups[ self::$groups['online'] ]['location'] );
		$this->assertSame( '', $groups[ self::$groups['club'] ]['location'] );
	}

	/**
	 * A group with no name is left out.
	 */
	public function test_unnamed_group_is_left_out() {
		update_blog_option( self::$groups['berlin'], 'blogname', '' );

		$this->assertNotContains( self::$groups['berlin'], wp_list_pluck( Groups_Directory\get_groups(), 'id' ) );
	}

	/**
	 * The list is built once and reused until the cache expires.
	 */
	public function test_list_is_cached() {
		$before = Groups_Directory\get_groups();

		update_blog_option( self::$groups['berlin'], 'blogname', 'Renamed Group' );

		$this->assertSame( $before, Groups_Directory\get_groups() );

		wp_cache_delete( Groups_Directory\CACHE_KEY, Groups_Directory\CACHE_GROUP );

		$this->assertContains( 'Renamed Group', $this->names( Groups_Directory\get_groups() ) );
	}

	/**
	 * A search looks in the name, the city and the country.
	 *
	 * @dataProvider data_searches
	 */
	public function test_search( string $term, array $expected ) {
		$this->assertSame(
			$expected,
			$this->names( Groups_Directory\search_groups( Groups_Directory\get_groups(), $term ) )
		);
	}

	/**
	 * Searches and the names they should find.
	 */
	public function data_searches(): array {
		$everything = array( 'ankara wordpress', 'GatherPress Huddle', "Tom & Jerry's Club", 'WordPress Berlin', 'WP Meetup İstanbul' );

		return array(
			'nothing searched'            => array( '', $everything ),
			'only spaces'                 => array( '   ', $everything ),
			'only separators'             => array( ' , ', $everything ),
			'by name'                     => array( 'huddle', array( 'GatherPress Huddle' ) ),
			'by part of a name'           => array( 'press', array( 'ankara wordpress', 'GatherPress Huddle', 'WordPress Berlin' ) ),
			'by city'                     => array( 'Berlin', array( 'WordPress Berlin' ) ),
			'by country'                  => array( 'germany', array( 'WordPress Berlin' ) ),
			'by a country with two'       => array( 'Turkey', array( 'ankara wordpress', 'WP Meetup İstanbul' ) ),
			'online groups'               => array( 'online', array( 'GatherPress Huddle' ) ),
			'ignoring case'               => array( 'BERLIN', array( 'WordPress Berlin' ) ),
			'ignoring accents'            => array( 'istanbul', array( 'WP Meetup İstanbul' ) ),
			'with the accents'            => array( 'İstanbul', array( 'WP Meetup İstanbul' ) ),
			'city and country'            => array( 'berlin germany', array( 'WordPress Berlin' ) ),
			'country, then city'          => array( 'Germany, Berlin', array( 'WordPress Berlin' ) ),
			'name and country'            => array( 'wordpress turkey', array( 'ankara wordpress' ) ),
			'every word has to match'     => array( 'berlin turkey', array() ),
			'punctuation in a name'       => array( "tom & jerry's", array( "Tom & Jerry's Club" ) ),
			'an archived group'           => array( 'hamburg', array() ),
			'no match'                    => array( 'nowhereville', array() ),
			'a wildcard is just a letter' => array( '%', array() ),
		);
	}

	/**
	 * The search is read as plain text of a bounded length.
	 */
	public function test_search_term_is_read_as_plain_text() {
		$this->set_request( '/group/' );
		$this->assertSame( '', Groups_Directory\get_search_term() );

		$_GET['q'] = '  <b>Berlin</b>  ';
		$this->assertSame( 'Berlin', Groups_Directory\get_search_term() );

		$_GET['q'] = array( 'Berlin' );
		$this->assertSame( '', Groups_Directory\get_search_term() );

		$_GET['q'] = str_repeat( 'é', Groups_Directory\SEARCH_MAX_LENGTH + 50 );
		$this->assertSame( Groups_Directory\SEARCH_MAX_LENGTH, mb_strlen( Groups_Directory\get_search_term() ) );
	}

	/**
	 * The search field submits `q` to the list and shows what was searched.
	 */
	public function test_search_form_searches_the_list() {
		$form = Groups_Directory\render_search_form( 'São "Paulo"' );
		$tags = new \WP_HTML_Tag_Processor( $form );

		$this->assertTrue( $tags->next_tag( 'form' ) );
		$this->assertSame( Groups_Directory\get_directory_url(), $tags->get_attribute( 'action' ) );
		$this->assertSame( 'get', strtolower( (string) $tags->get_attribute( 'method' ) ) );

		$this->assertTrue( $tags->next_tag( 'input' ) );
		$this->assertSame( 'q', $tags->get_attribute( 'name' ) );
		$this->assertSame( 'São "Paulo"', $tags->get_attribute( 'value' ) );

		$this->assertStringNotContainsString( 'name="s"', $form );
	}

	/**
	 * The block is registered without depending on GatherPress.
	 */
	public function test_block_is_registered() {
		$this->assertTrue( \WP_Block_Type_Registry::get_instance()->is_registered( 'wporg/groups-directory' ) );
	}

	/**
	 * The page says the list is incomplete, and where to look instead.
	 */
	public function test_block_says_the_list_is_incomplete() {
		$html = $this->render();

		$this->assertStringContainsString( 'wporg-groups-directory__notice', $html );
		$this->assertStringContainsString( 'Meetup.com', $html );
		$this->assertStringContainsString( 'href="' . esc_url( Groups_Directory\ANNOUNCEMENT_URL ) . '"', $html );
		$this->assertStringContainsString( 'href="' . esc_url( Groups_Directory\get_events_site_url() ) . '"', $html );
	}

	/**
	 * Every group is a row with its name, linked, and its location.
	 */
	public function test_block_lists_every_group() {
		$this->set_request( '/group/' );

		$html = $this->render();

		$this->assertStringContainsString( '5 groups', $html );
		$this->assertSame( 5, substr_count( $html, 'class="wporg-groups-directory__group"' ) );

		$this->assertStringContainsString(
			'<a class="wporg-groups-directory__name" href="' . esc_url( get_home_url( self::$groups['berlin'], '/' ) ) . '">WordPress Berlin</a>',
			$html
		);
		$this->assertStringContainsString( 'Berlin, Germany', $html );

		// Escaped once, although the name is stored escaped.
		$this->assertStringContainsString( '>Tom &amp; Jerry&#039;s Club</a>', $html );

		$this->assertStringNotContainsString( 'Archived Hamburg', $html );
		$this->assertStringNotContainsString( 'wporg-groups-directory__reset', $html );
		$this->assertStringNotContainsString( 'wporg-groups-directory__empty', $html );
	}

	/**
	 * A group with no location has no empty location beside it.
	 */
	public function test_block_omits_a_missing_location() {
		$this->set_request( '/group/?q=jerry' );

		$html = $this->render();

		$this->assertSame( 1, substr_count( $html, 'class="wporg-groups-directory__group"' ) );
		$this->assertStringNotContainsString( 'wporg-groups-directory__location', $html );
	}

	/**
	 * A search narrows the list, says how many matched, and offers a way back.
	 */
	public function test_block_shows_search_results() {
		$this->set_request( '/group/?q=turkey' );

		$html = $this->render();

		$this->assertStringContainsString( '2 groups match “turkey”', $html );
		$this->assertSame( 2, substr_count( $html, 'class="wporg-groups-directory__group"' ) );
		$this->assertStringNotContainsString( 'WordPress Berlin', $html );
		$this->assertStringContainsString( 'value="turkey"', $html );
		$this->assertStringContainsString(
			'<a class="wporg-groups-directory__reset" href="' . esc_url( Groups_Directory\get_directory_url() ) . '">',
			$html
		);
	}

	/**
	 * One match is counted in the singular.
	 */
	public function test_block_counts_one_match() {
		$this->set_request( '/group/?q=berlin' );

		$this->assertStringContainsString( '1 group matches “berlin”', $this->render() );
	}

	/**
	 * A search that finds nothing says so, with the way back to the list.
	 */
	public function test_block_shows_an_empty_search() {
		$this->set_request( '/group/?q=nowhereville' );

		$html = $this->render();

		$this->assertStringContainsString( 'No groups match “nowhereville”.', $html );
		$this->assertStringContainsString( '<a href="' . esc_url( Groups_Directory\get_directory_url() ) . '">', $html );
		$this->assertStringNotContainsString( 'wporg-groups-directory__list', $html );
		$this->assertStringNotContainsString( 'wporg-groups-directory__count', $html );
	}

	/**
	 * What was searched for is printed as text, wherever it appears.
	 */
	public function test_block_escapes_the_search() {
		$this->set_request( '/group/?q=' . rawurlencode( '"><script>alert(1)</script><img src=x onerror=alert(2)>' ) );

		$html = $this->render();

		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( 'value=""><', $html );
		$this->assertStringContainsString( 'value="&quot;&gt;"', $html );
	}

	/**
	 * With no groups at all, the page says so.
	 */
	public function test_block_shows_an_empty_network() {
		wp_cache_set( Groups_Directory\CACHE_KEY, array(), Groups_Directory\CACHE_GROUP );

		$this->set_request( '/group/' );

		$html = $this->render();

		$this->assertStringContainsString( 'No groups are listed yet.', $html );
		$this->assertStringNotContainsString( 'wporg-groups-directory__list', $html );
	}

	/**
	 * The list drops the head links to the root's feeds and APIs; a group's page keeps them.
	 */
	public function test_list_does_not_advertise_the_roots_feeds() {
		$hooks = array(
			array( 'wp_head', 'feed_links', 2 ),
			array( 'wp_head', 'rest_output_link_wp_head', 10 ),
			array( 'template_redirect', 'rest_output_link_header', 11 ),
		);

		foreach ( $hooks as list( $hook, $callback, $priority ) ) {
			add_action( $hook, $callback, $priority );
		}

		$this->set_request( '/group/directory-berlin/' );
		Groups_Directory\remove_discovery_links();

		foreach ( $hooks as list( $hook, $callback, $priority ) ) {
			$this->assertSame( $priority, has_action( $hook, $callback ), "$hook: $callback" );
		}

		$this->set_request( '/group/' );
		Groups_Directory\remove_discovery_links();

		foreach ( $hooks as list( $hook, $callback ) ) {
			$this->assertFalse( has_action( $hook, $callback ), "$hook: $callback" );
		}
	}
}
