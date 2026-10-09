<?php

namespace WordCamp\Tests;

use WP_UnitTestCase;
use WordCamp\SubRoles;

defined( 'WPINC' ) || die();

/**
 * Class Test_Omit_UserMeta_Caps
 *
 * @group mu-plugins
 * @group subroles
 *
 * @package WordCamp\Tests
 */
class Test_SubRoles extends Database_TestCase {
	/**
	 * Reset global state between tests, for isolation.
	 */
	public function set_up() {
		parent::set_up();

		global $wcorg_subroles;

		$wcorg_subroles = array();

		if ( ! defined( 'WCPT_POST_TYPE_ID' ) ) {
			define( 'WCPT_POST_TYPE_ID', 'wordcamp' );
		}

		if ( ! post_type_exists( WCPT_POST_TYPE_ID ) ) {
			register_post_type(
				WCPT_POST_TYPE_ID,
				array(
					'public'          => true,
					'capability_type' => WCPT_POST_TYPE_ID,
					'map_meta_cap'    => true,
				)
			);
		}
	}

	/**
	 * @covers \WordCamp\SubRoles\omit_usermeta_caps()
	 */
	public function test_user_with_additional_caps_cannot() {
		$user = self::factory()->user->create_and_get( array(
			'role' => 'subscriber',
		) );

		$user->add_cap( 'wordcamp_wrangle_wordcamps' );
		$usermeta = get_user_meta( $user->ID, 'wptests_capabilities', true );

		$this->assertTrue( $user->has_cap( 'read' ) );
		$this->assertTrue( $usermeta['wordcamp_wrangle_wordcamps'] );
		$this->assertFalse( $user->has_cap( 'wordcamp_wrangle_wordcamps' ) );
		$this->assertFalse( user_can( $user->ID, 'wordcamp_wrangle_wordcamps' ) );
	}

	/**
	 * @dataProvider data_user_with_subrole_can
	 *
	 * @covers \WordCamp\SubRoles\map_subrole_caps()
	 * @covers \WordCamp\SubRoles\add_subrole_caps()
	 * @covers \WordCamp\SubRoles\get_user_subroles()
	 */
	public function test_user_with_subrole_can( $subrole, $primitive_cap, $meta_cap ) {
		global $wcorg_subroles;

		// Some caps are only applied on Central.
		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );

		$user = self::factory()->user->create_and_get( array(
			'role' => 'subscriber',
		) );

		$this->assertTrue( $user->has_cap( 'read' ) );
		$this->assertFalse( $user->has_cap( $primitive_cap ) );
		$this->assertFalse( user_can( $user->ID, $meta_cap ) );

		$wcorg_subroles = array(
			$user->ID => array( $subrole ),
		);

		$this->assertTrue( $user->has_cap( 'read' ) );
		$this->assertTrue( $user->has_cap( $primitive_cap ) );
		$this->assertTrue( user_can( $user->ID, $meta_cap ) );

