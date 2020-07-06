<?php

namespace WordCamp\JSON_API_V1\Tests;
use WP_UnitTest_Factory;
use WordCamp\Tests\Database_TestCase;

defined( 'WPINC' ) || die();

/**
 * @group mu-plugins
 * @group rest-api
 */
class Test_WordCamp_JSON_API_V1 extends Database_TestCase {
	/**
	 * Create sites we'll need for the tests.
	 *
	 * @param WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		parent::wpSetUpBeforeClass( $factory );
	}

	/**
	 * Revert the persistent changes from `wpSetUpBeforeClass()` that won't be automatically cleaned up.
	 */
	public static function wpTearDownAfterClass() {
		parent::wpTearDownAfterClass();
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
				$this->assertSame( $route, $wp->query_vars['rest_route'] );
				break;

			case 'v1':
				$this->assertSame( $route, $wp->query_vars['json_route'] );
				break;
		}

		restore_current_blog(); // Restore after the asserts, because it will impact the global `$wp` variable.
	}

	public function data_wcorg_json_v2_compat() {
		$slashed_cases = array();

		// The first param can't reference self::$slash_year_site_id directly, since data providers are executed before `setUp()`.
		$unslashed_cases = array(
			'slash-year non-api requests are not routed to api' => array(
				'slash_year_2020_site_id',
				'https://vancouver.wordcamp.test/2020/hello',
				'not-api',
				null,
			),

			'dot year non-api requests are not routed to api' => array(
				'year_dot_2019_site_id',
				'https://2020.seattle.wordcamp.test',
				'not-api',
				null,
			),

			'slash-year api root goes to v2' => array(
				'slash_year_2020_site_id',
				'https://vancouver.wordcamp.test/2020/wp-json',
				'v2',
				'/',
			),

			'dot-year api root goes to v2' => array(
				'year_dot_2019_site_id',
				'https://2020.seattle.wordcamp.test/wp-json',
				'v2',
				'/',
			),

			'slash-year v2 posts endpoint goes to v2' => array(
				'slash_year_2020_site_id',
				'https://vancouver.wordcamp.test/2020/wp-json/wp/v2/posts/1',
				'v2',
				'/wp/v2/posts/17',
			),

			'dot-year v2 posts endpoint goes to v2' => array(
				'year_dot_2019_site_id',
				'https://2020.seattle.wordcamp.test/wp-json/wp/v2/posts/1',
				'v2',
				'/wp/v2/posts/17',
			),

			'slash-year v1 posts endpoint goes to v1' => array(
				'slash_year_2020_site_id',
				'https://vancouver.wordcamp.test/2020/wp-json/posts/1',
				'v1',
				'/posts/1',
			),

			'dot-year v1 posts endpoint goes to v1' => array(
				'year_dot_2019_site_id',
				'https://2020.seattle.wordcamp.test/wp-json/posts/1',
				'v1',
				'/posts/1',
			),
		);

		// Make sure each case is also tested with a trailing slash, because the results can be different.
		foreach( $unslashed_cases as $case ) {
			$case[1] = trailingslashit( $case[1] );

			$slashed_cases[] = $case;
		}

		return array_merge( $unslashed_cases, $slashed_cases );
	}
}
