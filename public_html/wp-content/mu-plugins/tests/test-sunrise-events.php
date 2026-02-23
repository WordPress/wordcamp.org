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
use WordCamp\Tests\Database_TestCase;

defined( 'WPINC' ) || die();

/**
 * @group sunrise
 * @group mu-plugins
 */
class Test_Sunrise_Events extends Database_TestCase {
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

	/**
	 * @covers WordCamp\Site_URL_History\store_old_url_on_rename
	 */
	public function test_store_old_url_on_rename_saves_meta() {
		$site_id = self::factory()->blog->create( array(
			'domain'     => 'events.wordpress.test',
			'path'       => '/testcity/2025/meetup/',
			'network_id' => EVENTS_NETWORK_ID,
		) );

		// Simulate a path rename.
		wp_update_site( $site_id, array( 'path' => '/testcity/2026/meetup/' ) );

		$old_urls = get_site_meta( $site_id, 'old_home_url' );

		$this->assertContains( 'https://events.wordpress.test/testcity/2025/meetup/', $old_urls );

		wp_delete_site( $site_id );
	}

	/**
	 * @covers WordCamp\Site_URL_History\store_old_url_on_rename
	 */
	public function test_store_old_url_on_rename_skips_when_unchanged() {
		$site_id = self::factory()->blog->create( array(
			'domain'     => 'events.wordpress.test',
			'path'       => '/testcity/2025/workshop/',
			'network_id' => EVENTS_NETWORK_ID,
		) );

		// Update something other than domain/path.
		wp_update_site( $site_id, array( 'public' => 0 ) );

		$old_urls = get_site_meta( $site_id, 'old_home_url' );

		$this->assertEmpty( $old_urls );

		wp_delete_site( $site_id );
	}

	/**
	 * @covers WordCamp\Site_URL_History\store_old_url_on_rename
	 */
	public function test_store_old_url_on_rename_no_duplicates() {
		$site_id = self::factory()->blog->create( array(
			'domain'     => 'events.wordpress.test',
			'path'       => '/testcity/2025/conference/',
			'network_id' => EVENTS_NETWORK_ID,
		) );

		// Rename, then rename back, then rename again to trigger duplicate attempt.
		wp_update_site( $site_id, array( 'path' => '/testcity/2026/conference/' ) );
		wp_update_site( $site_id, array( 'path' => '/testcity/2025/conference/' ) );
		wp_update_site( $site_id, array( 'path' => '/testcity/2026/conference/' ) );

		$old_urls = get_site_meta( $site_id, 'old_home_url' );
		$original_count = count(
			array_filter(
				$old_urls,
				function ( $url ) {
					return 'https://events.wordpress.test/testcity/2025/conference/' === $url;
				}
			)
		);

		$this->assertSame( 1, $original_count );

		wp_delete_site( $site_id );
	}

	/**
	 * @covers WordCamp\Sunrise\Events\get_latest_event_url
	 *
	 * @dataProvider data_get_latest_event_url
	 */
	public function test_get_latest_event_url( $request_path, $expected ) {
		$actual = get_latest_event_url( $request_path );

		$this->assertSame( $expected, $actual );
	}

	/**
	 * Test cases for test_get_latest_event_url().
	 *
	 * @return array
	 */
	public function data_get_latest_event_url() {
		return array(
			'old year redirects to latest year' => array(
				'/rome/2023/training/',
				'https://events.wordpress.test/rome/2024/training/',
			),

			'current year does not redirect' => array(
				'/rome/2024/training/',
				false,
			),

			'unknown city returns false' => array(
				'/narnia/2023/meetup/',
				false,
			),

			'non-matching path returns false' => array(
				'/some-page/',
				false,
			),
		);
	}
}
