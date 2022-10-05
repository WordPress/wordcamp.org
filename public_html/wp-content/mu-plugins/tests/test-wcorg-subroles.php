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
class Test_Omit_UserMeta_Caps extends WP_UnitTestCase {
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
	public function test_user_with_subrole_can( $subrole, $primitive_cap, $meta_cap) {
		global $wcorg_subroles;

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
	}

	function data_user_with_subrole_can() {
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
		);
	}
}
