<?php

namespace WordCamp\WordCampUser\Tests;

use WP_UnitTestCase;
use function WordCamp\WordCampUser\{
	get_user_id,
	protect_user,
	suppress_note_notifications_for_system_user,
	USER_LOGIN
};

defined( 'WPINC' ) || die();

/**
 * @group mu-plugins
 * @group wordcamp-user
 */
class Test_WordCamp_User extends WP_UnitTestCase {
	protected static $wordcamp_user_id;
	protected static $admin_user_id;
	protected static $super_admin_id;

	public static function wpSetUpBeforeClass( $factory ): void {
		self::$wordcamp_user_id = $factory->user->create(
			array(
				'user_login' => USER_LOGIN,
				'user_email' => 'support@wordcamp.org',
				'role'       => 'administrator',
			)
		);

		self::$admin_user_id = $factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		self::$super_admin_id = $factory->user->create(
			array(
				'role' => 'administrator',
			)
		);
		grant_super_admin( self::$super_admin_id );
	}

	/**
	 * @covers \WordCamp\WordCampUser\protect_user()
	 */
	public function test_protect_user_blocks_non_super_admin() {
		$this->assertSame(
			array( 'do_not_allow' ),
			protect_user( array(), 'remove_user', self::$admin_user_id, array( self::$wordcamp_user_id ) )
		);

		$this->assertSame(
			array( 'do_not_allow' ),
			protect_user( array(), 'promote_user', self::$admin_user_id, array( self::$wordcamp_user_id ) )
		);
	}

	/**
	 * @covers \WordCamp\WordCampUser\protect_user()
	 */
	public function test_protect_user_allows_super_admin() {
		$this->assertSame(
			array( 'remove_users' ),
			protect_user( array( 'remove_users' ), 'remove_user', self::$super_admin_id, array( self::$wordcamp_user_id ) )
		);
	}

	/**
	 * @covers \WordCamp\WordCampUser\suppress_note_notifications_for_system_user()
	 */
	public function test_suppress_note_notifications_for_system_user_post() {
		$post_id = self::factory()->post->create(
			array(
				'post_author' => self::$wordcamp_user_id,
				'post_type'   => 'page',
			)
		);

		$note_id = self::factory()->comment->create(
			array(
				'comment_post_ID' => $post_id,
				'comment_type'    => 'note',
				'comment_content' => 'Test note on block',
			)
		);

		// Notes on posts authored by the system user should have notifications suppressed.
		$this->assertFalse(
			suppress_note_notifications_for_system_user( true, $note_id )
		);
	}

	/**
	 * @covers \WordCamp\WordCampUser\suppress_note_notifications_for_system_user()
	 */
	public function test_do_not_suppress_note_notifications_for_regular_user_post() {
		$post_id = self::factory()->post->create(
			array(
				'post_author' => self::$admin_user_id,
				'post_type'   => 'page',
			)
		);

		$note_id = self::factory()->comment->create(
			array(
				'comment_post_ID' => $post_id,
				'comment_type'    => 'note',
				'comment_content' => 'Test note on regular post',
			)
		);

		// Notes on posts authored by regular users should not be suppressed.
		$this->assertTrue(
			suppress_note_notifications_for_system_user( true, $note_id )
		);
	}

	/**
	 * @covers \WordCamp\WordCampUser\suppress_note_notifications_for_system_user()
	 */
	public function test_do_not_suppress_non_note_comments() {
		$post_id = self::factory()->post->create(
			array(
				'post_author' => self::$wordcamp_user_id,
				'post_type'   => 'post',
			)
		);

		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID' => $post_id,
				'comment_type'    => 'comment',
				'comment_content' => 'Regular comment',
			)
		);

		// Regular comments should not be modified by this filter.
		$this->assertTrue(
			suppress_note_notifications_for_system_user( true, $comment_id )
		);
	}
}
