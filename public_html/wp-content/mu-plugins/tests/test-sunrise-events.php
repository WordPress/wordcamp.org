<?php
/*
 * This isn't technically an mu-plugin, but it's easier to just put this here than creating a whole new suite.
 *
 * @todo `sunrise-events.php` isn't watched by phpunit-watcher because it can only watch entire folders, and
 * `wp-content` is too big to monitor without significant performance impacts. You'll have to modify this file
 * to automatically re-run tests.
 *
 * See https://github.com/spatie/phpunit-watcher/issues/113
 */


namespace WordCamp\Sunrise\Events;
use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * @group sunrise
 * @group mu-plugins
 */
class Test_Sunrise_Events extends WP_UnitTestCase {
	/**
	 * @covers WordCamp\Sunrise\get_redirect_url
	 *
	 * @dataProvider data_get_redirect_url
	 */
	public function test_get_redirect_url( $request_uri, $expected_url ) {
		$actual_url = get_redirect_url( $request_uri );

		$this->assertSame( $expected_url, $actual_url );
	}

	/**
	 * Test cases for test_get_redirect_url().
	 *
	 * @return array
	 */
	public function data_get_redirect_url() {
		return array(
			'no redirect' => array(
				'request_uri'  => '/foo/2024/bar/',
				'expected_url' => '',
			),

			'without subpath or query vars' => array(
				'request_uri'  => '/uganda/2024/wordpress-showcase/',
				'expected_url' => 'https://events.wordpress.test/masaka/2024/wordpress-showcase/',
			),

			'with subpath and query vars' => array(
				'request_uri'  => '/uganda/2024/wordpress-showcase/schedule/?foo=bar',
				'expected_url' => 'https://events.wordpress.test/masaka/2024/wordpress-showcase/schedule/?foo=bar',
			),
		);
	}
}
