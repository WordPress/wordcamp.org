<?php

namespace WordCamp\Groups\Tests;

use WP_REST_Request;
use WP_User_Query;
use WordCamp\Groups\Frontend\Member_Joined;
use WordCamp\Groups\Frontend\Members\Members_Controller;

use function WordCamp\Groups\Frontend\REST\save_rsvp;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * @group groups
 */
class Test_Groups_Member_Joined extends Groups_TestCase {

	/**
	 * A user who isn't in the group yet.
	 */
	private function outsider(): int {
		$user_id = self::factory()->user->create();
		remove_user_from_blog( $user_id, self::$groups_root_site_id );

		return $user_id;
	}

	/**
	 * The Join button records the date and that they used it.
	 */
	public function test_join_button_records_join() {
		$user_id = $this->outsider();
		wp_set_current_user( $user_id );

		$before = time();
		( new Members_Controller() )->join_group( new WP_REST_Request( 'POST', '/wporg-groups/v1/members/join' ) );

		$join = Member_Joined\get_join( $user_id );

		$this->assertNotNull( $join );
		$this->assertGreaterThanOrEqual( $before, $join['time'] );
		$this->assertLessThanOrEqual( time(), $join['time'] );
		$this->assertSame( Member_Joined\SOURCE_JOIN, $join['source'] );
	}

