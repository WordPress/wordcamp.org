<?php

namespace WordCamp\WCPT\Tests;

use Meetup_Admin;
use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * @group wcpt
 */
class Test_Meetup_Group_Link extends WP_UnitTestCase {
	/**
	 * Group site the tracker record links to.
	 *
	 * @var int
	 */
	protected static $group_site_id;

	/**
	 * @var Meetup_Admin
	 */
	protected $admin;

	/**
	 * @var int
	 */
	protected $post_id;

	/**
	 * Create the groups network, its root, and one group site.
	 *
	 * @param \WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		$factory->network->create(
			array(
				'domain'     => 'events.wordpress.test',
				'path'       => '/group/',
				'network_id' => GROUPS_NETWORK_ID,
			)
		);

		$factory->blog->create(
			array(
				'domain'     => 'events.wordpress.test',
				'path'       => '/group/',
				'blog_id'    => GROUPS_ROOT_BLOG_ID,
				'network_id' => GROUPS_NETWORK_ID,
			)
		);

		self::$group_site_id = $factory->blog->create(
			array(
				'domain'     => 'events.wordpress.test',
				'path'       => '/group/joinville/',
				'network_id' => GROUPS_NETWORK_ID,
			)
		);
	}

	/**
	 * Remove the groups network fixtures.
	 */
	public static function wpTearDownAfterClass() {
		global $wpdb;

		wp_delete_site( self::$group_site_id );
		wp_delete_site( GROUPS_ROOT_BLOG_ID );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE site_id = %d", GROUPS_NETWORK_ID ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->site} WHERE id = %d", GROUPS_NETWORK_ID ) );
	}

	/**
	 * Create a tracker record.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! class_exists( 'Meetup_Admin' ) ) {
			require_once WCPT_DIR . 'wcpt-meetup/class-meetup-admin.php';
		}

		$this->admin   = $GLOBALS['meetup_admin'] ?? new Meetup_Admin();
		$this->post_id = self::factory()->post->create( array( 'post_type' => 'wp_meetup' ) );
	}

	/**
	 * Clean up the request and the scheduled refresh.
	 */
	public function tear_down() {
		$_POST                             = array();
		$this->admin->active_admin_notices = array();

		switch_to_blog( GROUPS_ROOT_BLOG_ID );
		wp_unschedule_hook( Meetup_Admin::GROUP_REFRESH_HOOK );
		restore_current_blog();

		parent::tear_down();
	}

	/**
	 * Save the record from the edit screen with a group URL.
	 *
	 * @param string $url   GatherPress group URL.
	 * @param array  $extra Other fields.
	 */
	protected function save( string $url, array $extra = array() ): void {
		$_POST = array_merge(
			array(
				'action'                                          => 'editpost',
				wcpt_key_to_str( Meetup_Admin::GROUP_URL_KEY, 'wcpt_' ) => $url,
			),
			$extra
		);

		$this->admin->metabox_save( $this->post_id, get_post( $this->post_id ), false );
	}

	/**
	 * @covers Meetup_Admin::update_group_site_link
	 */
	public function test_saving_a_group_url_links_the_group_site() {
		$this->save( 'https://events.wordpress.test/group/joinville/events/' );

		$this->assertSame( self::$group_site_id, (int) get_post_meta( $this->post_id, Meetup_Admin::GROUP_SITE_ID_KEY, true ) );
		$this->assertSame( self::$group_site_id, (int) Meetup_Admin::get_group_site( $this->post_id )->blog_id );

		$this->save( 'https://www.meetup.com/wp-joinville/' );

		$this->assertNull( Meetup_Admin::get_group_site( $this->post_id ), 'A URL that is not a group unlinks the record.' );
		$this->assertContains( 'invalid-group-url', $this->admin->active_admin_notices );

		$this->save( 'https://events.wordpress.test/group/' );

		$this->assertNull( Meetup_Admin::get_group_site( $this->post_id ), 'The groups root is not a group.' );
	}

	/**
	 * @covers Meetup_Admin::update_group_site_link
	 */
	public function test_sync_now_schedules_a_refresh_on_the_groups_root() {
		$this->save( 'https://events.wordpress.test/group/joinville/', array( 'refresh_group_stats' => 'on' ) );

		switch_to_blog( GROUPS_ROOT_BLOG_ID );
		$scheduled = wp_next_scheduled( Meetup_Admin::GROUP_REFRESH_HOOK, array( self::$group_site_id ) );
		restore_current_blog();

		$this->assertNotFalse( $scheduled );
		$this->assertFalse( wp_next_scheduled( Meetup_Admin::GROUP_REFRESH_HOOK, array( self::$group_site_id ) ), 'Not scheduled on Central.' );
	}

	/**
	 * @covers Meetup_Admin::render_group_data_metabox
	 */
	public function test_group_data_box_shows_the_stored_summary() {
		update_post_meta( $this->post_id, Meetup_Admin::GROUP_SITE_ID_KEY, self::$group_site_id );
		update_site_meta(
			self::$group_site_id,
			Meetup_Admin::GROUP_STATS_KEY,
			array(
				'version'               => 1,
				'synced_at'             => time(),
				'location'              => 'Joinville, Brazil',
				'members_count'         => 42,
				'organizers'            => array( 'ana', 'bruno' ),
				'past_events_count'     => 7,
				'last_event_at'         => '2026-10-01 22:00:00',
				'last_event_rsvps'      => 18,
				'next_event_at'         => '2026-01-01 22:00:00',
				'events_last_12_months' => 5,
				'average_rsvps'         => 12.5,
			)
		);

		ob_start();
		$this->admin->render_group_data_metabox( get_post( $this->post_id ) );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Joinville, Brazil', $html );
		$this->assertStringContainsString( 'ana, bruno', $html );
		$this->assertStringContainsString( '2026-10-01', $html );
		$this->assertStringContainsString( '12.5', $html );
		$this->assertStringNotContainsString( '2026-01-01', $html, 'A next event that has passed is not shown.' );
	}
}
