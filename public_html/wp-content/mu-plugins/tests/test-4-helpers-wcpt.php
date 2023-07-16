<?php

namespace WordCamp\Helpers\WCPT\Tests;
use WP_UnitTestCase;

defined( 'WPINC' ) || die();


/**
 * @group mu-plugins
 * @group helpers
 * @group helpers-wcpt
 */
class Test_Helpers_WCPT extends WP_UnitTestCase {
	/**
	 * @covers ::wcorg_get_url_part
	 *
	 * @dataProvider data_wcorg_get_url_part
	 */
	public function test_wcorg_get_url_part( $site_url, $part, $expected ) {
		$actual = wcorg_get_url_part( $site_url, $part );

		$this->assertSame( $expected, $actual );
	}

	/**
	 * Test cases for test_wcorg_get_url_part().
	 *
	 * @return array
	 */
	public function data_wcorg_get_url_part() {
		return array(
			'year.city url - get city' => array(
				'https://2020.narnia.wordcamp.test',
				'city',
				'narnia',
			),

			'year.city url - get city-domain' => array(
				'https://2020.narnia.wordcamp.test',
				'city-domain',
				'narnia.wordcamp.test',
			),

			'year.city url - get year' => array(
				'https://2020.narnia.wordcamp.test',
				'year',
				2020,
			),

			'year.city url with extra - get year' => array(
				'https://2020-designers.narnia.wordcamp.test',
				'year',
				2020,
			),

			'city/year url - get city' => array(
				'https://narnia.wordcamp.test/2020',
				'city',
				'narnia',
			),

			'city/year url - get city-domain' => array(
				'https://narnia.wordcamp.test/2020',
				'city-domain',
				'narnia.wordcamp.test',
			),

			'city/year url - get year' => array(
				'https://narnia.wordcamp.test/2020',
				'year',
				2020,
			),

			'city/year url with extra - get year' => array(
				'https://narnia.wordcamp.test/2020-designers',
				'year',
				2020,
			),
		);
	}
}
