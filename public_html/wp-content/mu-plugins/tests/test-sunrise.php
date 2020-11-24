<?php

/*
 * This isn't technically an mu-plugin, but it's easier to just put this here than creating a whole new suite.
 *
 * @todo `sunrise.php` isn't watched by phpunit-watcher because it can only watch entire folders, and `wp-content`
 * is too big to monitor without significant performance impacts. You'll have to modify this file to automatically
 * re-run tests.
 *
 * See https://github.com/spatie/phpunit-watcher/issues/113
 */


namespace WordCamp\Sunrise\Tests;
use WP_UnitTest_Factory;
use WordCamp\Tests\Database_TestCase;

use function WordCamp\Sunrise\{
	get_canonical_year_url, get_post_slug_url_without_duplicate_dates, guess_requested_domain_path,
	get_corrected_root_relative_url, get_city_slash_year_url, domain_redirects, root_redirects,
};

defined( 'WPINC' ) || die();

/**
 * @group mu-plugins
 * @group sunrise
 */
class Test_Sunrise extends Database_TestCase {
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
	 * @covers ::guess_requested_domain_path
	 *
	 * @dataProvider data_guess_requested_domain_path
	 */
	public function test_guess_requested_domain_path( $site ) {
		list(
			'domain'     => $domain,
			'test-paths' => $test_paths,
			'expected'   => $expected,
		) = $site;

		$_SERVER['HTTP_HOST'] = $domain;

		foreach ( $test_paths as $path ) {
			$_SERVER['REQUEST_URI'] = $path;

			$actual = guess_requested_domain_path();

			$this->assertSame( $expected, $actual );
		}
	}

	/**
	 * Test cases for test_guess_requested_domain_path().
	 *
	 * @return array
	 */
	public function data_guess_requested_domain_path() {
		return array(
			'root site' => array( array(
				'domain' => 'wordcamp.test',

				'test-paths' => array(
					'/',
					'/schedule/',
					'/2020/', // Year archive.
				),

				'expected' => array(
					'domain' => 'wordcamp.test',
					'path'   => '/',
				),
			) ),

			'central' => array( array(
				'domain' => 'central.wordcamp.test',

				'test-paths' => array(
					'/',
					'/schedule/',

					// This function isn't expected to distinguish the `/2020/` path as a year archive. See its phpdoc.
				),

				'expected' => array(
					'domain' => 'central.wordcamp.test',
					'path'   => '/',
				),
			) ),

			'year.city site' => array( array(
				'domain' => '2020.seattle.wordcamp.test',

				'test-paths' => array(
					'/',
					'/schedule/',
					'/2020/', // Year archive.
				),

				'expected' => array(
					'domain' => '2020.seattle.wordcamp.test',
					'path'   => '/',
				),
			) ),

			'city/year site' => array( array(
				'domain' => 'vancouver.wordcamp.test',

				'test-paths' => array(
					'/2020/',
					'/2020/schedule/',
					'/2020/2020/', // Year archive.
					'/2020',
					'/2020?s=foo',
					'/2020?s=foo&bar=1',
					'/2020?s=foo&bar=1#quix',
				),

				'expected' => array(
					'domain' => 'vancouver.wordcamp.test',
					'path'   => '/2020/',
				),
			) ),
		);
	}

	/**
	 * @covers ::root_redirects
	 *
	 * @dataProvider data_root_redirects
	 */
	public function test_root_redirects( $domain, $path, $expected ) {
		$actual = root_redirects( $domain, $path );

		$this->assertSame( $expected, $actual );
	}

	/**
	 * Test cases for test_root_redirects().
	 *
	 * @return array
	 */
	public function data_root_redirects() {
		return array(
			/*
			 * There aren't any cases to test that front-end requests to the root site redirect to Central,
			 * because it's difficult to mock `is_admin()` being `false` in that context.
			 * `set_current_screen( 'front' )` works for some cases, but not all. `$this->>go_to()` doesn't seem
			 * to help either.
			 */

			'root site cron requests _dont_ redirect to Central' => array(
				'wordcamp.test',
				'/wp-cron.php',
				false,
			),

			'root site rest requests _dont_ redirect to Central' => array(
				'wordcamp.test',
				'/wp-json',
				false,
			),

			'non-root domains _dont_ redirect' => array(
				'narnia.wordcamp.test',
				'/',
				false,
			),
		);
	}

