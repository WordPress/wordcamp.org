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

	/**
	 * Tears down the groups-network fixture created in wpSetUpBeforeClass().
	 */
	public static function wpTearDownAfterClass() {
		global $wpdb;

		wp_delete_site( self::$groups_root_site_id );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE site_id = %d", GROUPS_NETWORK_ID ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->site} WHERE id = %d", GROUPS_NETWORK_ID ) );

		parent::wpTearDownAfterClass();
	}

	/**
	 * Switches to the groups-network fixture site before each test.
	 */
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
		\GatherPress\Core\Setup::get_instance()->check_plugin_version();

		// Same situation for the recurring-events extension's own tables: its
		// install step only runs once, from 'plugins_loaded', for whichever
		// site happened to be current when this PHPUnit process bootstrapped
		// — not this fixture blog. maybe_install() is the equivalent
		// idempotent self-heal. Guarded because this plugin is only loaded by
		// the combined, repo-wide suite (see phpunit-bootstrap.php), not by
		// this plugin's own standalone tests/bootstrap.php.
		if ( class_exists( '\WordPressdotorg\GatherPress_Recurring_Events\Database' ) ) {
			\WordPressdotorg\GatherPress_Recurring_Events\Database::maybe_install();
		}

		// `schedule_new_event_notification()` (#1829) sends GatherPress's
		// "all members" email directly and synchronously from
		// `transition_post_status` (see its own docblock for why it isn't
		// deferred through wp-cron). Any test in this suite that publishes
		// a `gatherpress_event` post -- most of them, incidentally, not
		// just tests actually about notifications -- would otherwise
		// trigger real email-template rendering, which needs runtime
		// (theme template functions, etc.) this bootstrap doesn't load.
		// Test_Groups_Notifications, the one test class that wants this
		// hook active, re-adds it itself.
		remove_action(
			'transition_post_status',
			'WordCamp\Groups\Frontend\Notifications\schedule_new_event_notification',
			10
		);
	}

	/**
	 * Restores the previously-current blog after each test.
	 */
	protected function tearDown(): void {
		restore_current_blog();
		parent::tearDown();
	}
}
