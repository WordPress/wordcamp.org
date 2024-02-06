<?php

namespace WordCamp\Lets_Encrypt\Tests;
use WP_UnitTest_Factory;
use WordCamp\Tests\Database_TestCase;
use WordCamp_Lets_Encrypt_Helper;

use function WordCamp\Sunrise\get_domain_redirects;

defined( 'WPINC' ) || die();

/**
 * @group mu-plugins
 * @group lets-encrypt
 */
class Test_Lets_Encrypt extends Database_TestCase {
	protected static $expected_domains;

	/**
	 * Setup state that should be shared by all tests.
	 *
	 * @param WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		parent::wpSetUpBeforeClass( $factory );

		$hardcoded_redirects = array_keys( get_domain_redirects() );
		$hardcoded_redirects = WordCamp_Lets_Encrypt_Helper::parse_domain( $hardcoded_redirects );

		// Test sites created by `parent::wpSetUpBeforeClass()`.
		$database_sites = array(
			'example.org', // The default site in WP's test suite.
			'central.wordcamp.test',

			// It should contain a city root site entry for the year.city domains that exist in the db.
			'seattle.wordcamp.test',

			// It should contain an entry for each year.city domain that exist in the database.
			'2018.seattle.wordcamp.test',
			'2019.seattle.wordcamp.test',

			// It should contain a single entry representing all city/year domains that exist in the database.
			'vancouver.wordcamp.test',

			// It should contain legacy entries for all city/year domains that existed before the migration.
			'2016.vancouver.wordcamp.test',
			'2018-developers.vancouver.wordcamp.test',
			'2020.vancouver.wordcamp.test',
			'2020-developers.vancouver.wordcamp.test',
			'2021.japan.wordcamp.test',

			// It should contain old year-less domains.
			'japan.wordcamp.test',
		);

		self::$expected_domains = array_merge( $database_sites, $hardcoded_redirects );

		sort( self::$expected_domains );
	}

	/**
	 * Revert the persistent changes from `wpSetUpBeforeClass()` that won't be automatically cleaned up.
	 */
	public static function wpTearDownAfterClass() {
		parent::wpTearDownAfterClass();
	}

	/**
	 * @covers WordCamp_Lets_Encrypt_Helper::get_domains
	 */
	public function test_get_domains() {
		$actual = WordCamp_Lets_Encrypt_Helper::get_domains();
		$only_domains = true;

		sort( $actual );

		$this->assertSame( self::$expected_domains, $actual );

		foreach ( $actual as $domain ) {
			if ( $domain !== wp_parse_url( "https://$domain", PHP_URL_HOST ) ) {
				$only_domains = false;
				break;
			}
		}

		$this->assertTrue( $only_domains, "Failed asserting that $domain is a valid hostname." );
	}

	/**
	 * @covers WordCamp_Lets_Encrypt_Helper::group_domains
	 */
	public function test_group_domains() {
		$actual = WordCamp_Lets_Encrypt_Helper::group_domains( self::$expected_domains );

		// Reverting to a flat array makes comparing them easier.
		$flat_actual = array_merge(
			array_keys( $actual ),
			...array_values( $actual )
		);

		// All of the expected domains are present.
		$all_domains_found = true;

		foreach( self::$expected_domains as $domain ) {
			if ( ! in_array( $domain, $flat_actual ) ) {
				$all_domains_found = false;
				break;
			}
		}

		$this->assertTrue( $all_domains_found );

		// `group_domains()` should add `city.wordcamp.org` domains for the hardcoded redirects, so it'll have a higher count.
		$this->assertGreaterThan(
			count( self::$expected_domains ),
			count( $flat_actual )
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

		// Legacy domains for each city/year site are added to the subarray.
		$this->assertSame(
			array(
				'2016.vancouver.wordcamp.test',
				'2020.vancouver.wordcamp.test',
				'2018-developers.vancouver.wordcamp.test',
				'2020-developers.vancouver.wordcamp.test',
			),
			$actual['vancouver.wordcamp.test']
		);

		// Special Cases
		$this->assertContains( 'central.wordcamp.test', $actual['wordcamp.test'] );
		$this->assertContains( '2006.wordcamp.test', $actual['sf.wordcamp.test'] );
		$this->assertContains( 'wordcampsf.org', $actual['sf.wordcamp.test'] );
	}
}
