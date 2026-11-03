<?php
/**
 * Tests for how website domains are normalized.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting\Tests;

use WP_UnitTestCase;
use function WordCamp\Sponsor_Vetting\normalize_domain;

defined( 'WPINC' ) || die();

/**
 * @group sponsor-vetting
 */
class Test_Domains extends WP_UnitTestCase {
	/**
	 * However a website is typed, the same company gets the same domain, so it's matched rather than added twice.
	 *
	 * @dataProvider data_domains
	 *
	 * @param string $url      A website as typed.
	 * @param string $expected Its domain.
	 */
	public function test_normalize_domain( string $url, string $expected ): void {
		$this->assertSame( $expected, normalize_domain( $url ) );
	}

	/**
	 * Websites and their domains.
	 *
	 * @return array[]
	 */
	public function data_domains(): array {
		return array(
			'scheme, www, case and path' => array( 'HTTPS://www.Example.com/about/', 'example.com' ),
			'no scheme, port'            => array( 'example.com:8080', 'example.com' ),
			'subdomain kept'             => array( 'http://shop.example.co.uk', 'shop.example.co.uk' ),
			'not a website'              => array( 'n/a', '' ),
		);
	}
}
