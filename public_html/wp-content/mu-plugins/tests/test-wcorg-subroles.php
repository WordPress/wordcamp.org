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
	 * @covers ::omit_usermeta_caps()
	 */
	public function test_user_with_additional_caps_cannot() {
		$user = self::factory()->user->create_and_get( array(
			'role' => 'subscriber',
		) );

		$user->add_cap( 'wordcamp_wrangle_wordcamps' );
		$usermeta = get_user_meta( $user->ID, 'wptests_capabilities', true );

		$this->assertTrue( $user->has_cap( 'read' ) );
		$this->assertFalse( $user->has_cap( 'wordcamp_wrangle_wordcamps' ) );
		$this->assertTrue( array_key_exists( 'wordcamp_wrangle_wordcamps', $usermeta ) );
	}

	/**
	 * @covers ::omit_usermeta_caps()
	 */
	public function test_user_with_subrole_can() {
		$user = self::factory()->user->create_and_get( array(
			'role' => 'subscriber',
		) );

		global $wcorg_subroles;
		$wcorg_subroles = array(
			$user->ID => array( 'wordcamp_wrangler' ),
		);

		$this->assertTrue( $user->has_cap( 'read' ) );
		$this->assertTrue( $user->has_cap( 'wordcamp_wrangle_wordcamps' ) );
	}
}
