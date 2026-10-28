<?php
/**
 * Tests for who can see applications.
 *
 * @package WordCamp\Open_Horizons
 */

declare( strict_types = 1 );

namespace WordCamp\Open_Horizons\Tests;

use WordCamp\Tests\Database_TestCase;
use const WordCamp\Open_Horizons\POST_TYPE;

defined( 'WPINC' ) || die();

/**
 * Extends `Database_TestCase` for its Central site: the reviewer subrole only applies there.
 *
 * @group open-horizons
 */
class Test_Permissions extends Database_TestCase {
	/**
	 * Clears the subroles the test gave out.
	 */
	public function tear_down(): void {
		$GLOBALS['wcorg_subroles'] = array();

		parent::tear_down();
	}

	/**
	 * Only scholarship reviewers can open an application; wranglers can't, since applicants are often wranglers
	 * themselves.
	 *
	 * @dataProvider data_subroles
	 *
	 * @param string $subrole  The user's subrole.
	 * @param bool   $can_read Whether they may open applications.
	 */
	public function test_only_scholarship_reviewers_can_open_applications( string $subrole, bool $can_read ): void {
		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => POST_TYPE,
				'post_status' => 'oh-received',
			)
		);
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$GLOBALS['wcorg_subroles'] = array( $user_id => array( $subrole ) );

		$this->assertSame( $can_read, user_can( $user_id, 'edit_post', $post_id ) );
		$this->assertSame( $can_read, user_can( $user_id, 'read_post', $post_id ) );

		restore_current_blog();
	}

	/**
	 * Subroles and whether they open applications.
	 *
	 * @return array[]
	 */
	public function data_subroles(): array {
		return array(
			'scholarship reviewer' => array( 'scholarship_reviewer', true ),
			'wordcamp wrangler'    => array( 'wordcamp_wrangler', false ),
			'mentor manager'       => array( 'mentor_manager', false ),
		);
	}
}