	/**
	 * @covers ::domain_redirects
	 * @covers ::get_domain_redirects
	 *
	 * @dataProvider data_domain_redirects
	 */
	public function test_domain_redirects( $domain, $path, $request_uri, $expected ) {
		$actual = domain_redirects( $domain, $path, $request_uri );

		$this->assertSame( $expected, $actual );
	}

	/**
	 * Test cases for test_domain_redirects().
	 *
	 * @return array
	 */
	public function data_domain_redirects() {
		return array(
			'domain redirect to central removes request uri' => array(
				'bg.wordcamp.test',
				'/',
				'/schedule/',
				'https://central.wordcamp.test',
			),

			'domain redirect from year.city site to city/year site, including request uri' => array(
				'2010.philly.wordcamp.test',
				'/',
				'/schedule/',
				'https://philadelphia.wordcamp.test/2010/schedule/',
			),

			'domain redirect from city/year site to other city/year site' => array(
				'india.wordcamp.test',
				'/2020/',
				'/2020/schedule/',
				'https://india.wordcamp.test/2021/schedule/',
			),

			'external domain redirects to central' => array(
				'wordcampsf.org',
				'/',
				'/schedule/',
				'https://sf.wordcamp.test/schedule/',
			),

			'unknown domain should not redirect' => array(
				'narnia.wordcamp.test',
				'/',
				'/',
				false,
			),
		);
	}

	/**
	 * @covers ::get_city_slash_year_url
	 *
	 * @dataProvider data_get_city_slash_year_url
	 */
	public function test_get_city_slash_year_url( $domain, $request_uri, $expected ) {
		$actual = get_city_slash_year_url( $domain, $request_uri );

		$this->assertSame( $expected, $actual );
	}

	/**
	 * Test cases for test_get_city_slash_year_url().
	 *
	 * @return array
	 */
	public function data_get_city_slash_year_url() {
		return array(
			/*
			 * Should not redirect.
			 */
			'root site homepage should not redirect' => array(
				'wordcamp.test',
				'/',
				false,
			),

			'root site subpage should not redirect' => array(
				'wordcamp.test',
				'/schedule/',
				false,
			),

			'central homepage should not redirect' => array(
				'central.wordcamp.test',
				'/',
				false,
			),

			'central subpage should not redirect' => array(
				'central.wordcamp.test',
				'/schedule/',
				false,
			),

			'city missing from `$redirect_cities` should not redirect' => array(
				'2018.narnia.wordcamp.test',
				'/',
				false,
			),

			'already a city/year format homepage should not redirect' => array(
				'vancouver.wordcamp.test',
				'/2020/',
				false,
			),

			'already a city/year format subpage should not redirect' => array(
				'vancouver.wordcamp.test',
				'/2020/schedule/',
				false,
			),

			'already a city/year format year archive should not redirect' => array(
				'vancouver.wordcamp.test',
				'/2020/2020/',
				false,
			),


			/*
			 * Should redirect.
			 */
			'city.year homepage should redirect' => array(
				'2020.testing.wordcamp.test',
				'/',
				'https://testing.wordcamp.test/2020/',
			),

			'city.year subpage should redirect' => array(
				'2020.testing.wordcamp.test',
				'/schedule/',
				'https://testing.wordcamp.test/2020/schedule/',
			),

			'city.year year archive should redirect' => array(
				'2020.testing.wordcamp.test',
				'/2020/',
				'https://testing.wordcamp.test/2020/2020/',
			),

			'city.year extra ID homepage should redirect' => array(
				'2019-designers.testing.wordcamp.test',
				'/',
				'https://testing.wordcamp.test/2019-designers/',
			),

			'city.year extra ID subpage should redirect' => array(
				'2019-designers.testing.wordcamp.test',
				'/schedule/',
				'https://testing.wordcamp.test/2019-designers/schedule/',
			),

			'city.year extra ID year archive should redirect' => array(
				'2019-designers.testing.wordcamp.test',
				'/2019/',
				'https://testing.wordcamp.test/2019-designers/2019/',
			),
		);
	}

	/**
	 * @covers ::get_corrected_root_relative_url
	 *
	 * @dataProvider data_get_corrected_root_relative_url
	 */
	public function test_get_corrected_root_relative_url( $domain, $path, $request_uri, $referer, $expected ) {
		$actual = get_corrected_root_relative_url( $domain, $path, $request_uri, $referer );

		$this->assertSame( $expected, $actual );
	}

