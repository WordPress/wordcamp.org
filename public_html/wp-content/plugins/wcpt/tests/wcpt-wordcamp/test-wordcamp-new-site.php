<?php

namespace WordCamp\WCPT\Tests;
use WP_UnitTestCase;
use WordCamp_New_Site;

defined( 'WPINC' ) || die();

/**
 * @group wcpt
 */
class Test_WordCamp_New_Site extends WP_UnitTestCase {
	/**
	 * @covers WordCamp_New_Site::url_matches_expected_format
	 *
	 * @dataProvider data_url_matches_expected_format
	 */
	public function test_url_matches_expected_format( $domain, $path, $wordcamp_id, $expected ) {
		$actual = WordCamp_New_Site::url_matches_expected_format( $domain, $path, $wordcamp_id );

		$this->assertSame( $expected, $actual );
	}

	/**
	 * Test cases for test_url_matches_expected_format().
	 *
	 * @return array
	 */
	public function data_url_matches_expected_format() {
		return array(
			'old sites can have external domains' => array(
				'wordcampchicago.com',
				'/',
				590,
				true,
			),

			'newer exceptions can have external domains' => array(
				'2012.vancouver.buddypress.org',
				'/',
				169459,
				true,
			),

			"newer sites can't have external domains" => array(
				'wordcampsingapore2011.org',
				'/',
				2342,
				false,
			),

			'old internal sites should not have the year.city format' => array(
				'2011.jabalpur.wordcamp.test',
				'/',
				2340,
				false,
			),

			'newer internal sites should not have the year.city format' => array(
				'2011.jabalpur.wordcamp.test',
				'/',
				2342,
				false,
			),

			'old internal sites should have the city/year format' => array(
				'jabalpur.wordcamp.test',
				'/2011/',
				2340,
				true,
			),

			'newer internal sites should have the city/year format' => array(
				'jabalpur.wordcamp.test',
				'/2011/',
				2342,
				true,
			),
		);
	}
}
