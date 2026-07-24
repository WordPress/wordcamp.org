<?php

namespace WordCamp\Groups\Tests;

use WordCamp\Tests\Database_TestCase;
use WP_UnitTest_Factory;

/**
 * Extends the shared WordCamp.org network fixture with a groups-network site.
 *
 * `GROUPS_NETWORK_ID`/`GROUPS_ROOT_BLOG_ID` are already defined in
 * `phpunit-bootstrap.php`, but `Database_TestCase` doesn't build that
 * network/site — only the WordCamp/Events ones. Test classes that need a
 * groups-network site should extend this instead of `Database_TestCase`.
 */
abstract class Groups_TestCase extends Database_TestCase {
	/**
	 * @var int
	 */
	protected static $groups_root_site_id;

	/**
	 * @param WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		parent::wpSetUpBeforeClass( $factory );

		$factory->network->create(
			array(
				'domain'     => 'events.wordpress.test',
				'path'       => '/group/',
				'network_id' => GROUPS_NETWORK_ID,
			)
		);

		self::$groups_root_site_id = $factory->blog->create(
			array(
				'domain'     => 'events.wordpress.test',
				'path'       => '/group/sunshine-coast-qld/',
				'blog_id'    => GROUPS_ROOT_BLOG_ID,
				'network_id' => GROUPS_NETWORK_ID,
			)
		);
	}

	public static function wpTearDownAfterClass() {
		global $wpdb;

		wp_delete_site( self::$groups_root_site_id );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE site_id = %d", GROUPS_NETWORK_ID ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->site} WHERE id = %d", GROUPS_NETWORK_ID ) );

		parent::wpTearDownAfterClass();
	}

	protected function setUp(): void {
		parent::setUp();

		switch_to_blog( self::$groups_root_site_id );

		// GatherPress creates its `{prefix}gatherpress_events` table on
		// plugin activation; since these tests require the plugin file
		// directly instead of truly activating it, the table never gets
		// created for this fixture blog. `check_plugin_version()` is
		// GatherPress's own public, idempotent self-heal for exactly this
		// scenario ("site created before plugin activation") — safe to
		// call every test.
		if ( class_exists( '\GatherPress\Core\Setup' ) ) {
			\GatherPress\Core\Setup::get_instance()->check_plugin_version();
		}
	}

	protected function tearDown(): void {
		restore_current_blog();
		parent::tearDown();
	}
}
