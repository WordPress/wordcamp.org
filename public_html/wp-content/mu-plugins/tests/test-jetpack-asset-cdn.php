<?php

namespace WordCamp\Tests;

use WP_Error, WP_UnitTestCase;
use function WordCamp\Jetpack_Tweaks\Asset_CDN\skip_core_versions_the_cdn_lacks;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/jetpack-tweaks/asset-cdn.php';

/**
 * Tests that Jetpack's asset CDN is only used for core versions it actually has.
 *
 * @group mu-plugins
 * @group jetpack
 */
class Test_Jetpack_Asset_CDN extends WP_UnitTestCase {
	/**
	 * How many HEAD requests the fake transport has seen.
	 *
	 * @var int
	 */
	protected $request_count = 0;

	/**
	 * Fake the CDN's response to the availability check.
	 *
	 * @param mixed $response The canned response to return, or a WP_Error.
	 */
	protected function fake_cdn_response( $response ) {
		add_filter(
			'pre_http_request',
			function () use ( $response ) {
				$this->request_count++;

				return $response;
			}
		);
	}

	/**
	 * A version the CDN serves is passed through untouched.
	 *
	 * @covers \WordCamp\Jetpack_Tweaks\Asset_CDN\skip_core_versions_the_cdn_lacks
	 */
	public function test_released_version_is_kept() {
		$this->fake_cdn_response( array( 'response' => array( 'code' => 200 ) ) );

		$this->assertSame(
			array( '7.1.2', 'en_US' ),
			skip_core_versions_the_cdn_lacks( array( '7.1.2', 'en_US' ) )
		);
	}

	/**
	 * A version the CDN 404s on comes back as something Jetpack won't treat as a public release.
	 *
	 * @covers \WordCamp\Jetpack_Tweaks\Asset_CDN\skip_core_versions_the_cdn_lacks
	 */
	public function test_unreleased_version_is_marked_unpublished() {
		$this->fake_cdn_response( array( 'response' => array( 'code' => 404 ) ) );

		$actual = skip_core_versions_the_cdn_lacks( array( '7.1.3', 'en_US' ) );

		$this->assertSame( array( '7.1.3-unpublished', 'en_US' ), $actual );

		if ( is_callable( array( 'Jetpack_Photon_Static_Assets_CDN', 'is_public_version' ) ) ) {
			$this->assertFalse( \Jetpack_Photon_Static_Assets_CDN::is_public_version( $actual[0] ) );
		}
	}

	/**
	 * Once an answer is cached, no further requests are made for that version.
	 *
	 * @covers \WordCamp\Jetpack_Tweaks\Asset_CDN\cdn_has_core_version
	 */
	public function test_cached_answer_makes_no_request() {
		$this->fake_cdn_response( array( 'response' => array( 'code' => 404 ) ) );

		skip_core_versions_the_cdn_lacks( array( '7.1.9', 'en_US' ) );
		skip_core_versions_the_cdn_lacks( array( '7.1.9', 'en_US' ) );

		$this->assertSame( 1, $this->request_count );
	}

	/**
	 * A failed request counts as "no" now, but leaves nothing behind for the next page load.
	 *
	 * @covers \WordCamp\Jetpack_Tweaks\Asset_CDN\cdn_has_core_version
	 */
	public function test_request_error_is_not_cached() {
		$this->fake_cdn_response( new WP_Error( 'http_request_failed', 'Operation timed out' ) );

		$this->assertSame(
			array( '7.2.0-unpublished', 'en_US' ),
			skip_core_versions_the_cdn_lacks( array( '7.2.0', 'en_US' ) )
		);
		$this->assertFalse( get_site_transient( 'wc_cdn_core_version_' . md5( '7.2.0' ) ) );
		$this->assertSame( 1, $this->request_count );
	}
}
