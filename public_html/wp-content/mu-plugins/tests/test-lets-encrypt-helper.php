<?php

namespace WordCamp\Lets_Encrypt\Tests;
use WP_UnitTestCase, WP_UnitTest_Factory;
use WordCamp_Lets_Encrypt_Helper;

use function WordCamp\Sunrise\get_domain_redirects;

defined( 'WPINC' ) || die();

/**
 * @group mu-plugins
 * @group lets-encrypt
 */
class Test_Lets_Encrypt extends WP_UnitTestCase {
	protected static $network_id;
	protected static $central_site_id;
	protected static $year_dot_2018_site_id;
	protected static $year_dot_2019_site_id;
	protected static $slash_year_2016_site_id;
	protected static $slash_year_2018_dev_site_id;
	protected static $slash_year_2020_site_id;
	protected static $expected_domains;

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

		self::$central_site_id = $factory->blog->create( array(
			'domain'     => 'central.wordcamp.test',
			'path'       => '/',
			'network_id' => self::$network_id,
		) );

		self::$year_dot_2018_site_id = $factory->blog->create( array(
			'domain'     => '2018.seattle.wordcamp.test',
			'path'       => '/',
			'network_id' => self::$network_id,
		) );

		self::$year_dot_2019_site_id = $factory->blog->create( array(
			'domain'     => '2019.seattle.wordcamp.test',
			'path'       => '/',
			'network_id' => self::$network_id,
		) );

		self::$slash_year_2016_site_id = $factory->blog->create( array(
			'domain'     => 'vancouver.wordcamp.test',
			'path'       => '/2016/',
			'network_id' => self::$network_id,
		) );

		self::$slash_year_2018_dev_site_id = $factory->blog->create( array(
			'domain'     => 'vancouver.wordcamp.test',
			'path'       => '/2018-developers/',
			'network_id' => self::$network_id,
		) );

		self::$slash_year_2020_site_id = $factory->blog->create( array(
			'domain'     => 'vancouver.wordcamp.test',
			'path'       => '/2020/',
			'network_id' => self::$network_id,
		) );

		$hardcoded_redirects = array_keys( get_domain_redirects() );

		self::$expected_domains = array_merge(
			array(
				'example.org', // The default site in WP's test suite.
				'central.wordcamp.test',

				// It should contain a city root site entry for the year.city domains that exist in the db.
				'seattle.wordcamp.test',

				// It should contain an entry for each year.city domain that exist in the database.
				'2018.seattle.wordcamp.test',
				'2019.seattle.wordcamp.test',

				// It should contain a single entry representing all city/year domains that exist in the database.
				'vancouver.wordcamp.test',

				// It should contain legacy entries for all city/year domains that exist in the database.
				'2016.vancouver.wordcamp.test',
				'2020.vancouver.wordcamp.test',
			),
			$hardcoded_redirects
		);
	}

	/**
	 * Revert the persistent changes from `wpSetUpBeforeClass()` that won't be automatically cleaned up.
	 */
	public static function wpTearDownAfterClass() {
		global $wpdb;

		wp_delete_site( self::$central_site_id );
		wp_delete_site( self::$year_dot_2018_site_id );
		wp_delete_site( self::$year_dot_2019_site_id );
		wp_delete_site( self::$slash_year_2016_site_id );
		wp_delete_site( self::$slash_year_2018_dev_site_id );
		wp_delete_site( self::$slash_year_2020_site_id );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE site_id = %d", self::$network_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->site}     WHERE id      = %d", self::$network_id ) );
	}

	/**
	 * @covers WordCamp_Lets_Encrypt_Helper::get_domains
	 */
	public function test_get_domains() {
		$actual = WordCamp_Lets_Encrypt_Helper::get_domains();

		$this->assertSame( self::$expected_domains, $actual );
	}

	/**
	 * @covers WordCamp_Lets_Encrypt_Helper::group_domains
	 */
	public function test_group_domains() {
		$actual = WordCamp_Lets_Encrypt_Helper::group_domains( self::$expected_domains );

		$flat_actual = array_merge(
			array_keys( $actual ),
			...array_values( $actual )
		);

		// All of the expected domains exists, no extra ones were added.
		$this->assertSame(
			sort( self::$expected_domains ),
			sort( $flat_actual )
		);

		$this->assertArrayHasKey( 'seattle.wordcamp.test', $actual );
		$this->assertArrayHasKey( 'vancouver.wordcamp.test', $actual );
		$this->assertArrayHasKey( 'sf.wordcamp.test', $actual );

		// year.city domains are added to the subarray.
		$this->assertSame(
			array(
				'2018.seattle.wordcamp.test',
				'2019.seattle.wordcamp.test',
			),
			$actual['seattle.wordcamp.test']
		);

		// Legacy domains for each city/year domain are added to the subarray.
		$this->assertSame(
			array(
				'2016.vancouver.wordcamp.test',
				'2020.vancouver.wordcamp.test',
			),
			$actual['vancouver.wordcamp.test']
		);

		// Special Cases
		$this->assertContains( 'central.wordcamp.test', $actual['wordcamp.test'] );
		$this->assertContains( '2006.wordcamp.test', $actual['sf.wordcamp.test'] );
		$this->assertContains( 'wordcampsf.org', $actual['sf.wordcamp.test'] );
	}
}