	/**
	 * Test cases for test_get_corrected_root_relative_url().
	 *
	 * @return array
	 */
	public function data_get_corrected_root_relative_url() {
		return array(

			/*
			 * Negative cases.
			 */
			"root site isn't affected" => array(
				'wordcamp.test',
				'/',
				'/schedule',
				'https://vancouver.wordcamp.test/2016/',
				false,
			),

			"3rd-level domains that aren't camp sites aren't affected - external referral" => array(
				'central.wordcamp.test',
				'/',
				'/schedule',
				'https://vancouver.wordcamp.test/2016/schedule/',
				false,
			),

			"3rd-level domains that aren't camp sites aren't affected - self referral" => array(
				'central.wordcamp.test',
				'/',
				'/schedule',
				'https://central.wordcamp.test/about/',
				false,
			),

			"year.city sites aren't impacted" => array(
				'2018.seattle.wordcamp.test',
				'/',
				'/schedule',
				'https://seattle.wordcamp.test/2018/',
				false,
			),

			"city/year sites aren't impacted" => array(
				'seattle.wordcamp.test',
				'/2018/',
				'/2018/schedule/',
				'https://seattle.wordcamp.test/2018/',
				false,
			),

			'no referrer' => array(
				'vancouver.wordcamp.test',
				'/',
				'/tickets',
				'',
				false,
			),

			'3rd-party referrer' => array(
				'vancouver.wordcamp.test',
				'/',
				'/tickets',
				'https://example.org/foo.html',
				false,
			),

			"sites after the 2020 URL migration aren't impacted" => array(
				'vancouver.wordcamp.test',
				'/',
				'/tickets/',
				'https://vancouver.wordcamp.test/2021/',
				false,
			),

			/*
			 * Positive cases.
			 */
			'homepage referred from camp site' => array(
				'vancouver.wordcamp.test',
				'/',
				'/',
				'https://vancouver.wordcamp.test/2016/tickets/',
				'https://vancouver.wordcamp.test/2016/',
			),

			'subpage referred from camp site' => array(
				'vancouver.wordcamp.test',
				'/',
				'/tickets',
				'https://vancouver.wordcamp.test/2016/',
				'https://vancouver.wordcamp.test/2016/tickets/',
			),

			'referred from subpage on camp site' => array(
				'vancouver.wordcamp.test',
				'/',
				'/tickets',
				'https://vancouver.wordcamp.test/2016/news/',
				'https://vancouver.wordcamp.test/2016/tickets/',
			),

			'referred from subpage on camp site with extra site identifier' => array(
				'vancouver.wordcamp.test',
				'/',
				'/tickets',
				'https://vancouver.wordcamp.test/2018-developers/news/',
				'https://vancouver.wordcamp.test/2018-developers/tickets/',
			),

			'image referred from camp site' => array(
				'london.wordcamp.test',
				'/',
				'/files/2015/03/wapuunk.png',
				'https://london.wordcamp.test/2015/wapuunk-wallpapers-and-more/',
				'https://london.wordcamp.test/2015/files/2015/03/wapuunk.png',
			),
		);
	}

	/**
	 * @covers ::get_canonical_year_url
	 *
	 * @dataProvider data_get_canonical_year_url
	 */
	public function test_get_canonical_year_url( $domain, $path, $expected ) {
		$actual = get_canonical_year_url( $domain, $path );

		$this->assertSame( $expected, $actual );
	}

