<?php
/**
 * Tests for who can open the registry.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting\Tests;

use WordCamp\Tests\Database_TestCase;
use function WordCamp\Sponsor_Vetting\register_types;
use const WordCamp\Sponsor_Vetting\COMPANY_POST_TYPE;
use const WordCamp\Sponsor_Vetting\SPONSORSHIP_POST_TYPE;

defined( 'WPINC' ) || die();

/**
 * Extends `Database_TestCase` for its Central site: the vetter subrole only applies there.
 *
 * @group sponsor-vetting
 */
class Test_Permissions extends Database_TestCase {
	/**
	 * Registers the post types, as `init` does on Central.
	 */
	public function set_up(): void {
		parent::set_up();

		register_types();
	}

	/**
	 * Clears the subroles the test gave out.
	 */
	public function tear_down(): void {
		$GLOBALS['wcorg_subroles'] = array();

		parent::tear_down();
	}

	/**
	 * Only sponsor vetters can open companies and sponsorships; wranglers, who hold far broader access, can't.
	 *
	 * @dataProvider data_subroles
	 *
	 * @param string $subrole The user's subrole.
	 * @param bool   $can     Whether they may open the registry.
	 */
	public function test_only_sponsor_vetters_can_open_records( string $subrole, bool $can ): void {
		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );

		$company_id     = self::factory()->post->create(
			array(
				'post_type'   => COMPANY_POST_TYPE,
				'post_status' => 'sv-needs-vetting',
			)
		);
		$sponsorship_id = self::factory()->post->create(
			array(
				'post_type'   => SPONSORSHIP_POST_TYPE,
				'post_status' => 'svs-pending',
				'post_parent' => $company_id,
			)
		);
		$user_id        = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$GLOBALS['wcorg_subroles'] = array( $user_id => array( $subrole ) );

		$this->assertSame( $can, user_can( $user_id, 'edit_post', $company_id ) );
		$this->assertSame( $can, user_can( $user_id, 'read_post', $sponsorship_id ) );
		$this->assertSame( $can, user_can( $user_id, 'edit_post', $sponsorship_id ) );

		restore_current_blog();
	}

	/**
	 * Subroles and whether they open the registry.
	 *
	 * @return array[]
	 */
	public function data_subroles(): array {
		return array(
			'sponsor vetter'    => array( 'sponsor_vetter', true ),
			'wordcamp wrangler' => array( 'wordcamp_wrangler', false ),
			'mentor manager'    => array( 'mentor_manager', false ),
		);
	}
}
