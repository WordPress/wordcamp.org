<?php

namespace WordCamp\Groups\Tests;

use function WordCamp\Groups\Archive\get_group_sites;
use function WordCamp\Groups\Archive\get_group_site_count;
use function WordCamp\Groups\Archive\update_group_archive_status;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__, 2 ) . '/wporg-groups-frontend/tests/class-groups-testcase.php';

/**
 * @group groups
 */
class Test_Group_Archive extends Groups_TestCase {
	/**
	 * @var int
	 */
	private $group_site_id;

	/**
	 * Create a real group subsite for each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->group_site_id = self::factory()->blog->create(
			array(
				'domain'     => 'events.wordpress.test',
				'path'       => '/group/archive-test/',
				'network_id' => GROUPS_NETWORK_ID,
			)
		);

		switch_to_blog( $this->group_site_id );
		\GatherPress\Core\Setup::get_instance()->check_plugin_version();
	}

	/**
	 * Remove the temporary group site and restore the parent fixture.
	 */
	protected function tearDown(): void {
		restore_current_blog();
		wp_delete_site( $this->group_site_id );

		parent::tearDown();
	}

	/**
	 * Active group queries omit both archived groups and the network root.
	 */
	public function test_active_group_query_excludes_archived_groups_and_network_root() {
		$active_group_ids = array_map( 'intval', wp_list_pluck( get_group_sites(), 'blog_id' ) );
		$all_group_ids    = array_map( 'intval', wp_list_pluck( get_group_sites( true ), 'blog_id' ) );

		$this->assertContains( $this->group_site_id, $active_group_ids );
		$this->assertNotContains( GROUPS_ROOT_BLOG_ID, $all_group_ids );

		$this->assertTrue( update_group_archive_status( $this->group_site_id, true ) );

		$active_group_ids = array_map( 'intval', wp_list_pluck( get_group_sites(), 'blog_id' ) );
		$all_group_ids    = array_map( 'intval', wp_list_pluck( get_group_sites( true ), 'blog_id' ) );

		$this->assertNotContains( $this->group_site_id, $active_group_ids );
		$this->assertContains( $this->group_site_id, $all_group_ids );
	}

	/**
	 * Group queries can return stable pages and an accurate total.
	 */
	public function test_group_query_can_be_paginated() {
		$second_group_site_id = self::factory()->blog->create(
			array(
				'domain'     => 'events.wordpress.test',
				'path'       => '/group/archive-test-second/',
				'network_id' => GROUPS_NETWORK_ID,
			)
		);

		$all_group_ids = array_map( 'intval', wp_list_pluck( get_group_sites( true ), 'blog_id' ) );
		$first_page    = get_group_sites( true, 1, 0 );
		$second_page   = get_group_sites( true, 1, 1 );

		$this->assertCount( count( $all_group_ids ), get_group_sites( true ) );
		$this->assertSame( count( $all_group_ids ), get_group_site_count( true ) );
		$this->assertCount( 1, $first_page );
		$this->assertCount( 1, $second_page );
		$this->assertNotSame( $first_page[0]->blog_id, $second_page[0]->blog_id );

		wp_delete_site( $second_group_site_id );
	}

	/**
	 * Archiving changes only site state; group records survive reactivation.
	 */
	public function test_archive_and_reactivate_preserve_group_records() {
		global $wpdb;

		$event_id  = self::factory()->post->create(
			array(
				'post_title'  => 'Preserved event',
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
			)
		);
		$member_id = self::factory()->user->create();
		add_user_to_blog( $this->group_site_id, $member_id, 'subscriber' );

		$rsvp_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $event_id,
				'user_id'              => $member_id,
				'comment_author'        => 'Group member',
				'comment_author_email' => 'member@example.org',
				'comment_content'       => 'RSVP',
				'comment_type'          => 'gatherpress_rsvp',
				'comment_approved'      => 1,
			)
		);

		$this->assertTrue( update_group_archive_status( $this->group_site_id, true ) );
		$this->assertSame( '1', get_site( $this->group_site_id )->archived );
		$this->assertSame( 'Preserved event', get_post( $event_id )->post_title );
		$this->assertSame( 'gatherpress_rsvp', get_comment( $rsvp_id )->comment_type );
		$this->assertArrayHasKey(
			'subscriber',
			get_user_meta( $member_id, $wpdb->get_blog_prefix( $this->group_site_id ) . 'capabilities', true )
		);

		$this->assertTrue( update_group_archive_status( $this->group_site_id, false ) );
		$this->assertSame( '0', get_site( $this->group_site_id )->archived );
		$this->assertSame( 'Preserved event', get_post( $event_id )->post_title );
		$this->assertSame( 'gatherpress_rsvp', get_comment( $rsvp_id )->comment_type );
		$this->assertTrue( is_user_member_of_blog( $member_id, $this->group_site_id ) );
	}

	/**
	 * Sites outside the Groups network cannot be changed through this tool.
	 */
	public function test_rejects_site_from_another_network() {
		$other_site_id = self::factory()->blog->create();
		$result        = update_group_archive_status( $other_site_id, true );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_group_site', $result->get_error_code() );

		wp_delete_site( $other_site_id );
	}

	/**
	 * The placeholder root site must remain available for network routing.
	 */
	public function test_rejects_groups_network_root() {
		$result = update_group_archive_status( GROUPS_ROOT_BLOG_ID, true );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_group_site', $result->get_error_code() );
	}
}