	/**
	 * RSVPing joins the group too, and says so.
	 */
	public function test_rsvp_records_join() {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
			)
		);
		( new \GatherPress\Core\Event\Event( $event_id ) )->save_datetimes(
			array(
				'post_id'        => $event_id,
				'datetime_start' => gmdate( 'Y-m-d H:i:s', strtotime( '+30 days' ) ),
				'datetime_end'   => gmdate( 'Y-m-d H:i:s', strtotime( '+30 days +2 hours' ) ),
				'timezone'       => 'UTC',
			)
		);

		$user_id = $this->outsider();
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', "/wporg-groups/v1/event/{$event_id}/rsvp" );
		$request->set_param( 'id', $event_id );
		$request->set_param( 'status', 'attending' );
		save_rsvp( $request );

		$this->assertTrue( is_user_member_of_blog( $user_id, self::$groups_root_site_id ) );
		$this->assertSame( Member_Joined\SOURCE_RSVP, Member_Joined\get_join( $user_id )['source'] );
	}

	/**
	 * Anything else, like wp-admin's "Add Existing User", counts as added.
	 */
	public function test_other_adds_record_added() {
		$user_id = $this->outsider();

		add_user_to_blog( self::$groups_root_site_id, $user_id, 'subscriber' );

		$this->assertSame( Member_Joined\SOURCE_ADDED, Member_Joined\get_join( $user_id )['source'] );
	}

	/**
	 * The source only applies inside `with_source()`.
	 */
	public function test_source_does_not_leak_past_callback() {
		Member_Joined\with_source( Member_Joined\SOURCE_RSVP, '__return_true' );

		$user_id = $this->outsider();
		add_user_to_blog( self::$groups_root_site_id, $user_id, 'subscriber' );

		$this->assertSame( Member_Joined\SOURCE_ADDED, Member_Joined\get_join( $user_id )['source'] );
	}

	/**
	 * Leaving forgets the date, so rejoining records a new one.
	 */
	public function test_leaving_and_rejoining_records_new_join() {
		$user_id = $this->outsider();

		add_user_to_blog( self::$groups_root_site_id, $user_id, 'subscriber' );
		update_user_meta( $user_id, Member_Joined\meta_key( Member_Joined\JOINED_META ), 1000 );

		remove_user_from_blog( $user_id, self::$groups_root_site_id );
		$this->assertNull( Member_Joined\get_join( $user_id ) );

		Member_Joined\with_source(
			Member_Joined\SOURCE_JOIN,
			static function () use ( $user_id ) {
				add_user_to_blog( self::$groups_root_site_id, $user_id, 'subscriber' );
			}
		);

		$join = Member_Joined\get_join( $user_id );
		$this->assertGreaterThan( 1000, $join['time'] );
		$this->assertSame( Member_Joined\SOURCE_JOIN, $join['source'] );
	}

	/**
	 * Adding someone who's already a member again (to change their role,
	 * say) keeps the date they joined.
	 */
	public function test_readding_member_keeps_join_date() {
		$user_id = $this->outsider();

		add_user_to_blog( self::$groups_root_site_id, $user_id, 'subscriber' );
		update_user_meta( $user_id, Member_Joined\meta_key( Member_Joined\JOINED_META ), 1000 );

		add_user_to_blog( self::$groups_root_site_id, $user_id, 'editor' );

		$this->assertSame( 1000, Member_Joined\get_join( $user_id )['time'] );
	}

	/**
	 * Dates are per group: joining one says nothing about another.
	 */
	public function test_join_is_per_site() {
		$other_site = self::factory()->blog->create( array( 'network_id' => GROUPS_NETWORK_ID ) );
		$user_id    = $this->outsider();

		add_user_to_blog( $other_site, $user_id, 'subscriber' );

		$this->assertNull( Member_Joined\get_join( $user_id ) );

		switch_to_blog( $other_site );
		$this->assertNotNull( Member_Joined\get_join( $user_id ) );
		restore_current_blog();

		wp_delete_site( $other_site );
	}

	/**
	 * The line organizers see, per source.
	 */
	public function test_describe_join() {
		$user_id = $this->outsider();
		add_user_to_blog( self::$groups_root_site_id, $user_id, 'subscriber' );

		$key  = Member_Joined\meta_key( Member_Joined\SOURCE_META );
		$date = wp_date( get_option( 'date_format' ), Member_Joined\get_join( $user_id )['time'] );

		$expected = array(
			Member_Joined\SOURCE_JOIN   => "Joined $date",
			Member_Joined\SOURCE_RSVP   => "Joined $date by RSVPing",
			Member_Joined\SOURCE_IMPORT => "Imported from Meetup $date",
			Member_Joined\SOURCE_ADDED  => "Added $date",
		);

		foreach ( $expected as $source => $line ) {
			update_user_meta( $user_id, $key, $source );
			$this->assertSame( $line, Member_Joined\describe_join( $user_id ), $source );
		}
	}

	/**
	 * Members from before dates were recorded have none, unless the Meetup
	 * import brought them in.
	 */
	public function test_describe_join_without_date() {
		$user_id = self::factory()->user->create();
		delete_user_meta( $user_id, Member_Joined\meta_key( Member_Joined\JOINED_META ) );

		$this->assertSame( '', Member_Joined\describe_join( $user_id ) );

		if ( ! defined( '\WordCamp\Groups\Meetup_Import\IMPORTED_USERS_OPTION' ) ) {
			$this->markTestIncomplete( 'The Meetup import is not loaded in this suite.' );
		}

		update_option( \WordCamp\Groups\Meetup_Import\IMPORTED_USERS_OPTION, array( $user_id ) );
		$this->assertSame( 'From the Meetup import', Member_Joined\describe_join( $user_id ) );
	}

	/**
	 * Organizers see when members joined on the Members page; members don't.
	 */
	public function test_members_block_shows_joined_to_organizers_only() {
		$member_id = $this->outsider();
		Member_Joined\with_source(
			Member_Joined\SOURCE_RSVP,
			static function () use ( $member_id ) {
				add_user_to_blog( self::$groups_root_site_id, $member_id, 'subscriber' );
			}
		);

		wp_set_current_user( $member_id );
		$this->assertStringNotContainsString( 'wporg-group-members__joined', do_blocks( '<!-- wp:wporg/group-members /-->' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$output = do_blocks( '<!-- wp:wporg/group-members /-->' );

		$this->assertStringContainsString( 'wporg-group-members__joined', $output );
		$this->assertStringContainsString( 'by RSVPing', $output );
	}

	/**
	 * The wp-admin Users list sorts by join date, and still lists members
	 * without one.
	 */
	public function test_users_sort_by_joined_keeps_undated_members() {
		$key   = Member_Joined\meta_key( Member_Joined\JOINED_META );
		$early = self::factory()->user->create();
		$late  = self::factory()->user->create();
		$none  = self::factory()->user->create();

		update_user_meta( $early, $key, 1000 );
		update_user_meta( $late, $key, 2000 );
		delete_user_meta( $none, $key );

		$query = new WP_User_Query(
			array(
				'blog_id' => self::$groups_root_site_id,
				'include' => array( $none, $late, $early ),
				'orderby' => Member_Joined\ORDERBY,
				'order'   => 'DESC',
				'fields'  => 'ID',
			)
		);

		$ids = array_map( 'intval', $query->get_results() );

		$this->assertCount( 3, $ids );
		$this->assertLessThan( array_search( $early, $ids, true ), array_search( $late, $ids, true ) );
		$this->assertContains( $none, $ids );
	}

	/**
	 * The column is on a group's Users screen.
	 */
	public function test_users_column_on_group_sites() {
		$this->assertArrayHasKey( Member_Joined\ORDERBY, Member_Joined\add_users_column( array() ) );
	}
}
