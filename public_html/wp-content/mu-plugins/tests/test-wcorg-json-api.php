<?php

namespace WordCamp\JSON_API_V1\Tests;
use WP_UnitTestCase, WP_UnitTest_Factory;

defined( 'WPINC' ) || die();

/**
 * @group wordcamp-mu-plugins
 */
class Test_WordCamp_JSON_API_V1 extends WP_UnitTestCase {
	static $network_id, $year_dot_site_id, $slash_year_site_id;

	/**
	 * Create sites we'll need for the tests.
	 *
	 * @param WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$network_id = $factory->network->create( array(
			'domain' => 'wordcamp.test',
			'path'   => '/',
		) );

		self::$year_dot_site_id = $factory->blog->create( array(
			'domain'     => '2020.seattle.wordcamp.test',
			'path'       => '/',
			'network_id' => self::$network_id,
		) );

		self::$slash_year_site_id = $factory->blog->create( array(
			'domain'     => 'vancouver.wordcamp.test',
			'path'       => '/2020/',
			'network_id' => self::$network_id,
		) );
	}

	/**
	 * Revert the persistent changes from `wpSetUpBeforeClass()` that won't be automatically cleaned up.
	 */
	public static function wpTearDownAfterClass() {
		global $wpdb;

		wp_delete_site( self::$year_dot_site_id );
		wp_delete_site( self::$slash_year_site_id );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE site_id = %d", self::$network_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->site}     WHERE id      = %d", self::$network_id ) );
	}

	/**
	 * @covers ::wcorg_json_v2_compat
	 *
	 * @dataProvider data_wcorg_json_v2_compat
	 */
	public function test_wcorg_json_v2_compat( $site_id_variable, $url, $version, $route ) {
		global $wp;

		switch_to_blog( self::${$site_id_variable} );

		$this->go_to( $url );

		// Manually setup `json_var` query var, because `go_to()` doesn't for some reason.
		if ( ! empty( $route ) ) {
			$wp->query_vars['json_route'] = $route;
		}

		/**
		 * The function is already triggered by `go_to()` because it's hooked to `parse_request`, but the query
		 * vars weren't correct because of the note above. So it has to be called again.
		 */
		wcorg_json_v2_compat( $wp );

		switch( $version ) {
			case 'not-api';
				$this->assertFalse( isset( $wp->query_vars['json_route'] ) );
				$this->assertFalse( isset( $wp->query_vars['rest_route'] ) );
				break;

			case 'v2':
				$this->assertEquals( $route, $wp->query_vars['rest_route'] );
				break;

			case 'v1':
				$this->assertEquals( $route, $wp->query_vars['json_route'] );
				break;
		}

		restore_current_blog(); // Restore after the asserts, because it will impact the global `$wp` variable.
	}

	public function data_wcorg_json_v2_compat() {
		$slashed_cases = array();

		// The first param can't reference self::$slash_year_site_id directly, since data providers are executed before `setUp()`.
		$unslashed_cases = array(
			'slash-year non-api requests are not routed to api' => array(
				'slash_year_site_id',
				'https://vancouver.wordcamp.test/2020/hello',
				'not-api',
				null,
			),

			'dot year non-api requests are not routed to api' => array(
				'year_dot_site_id',
				'https://2020.seattle.wordcamp.test',
				'not-api',
				null,
			),

			'slash-year api root goes to v2' => array(
				'slash_year_site_id',
				'https://vancouver.wordcamp.test/2020/wp-json',
				'v2',
				'/',
			),

			'dot-year api root goes to v2' => array(
				'year_dot_site_id',
				'https://2020.seattle.wordcamp.test/wp-json',
				'v2',
				'/',
			),

			'slash-year v2 posts endpoint goes to v2' => array(
				'slash_year_site_id',
				'https://vancouver.wordcamp.test/2020/wp-json/wp/v2/posts/1',
				'v2',
				'/wp/v2/posts/17',
			),

			'dot-year v2 posts endpoint goes to v2' => array(
				'year_dot_site_id',
				'https://2020.seattle.wordcamp.test/wp-json/wp/v2/posts/1',
				'v2',
				'/wp/v2/posts/17',
			),

			'slash-year v1 posts endpoint goes to v1' => array(
				'slash_year_site_id',
				'https://vancouver.wordcamp.test/2020/wp-json/posts/1',
				'v1',
				'/posts/1',
			),

			'dot-year v1 posts endpoint goes to v1' => array(
				'year_dot_site_id',
				'https://2020.seattle.wordcamp.test/wp-json/posts/1',
				'v1',
				'/posts/1',
			),
		);

		// Make sure each case is also tested with a trailing slash, because the results can be different.
		foreach( $unslashed_cases as $case ) {
			$case[1] = trailingslashit( $case[1] );

			// should this affect json_route or rest_route too?

			$slashed_cases[] = $case;
		}

		return array_merge( $unslashed_cases, $slashed_cases );
	}
}
