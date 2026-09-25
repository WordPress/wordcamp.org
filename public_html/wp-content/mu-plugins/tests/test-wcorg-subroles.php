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
		);
	}
}