		restore_current_blog();
	}

	/**
	 * `campus_connect_viewer` exists to be narrower than `report_viewer`, so it must not
	 * grant the capability that opens every private report, nor anything off Central.
	 *
	 * @covers \WordCamp\SubRoles\add_subrole_caps()
	 */
	public function test_campus_connect_viewer_is_narrower_than_report_viewer() {
		global $wcorg_subroles;

		$user = self::factory()->user->create_and_get( array(
			'role' => 'subscriber',
		) );

		$wcorg_subroles = array(
			$user->ID => array( 'campus_connect_viewer' ),
		);

		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );

		$this->assertTrue( user_can( $user->ID, 'view_campus_connect_report' ) );
		$this->assertFalse( user_can( $user->ID, 'view_wordcamp_reports' ) );

		restore_current_blog();

		switch_to_blog( self::factory()->blog->create() );

		$this->assertFalse( user_can( $user->ID, 'view_campus_connect_report' ) );

		restore_current_blog();
	}

	/**
	 * @covers \WordCamp\SubRoles\map_subrole_caps()
	 */
	public function test_mentor_can_edit_their_wordcamp_post() {
		$mentor = self::factory()->user->create_and_get( array(
			'role'       => 'contributor',
			'user_login' => 'test_mentor',
		) );

		$post_id = self::factory()->post->create( array(
			'post_type' => WCPT_POST_TYPE_ID,
		) );

		$this->assertFalse( user_can( $mentor->ID, 'edit_post', $post_id ) );

		update_post_meta( $post_id, 'Mentor WordPress.org User Name', 'test_mentor' );

		$this->assertTrue( user_can( $mentor->ID, 'edit_post', $post_id ) );
	}

	/**
	 * @covers \WordCamp\SubRoles\map_subrole_caps()
	 */
	public function test_non_mentor_cannot_edit_wordcamp_post() {
		$user = self::factory()->user->create_and_get( array(
			'role'       => 'contributor',
			'user_login' => 'not_a_mentor',
		) );

		$post_id = self::factory()->post->create( array(
			'post_type' => WCPT_POST_TYPE_ID,
		) );
		update_post_meta( $post_id, 'Mentor WordPress.org User Name', 'actual_mentor' );

		$this->assertFalse( user_can( $user->ID, 'edit_post', $post_id ) );
	}

	/**
	 * @covers \WordCamp\SubRoles\map_subrole_caps()
	 */
	public function test_mentor_cannot_edit_wordcamp_post_they_dont_mentor() {
		$mentor = self::factory()->user->create_and_get( array(
			'role'       => 'contributor',
			'user_login' => 'test_mentor',
		) );

		$post_id = self::factory()->post->create( array(
			'post_type' => WCPT_POST_TYPE_ID,
		) );
		update_post_meta( $post_id, 'Mentor WordPress.org User Name', 'different_mentor' );

		$this->assertFalse( user_can( $mentor->ID, 'edit_post', $post_id ) );
	}

	/**
	 * @covers \WordCamp\SubRoles\map_subrole_caps()
	 */
	public function test_mentor_cannot_edit_wordcamp_post_without_mentor_meta() {
		$user = self::factory()->user->create_and_get( array(
			'role'       => 'contributor',
			'user_login' => 'test_mentor',
		) );

		$post_id = self::factory()->post->create( array(
			'post_type' => WCPT_POST_TYPE_ID,
		) );
		// No mentor meta set.

		$this->assertFalse( user_can( $user->ID, 'edit_post', $post_id ) );
	}

	/**
	 * @covers \WordCamp\SubRoles\map_subrole_caps()
	 */
	public function test_mentor_without_contributor_role_cannot_edit() {
		$mentor = self::factory()->user->create_and_get( array(
			'role'       => 'subscriber',
			'user_login' => 'test_mentor',
		) );

		$post_id = self::factory()->post->create( array(
			'post_type' => WCPT_POST_TYPE_ID,
		) );
		update_post_meta( $post_id, 'Mentor WordPress.org User Name', 'test_mentor' );

		// Subscribers don't have `edit_posts`, so even though the mentor mapping
		// returns `edit_posts` as the required cap, a subscriber won't have it.
		$this->assertFalse( user_can( $mentor->ID, 'edit_post', $post_id ) );
	}

	/**
	 * Define test cases for test_user_with_subrole_can().
	 */
	public function data_user_with_subrole_can() : array {
		return array(
			'wordcamp_wrangler' => array(
				'subrole'       => 'wordcamp_wrangler',
				'primitive_cap' => 'wordcamp_wrangle_wordcamps',
				'meta_cap'      => 'edit_others_wordcamps',
			),

			'mentor_manager' => array(
				'subrole'       => 'mentor_manager',
				'primitive_cap' => 'wordcamp_manage_mentors',
				'meta_cap'      => 'wordcamp_manage_mentors',
			),

			'report_viewer' => array(
				'subrole'       => 'report_viewer',
				'primitive_cap' => 'view_wordcamp_reports',
				'meta_cap'      => 'view_wordcamp_reports',
			),

			'campus_connect_viewer' => array(
				'subrole'       => 'campus_connect_viewer',
				'primitive_cap' => 'view_campus_connect_report',
				'meta_cap'      => 'view_campus_connect_report',
			),
		);
	}

	/**
	 * Create a camp authored by someone else, mentored by a contributor who is the current user.
	 *
	 * @return array [ $mentor_id, $author_id, $post_id ]
	 */
	protected function become_mentor_of_someone_elses_camp() {
		$author = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$mentor = self::factory()->user->create( array(
			'role'       => 'contributor',
			'user_login' => 'test_mentor',
		) );

		$post_id = self::factory()->post->create( array(
			'post_type'   => WCPT_POST_TYPE_ID,
			'post_author' => $author,
		) );

		update_post_meta( $post_id, 'Mentor WordPress.org User Name', 'test_mentor' );
		wp_set_current_user( $mentor );

		return array( $mentor, $author, $post_id );
	}

	/**
	 * Run the save through core's `_wp_translate_postdata()`, the way post.php does for an Update.
	 *
	 * The Author box posts the camp's author, who isn't the mentor, so core asks for
	 * `edit_others_wordcamps` without naming the post. The post ID is in the request, as it is in post.php.
	 *
	 * @param int $post_id
	 * @param int $author_id
	 *
	 * @return array|\WP_Error
	 */
	protected function translate_update( $post_id, $author_id ) {
		require_once ABSPATH . 'wp-admin/includes/post.php';

		// post.php hands `_wp_translate_postdata()` a copy of `$_POST`, so both carry the same fields.
		$_POST['post_ID']              = (string) $post_id;
		$_POST['post_author_override'] = (string) $author_id;

		$result = _wp_translate_postdata(
			true,
			array(
				'post_ID'              => (string) $post_id,
				'post_type'            => WCPT_POST_TYPE_ID,
				'post_author_override' => (string) $author_id,
				'post_title'           => 'WordCamp Test',
			)
		);

		unset( $_POST['post_ID'], $_POST['post_author_override'] );

		return $result;
	}

	/**
	 * A mentor can save their mentee's camp even though its author is someone else.
	 *
	 * @covers \WordCamp\SubRoles\map_subrole_caps()
	 * @ticket 2159
	 */
	public function test_mentor_can_save_their_mentees_camp() {
		list( , $author, $post_id ) = $this->become_mentor_of_someone_elses_camp();

		$result = $this->translate_update( $post_id, $author );

		$this->assertNotWPError( $result );
		$this->assertSame( $author, $result['post_author'] );
	}

	/**
	 * The same save on a camp the user doesn't mentor is still refused.
	 *
	 * @covers \WordCamp\SubRoles\map_subrole_caps()
	 * @ticket 2159
	 */
	public function test_mentor_cannot_save_a_camp_they_do_not_mentor() {
		list( , $author ) = $this->become_mentor_of_someone_elses_camp();

		$other = self::factory()->post->create( array(
			'post_type'   => WCPT_POST_TYPE_ID,
			'post_author' => $author,
		) );
		update_post_meta( $other, 'Mentor WordPress.org User Name', 'different_mentor' );

		$result = $this->translate_update( $other, $author );

		$this->assertWPError( $result );
		$this->assertSame( 'edit_others_posts', $result->get_error_code() );
	}

	/**
	 * The cap is scoped to the request for the mentee's camp: it isn't granted outright.
	 *
	 * @covers \WordCamp\SubRoles\map_subrole_caps()
	 * @ticket 2159
	 */
	public function test_mentor_does_not_get_edit_others_wordcamps_outright() {
		list( $mentor, , $post_id ) = $this->become_mentor_of_someone_elses_camp();

		$this->assertFalse( user_can( $mentor, 'edit_others_wordcamps' ) );

		$_POST['post_ID'] = (string) $post_id;
		$this->assertTrue( user_can( $mentor, 'edit_others_wordcamps' ) );
		unset( $_POST['post_ID'] );

		$this->assertFalse( user_can( $mentor, 'edit_others_wordcamps' ) );
	}

	/**
	 * Saving the mentee's camp doesn't open other camps: `edit_post` on another camp stays refused.
	 *
	 * @covers \WordCamp\SubRoles\map_subrole_caps()
	 * @ticket 2159
	 */
	public function test_mentee_save_request_does_not_open_other_camps() {
		list( $mentor, $author, $post_id ) = $this->become_mentor_of_someone_elses_camp();

		$other = self::factory()->post->create( array(
			'post_type'   => WCPT_POST_TYPE_ID,
			'post_author' => $author,
		) );

		$_POST['post_ID'] = (string) $post_id;
		$this->assertFalse( user_can( $mentor, 'edit_post', $other ) );
		unset( $_POST['post_ID'] );
	}

	/**
	 * Saving with a different author is a change of author, which the mentor can't make.
	 *
	 * @covers \WordCamp\SubRoles\map_subrole_caps()
	 * @ticket 2159
	 */
	public function test_mentor_cannot_change_the_camps_author() {
		list( , , $post_id ) = $this->become_mentor_of_someone_elses_camp();

		$someone_else = self::factory()->user->create( array( 'role' => 'contributor' ) );

		$result = $this->translate_update( $post_id, $someone_else );

		$this->assertWPError( $result );
		$this->assertSame( 'edit_others_posts', $result->get_error_code() );
	}

	/**
	 * A wrangler still edits anyone's camp and can change its author, through the type's own capability.
	 *
	 * @covers \WordCamp\SubRoles\map_subrole_caps()
	 * @ticket 2159
	 */
	public function test_wrangler_can_still_save_anyones_camp_with_a_new_author() {
		global $wcorg_subroles;

		list( , , $post_id ) = $this->become_mentor_of_someone_elses_camp();

		$wrangler        = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$wcorg_subroles  = array( $wrangler => array( 'wordcamp_wrangler' ) );
		$someone_else    = self::factory()->user->create( array( 'role' => 'contributor' ) );
		wp_set_current_user( $wrangler );

		$this->assertSame( 'edit_others_wordcamps', get_post_type_object( WCPT_POST_TYPE_ID )->cap->edit_others_posts );
		$this->assertTrue( user_can( $wrangler, 'edit_others_wordcamps' ) );
		$this->assertNotWPError( $this->translate_update( $post_id, $someone_else ) );
	}

	/**
	 * A contributor who mentors nothing gets nothing from a post ID in the request.
	 *
	 * @covers \WordCamp\SubRoles\map_subrole_caps()
	 * @ticket 2159
	 */
	public function test_non_mentor_gets_nothing_from_a_post_id_in_the_request() {
		list( , $author, $post_id ) = $this->become_mentor_of_someone_elses_camp();

		$someone = self::factory()->user->create( array( 'role' => 'contributor' ) );
		wp_set_current_user( $someone );

		$_POST['post_ID'] = (string) $post_id;
		$this->assertFalse( user_can( $someone, 'edit_others_wordcamps' ) );
		unset( $_POST['post_ID'] );

		$result = $this->translate_update( $post_id, $author );
		$this->assertWPError( $result );
	}
}
