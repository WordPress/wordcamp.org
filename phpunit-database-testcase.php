<?php

namespace WordCamp\Tests;
use WP_UnitTestCase, WP_UnitTest_Factory;


/**
 * Provides a mock WordCamp.org network of sites to test against.
 *
 * Test classes that need to interact with the database should extend this class, instead of extending
 * `WP_UnitTestCase` directly.
 *
 * Other test cases should extend `WP_UnitTestCase` directly, to avoid the performance delays that this
 * introduces.
 */
class Database_TestCase extends WP_UnitTestCase {
	protected static $network_id;
	protected static $central_site_id;
	protected static $year_dot_2018_site_id;
	protected static $year_dot_2019_site_id;
	protected static $slash_year_2016_site_id;
	protected static $slash_year_2018_dev_site_id;
	protected static $slash_year_2020_site_id;
	protected static $yearless_site_id;

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

		self::$yearless_site_id = $factory->blog->create( array(
			'domain'     => 'japan.wordcamp.test',
			'path'       => '/',
			'network_id' => self::$network_id,
		) );
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
}