	/**
	 * Test cases for test_get_canonical_year_url().
	 *
	 * @return array
	 */
	public function data_get_canonical_year_url() {
		return array(
			'dont redirect root site' => array(
				'wordcamp.test',
				'/',
				false,
			),

			'dont redirect non-existent site' => array(
				'narnia.wordcamp.test',
				'/',
				false,
			),

			/*
			 * e.g., https://japan.wordcamp.org/what-is-wordcamp/.
			 * e.g., https://japan.wordcamp.org/blog/2019/12/13/call-for-wordcamp-ogijima-2020-organizer-and-support-staff/
			 */
			"dont redirect permalinks on an old yearless site, even if there's a newer city/year site" => array(
				'japan.wordcamp.test',
				'/', // `guess_requested_domain_path()` will correctly guess `/` as the site path rather than the query string.
				false,
			),

			'dont redirect year.city sites' => array(
				'2018.seattle.wordcamp.test',
				'/',
				false,
			),

			'dont redirect city/year sites' => array(
				'vancouver.wordcamp.test',
				'/2020/',
				false,
			),

			/*
			 * e.g., https://japan.wordcamp.org/2019/12/13/call-for-wordcamp-ogijima-2020-organizer-and-support-staff/
			 *
			 * Ideally they wouldn't redirect, but this is such an edge case that it's not worth supporting. That's
			 * enforced by `wcorg_prevent_date_permalinks()`.
			 */
			'redirect date-based permalinks on an old yearless sites to the latest site' => array(
				'japan.wordcamp.test',
				'/2019/',
				'https://japan.wordcamp.test/2021/',
			),

			'404 at canonical domain should redirect to latest site' => array(
				'vancouver.wordcamp.test',
				'/this-page-does-not-exist/',
				'https://vancouver.wordcamp.test/2020/',
			),

			'future years that dont exist should redirect to latest site' => array(
				'vancouver.wordcamp.test',
				'/2024/',
				'https://vancouver.wordcamp.test/2020/',
			),

			'redirect year.city root to latest camp' => array(
				'seattle.wordcamp.test',
				'/',
				'https://2019.seattle.wordcamp.test/',
			),

			'redirect city/year root to latest camp' => array(
				'vancouver.wordcamp.test',
				'/',
				'https://vancouver.wordcamp.test/2020/',
			),
		);
	}

	/**
	 * @covers ::get_post_slug_url_without_duplicate_dates
	 *
	 * @dataProvider data_get_post_slug_url_without_duplicate_dates
	 */
	public function test_get_post_slug_url_without_duplicate_dates( $is_404, $permalink_structure, $domain, $path, $request_uri, $expected ) {
		$actual = get_post_slug_url_without_duplicate_dates( $is_404, $permalink_structure, $domain, $path, $request_uri );

		$this->assertSame( $expected, $actual );
	}

	/**
	 * Test cases for test_get_post_slug_url_without_duplicate_dates().
	 *
	 * @return array
	 */
	public function data_get_post_slug_url_without_duplicate_dates() {
		return array(
			'redirect matching requests with only year' => array(
				true,
				'/%postname%/',
				'vancouver.wordcamp.test',
				'/2020/',
				'/2020/2019/save-the-date-for-wordcamp-vancouver-2020/',
				'https://vancouver.wordcamp.test/2020/save-the-date-for-wordcamp-vancouver-2020/',
			),

			'redirect matching requests with year and month' => array(
				true,
				'/%postname%/',
				'vancouver.wordcamp.test',
				'/2018-developers/',
				'/2018-developers/2019/12/save-the-date-for-wordcamp-vancouver-2020/',
				'https://vancouver.wordcamp.test/2018-developers/save-the-date-for-wordcamp-vancouver-2020/',
			),

			'redirect matching requests with year, month, and day' => array(
				true,
				'/%postname%/',
				'vancouver.wordcamp.test',
				'/2020/',
				'/2020/2019/12/23/save-the-date-for-wordcamp-vancouver-2020/',
				'https://vancouver.wordcamp.test/2020/save-the-date-for-wordcamp-vancouver-2020/',
			),

			"only redirect if there's a duplicate date" => array(
				true,
				'/%postname%/',
				'vancouver.wordcamp.test',
				'/2020/',
				'/2020/206/save-the-date-for-wordcamp-vancouver-2020/', // `206` could be a tag slug, etc.
				false,
			),

			"don't create an infinite redirect loop" => array(
				true,
				'/%postname%/',
				'vancouver.wordcamp.test',
				'/2020/',
				'/2020/save-the-date-for-wordcamp-vancouver-2020/',
				false,
			),

			"don't redirect non-404 requests" => array(
				false,
				'/%postname%/',
				'vancouver.wordcamp.test',
				'/2020/',
				'/2020/2019/save-the-date-for-wordcamp-vancouver-2020/',
				false,
			),

			"don't redirect if the site uses a data permastruct" => array(
				true,
				'/%year%/%monthnum%/%day%/%postname%/',
				'vancouver.wordcamp.test',
				'/2020/',
				'/2020/2019/save-the-date-for-wordcamp-vancouver-2020/',
				false,
			),

			"don't redirect city.year sites" => array(
				true,
				'/%postname%/',
				'2020.vancouver.wordcamp.test',
				'/',
				'/2020/2019/save-the-date-for-wordcamp-vancouver-2020/',
				false,
			),
		);
	}
}
