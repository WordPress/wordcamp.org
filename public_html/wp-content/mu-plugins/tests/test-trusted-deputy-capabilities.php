<?php

namespace WordCamp\Trusted_Deputy_Capabilities\Tests;

use WP_UnitTestCase, WP_UnitTest_Factory;
use function WordCamp\Trusted_Deputy_Capabilities\{ is_deputy };

defined( 'WPINC' ) || die();

/**
 * @group mu-plugins
 * @group trusted-deputies
 */
class Test_Trusted_Deputy_Capabilities extends WP_UnitTestCase {
	/**
	 * Note: `wporg_remove_super_caps()` denies `import` to non-Super Admins if the domain isn't wordcamp.org,
	 * which results in a false-negative on sandboxes with alternate domain names.
	 */
	protected static $allowed_caps = array(
		'manage_network',
		'manage_sites',
		'activate_plugins',
		'export',
		'import',
		'edit_theme_options',

		'jetpack_connect',
		'jetpack_reconnect',
		'jetpack_disconnect',
		'jetpack_network_admin_page',
		'jetpack_network_sites_page',
		'jetpack_network_settings_page',
	);

	protected static $denied_caps = array(
		'unfiltered_html',
		'manage_network_users',
		'manage_network_plugins',
		'manage_network_themes',
		'manage_network_options',
		'create_users',
		'delete_plugins',
		'delete_themes',
		'delete_users',
		'edit_files',
		'edit_plugins',
		'edit_themes',
		'edit_users',
	);

	protected static $subscriber_id;
	protected static $trusted_deputy_id;

	/**
	 * Setup shared fixtures before any tests are run.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) : void {
		global $trusted_deputies;

		self::$subscriber_id = self::factory()->user->create( array(
			'role' => 'subscriber',
		) );

		self::$trusted_deputy_id = self::factory()->user->create( array(
			'role' => 'subscriber',
		) );

		$trusted_deputies = array(
			self::$trusted_deputy_id,
		);
	}

	/**
	 * Test that the user can do all capabilities that are granted to trusted deputies.
	 */
	protected function can_do_allowed_caps( int $user_id ) : bool {
		$did_all_allowed = true;

		foreach ( self::$allowed_caps as $capability ) {
			if ( ! user_can( $user_id, $capability ) ) {
				$did_all_allowed = false;
				break;
			}
		}

		return $did_all_allowed;
	}

	/**
	 * Test that the user can't do any capabilities that are denied to trusted deputies.
	 */
	protected function can_do_denied_caps( int $user_id ): bool {
		$did_denied_cap = false;

		foreach ( self::$denied_caps as $capability ) {
			if ( user_can( $user_id, $capability ) ) {
				$did_denied_cap = true;
				break;
			}
		}

		return $did_denied_cap;
	}

	/**
	 * @covers \WordCamp\Trusted_Deputy_Capabilities\is_deputy()
	 */
	public function test_is_deputy() {
		$this->assertFalse( is_deputy( self::$subscriber_id ) );
		$this->assertTrue( is_deputy( self::$trusted_deputy_id ) );
	}

	/**
	 * @covers \WordCamp\Trusted_Deputy_Capabilities\trusted_deputy_meta_caps()
	 * @covers \WordCamp\Trusted_Deputy_Capabilities\trusted_deputy_has_cap()
	 */
	public function test_can_only_do_allowed_caps() {
		$this->assertFalse( $this->can_do_allowed_caps( self::$subscriber_id ) );
		$this->assertFalse( $this->can_do_denied_caps( self::$subscriber_id ) );
		$this->assertTrue( $this->can_do_allowed_caps( self::$trusted_deputy_id ) );
		$this->assertFalse( $this->can_do_denied_caps( self::$trusted_deputy_id ) );
	}

	/**
	 * @covers \WordCamp\Trusted_Deputy_Capabilities\trusted_deputy_meta_caps()
	 * @covers \WordCamp\Trusted_Deputy_Capabilities\trusted_deputy_has_cap()
	 */
	public function test_cant_do_subrole_caps() {
		$this->assertFalse( user_can( self::$trusted_deputy_id, 'view_wordcamp_reports' ) ); // Primitive cap.
		$this->assertFalse( user_can( self::$trusted_deputy_id, 'edit_others_wordcamps' ) ); // Meta cap.
	}
}
