<?php

namespace WordCamp\WCPT\Tests;

use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * Tests for adding a Campus Connect organizer to Central at Needs Orientation.
 *
 * Bridged applications are owned by the `wordcamp` service account, and the event list only shows
 * people the applications they author or mentor. Regular camps add their lead organizer to Central on
 * `wcpt_approved_for_pre_planning`, which a Campus Connect application never fires (#1714).
 *
 * @group wcpt
 * @group campus-connect
 */
class Test_Campus_Connect_Onboarding extends WP_UnitTestCase {
	/**
	 * The service account that owns bridged applications.
	 *
	 * @var int
	 */
	protected $service_account;

	/**
	 * The organizer named on the application, with no role on Central yet.
	 *
	 * @var \WP_User
	 */
	protected $organizer;

	/**
	 * Act as a wrangler, and create the organizer as a network user with no role here.
	 */
	public function set_up() {
		parent::set_up();

		global $wcorg_subroles;

		$wcorg_subroles = array();

		$wrangler = self::factory()->user->create();
		grant_super_admin( $wrangler );
		wp_set_current_user( $wrangler );

		$this->service_account = self::factory()->user->create( array( 'user_login' => 'wordcamp-service' ) );
		$this->organizer       = self::factory()->user->create_and_get( array( 'user_login' => 'ccorganizer' ) );

		remove_user_from_blog( $this->organizer->ID, get_current_blog_id() );
	}

	/**
	 * Clean up the request and the global.
	 */
	public function tear_down() {
		global $wcorg_subroles;

		$wcorg_subroles = array();
		unset( $_POST['wcpt_wordpress_org_username'] );

		parent::tear_down();
	}

	/**
	 * Create an application owned by the service account.
	 *
	 * @param string $status
	 * @param bool   $campus_connect
	 * @param string $username Stored `WordPress.org Username`.
	 *
	 * @return int
	 */
	protected function create_application( $status, $campus_connect = true, $username = 'ccorganizer' ) {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => WCPT_POST_TYPE_ID,
				'post_status' => $status,
				'post_author' => $this->service_account,
			)
		);

		if ( $campus_connect ) {
			update_post_meta( $post_id, 'event_subtype', 'campusconnect' );
		}

		update_post_meta( $post_id, 'WordPress.org Username', $username );

		return $post_id;
	}

	/**
	 * Move an application to a status, the way a save does.
	 *
	 * @param int    $post_id
	 * @param string $status
	 */
	protected function move( $post_id, $status ) {
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => $status,
			)
		);
	}

	/**
	 * At Needs Orientation the organizer joins Central and owns the application.
	 *
	 * @covers WordCamp_Admin::add_campus_connect_organizer_to_central
	 */
	public function test_needs_orientation_adds_organizer_and_makes_them_author() {
		$post_id = $this->create_application( 'wcpt-needs-action' );

		$this->move( $post_id, 'wcpt-needs-orientati' );

		$this->assertTrue( is_user_member_of_blog( $this->organizer->ID, get_current_blog_id() ) );
		$this->assertSame( array( 'contributor' ), get_userdata( $this->organizer->ID )->roles );
		$this->assertSame( $this->organizer->ID, (int) get_post( $post_id )->post_author );
	}

	/**
	 * The author change is in the database, not only in the object cache.
	 *
	 * @covers WordCamp_Admin::add_campus_connect_organizer_to_central
	 */
	public function test_author_is_stored() {
		global $wpdb;

		$post_id = $this->create_application( 'wcpt-needs-action' );

		$this->move( $post_id, 'wcpt-needs-orientati' );

		$stored = $wpdb->get_var( $wpdb->prepare( "SELECT post_author FROM $wpdb->posts WHERE ID = %d", $post_id ) );

		$this->assertSame( $this->organizer->ID, (int) $stored );
	}

	/**
	 * The username on the edit screen wins over the stored one.
	 *
	 * The status change runs before the screen's meta is saved, so a wrangler who corrects the
	 * username in the same save would otherwise onboard the old one.
	 *
	 * @covers WordCamp_Admin::add_campus_connect_organizer_to_central
	 */
	public function test_username_from_the_edit_screen_wins() {
		$post_id = $this->create_application( 'wcpt-needs-action', true, 'someoneelse' );

		$_POST['wcpt_wordpress_org_username'] = 'ccorganizer';

		$this->move( $post_id, 'wcpt-needs-orientati' );

		$this->assertSame( $this->organizer->ID, (int) get_post( $post_id )->post_author );
	}

	/**
	 * An organizer who already has a role on Central keeps it.
	 *
	 * `add_user_to_blog()` replaces the role, which would demote an editor to contributor.
	 *
	 * @covers WordCamp_Admin::add_campus_connect_organizer_to_central
	 */
	public function test_existing_role_is_kept() {
		add_user_to_blog( get_current_blog_id(), $this->organizer->ID, 'editor' );

		$post_id = $this->create_application( 'wcpt-needs-action' );

		$this->move( $post_id, 'wcpt-needs-orientati' );

		$this->assertSame( array( 'editor' ), get_userdata( $this->organizer->ID )->roles );
		$this->assertSame( $this->organizer->ID, (int) get_post( $post_id )->post_author );
	}

	/**
	 * With no matching account, nothing changes and the log says why.
	 *
	 * @covers WordCamp_Admin::add_campus_connect_organizer_to_central
	 */
	public function test_unknown_username_changes_nothing_and_leaves_a_note() {
		$post_id = $this->create_application( 'wcpt-needs-action', true, 'nobody-by-this-name' );

		$this->move( $post_id, 'wcpt-needs-orientati' );

		$this->assertSame( $this->service_account, (int) get_post( $post_id )->post_author );

		$notes = wp_list_pluck( get_post_meta( $post_id, '_note' ), 'message' );

		$this->assertContains(
			'Could not add the organizer to Central: no WordPress.org account is named "nobody-by-this-name".',
			$notes
		);
	}

	/**
	 * With no username at all, nothing changes.
	 *
	 * @covers WordCamp_Admin::add_campus_connect_organizer_to_central
	 */
	public function test_empty_username_changes_nothing() {
		$post_id = $this->create_application( 'wcpt-needs-action', true, '' );

		$this->move( $post_id, 'wcpt-needs-orientati' );

		$this->assertSame( $this->service_account, (int) get_post( $post_id )->post_author );
		$this->assertFalse( is_user_member_of_blog( $this->organizer->ID, get_current_blog_id() ) );
	}

	/**
	 * A successful add is recorded in the log.
	 *
	 * @covers WordCamp_Admin::add_campus_connect_organizer_to_central
	 */
	public function test_success_leaves_a_note() {
		$post_id = $this->create_application( 'wcpt-needs-action' );

		$this->move( $post_id, 'wcpt-needs-orientati' );

		$notes = wp_list_pluck( get_post_meta( $post_id, '_note' ), 'message' );

		$this->assertContains( 'Added ccorganizer to Central and made them the author of this application.', $notes );
	}

	/**
	 * A regular camp moving to the same status is left alone.
	 *
	 * Its organizer is added at In Pre-Planning, by `add_organizer_to_central()`.
	 *
	 * @covers WordCamp_Admin::add_campus_connect_organizer_to_central
	 */
	public function test_regular_camp_is_not_onboarded_at_needs_orientation() {
		$post_id = $this->create_application( 'wcpt-needs-vetting', false );

		$this->move( $post_id, 'wcpt-needs-orientati' );

		$this->assertSame( $this->service_account, (int) get_post( $post_id )->post_author );
		$this->assertFalse( is_user_member_of_blog( $this->organizer->ID, get_current_blog_id() ) );
	}

	/**
	 * Other Campus Connect statuses don't onboard anyone.
	 *
	 * @covers WordCamp_Admin::add_campus_connect_organizer_to_central
	 */
	public function test_other_campus_connect_statuses_do_not_onboard() {
		$post_id = $this->create_application( 'wcpt-needs-vetting' );

		$this->move( $post_id, 'wcpt-needs-action' );
		$this->move( $post_id, 'wcpt-more-info-reque' );

		$this->assertSame( $this->service_account, (int) get_post( $post_id )->post_author );
		$this->assertFalse( is_user_member_of_blog( $this->organizer->ID, get_current_blog_id() ) );
	}

	/**
	 * The "added to Central" reminder isn't sent: its template is written for regular camps, and
	 * a Campus Connect organizer gets the Needs Orientation email at this step.
	 *
	 * @covers WordCamp_Admin::add_campus_connect_organizer_to_central
	 */
	public function test_does_not_fire_the_added_to_central_reminder() {
		$before  = did_action( 'wcor_organizer_added_to_central' );
		$post_id = $this->create_application( 'wcpt-needs-action' );

		$this->move( $post_id, 'wcpt-needs-orientati' );

		$this->assertSame( $before, did_action( 'wcor_organizer_added_to_central' ) );
	}
}
