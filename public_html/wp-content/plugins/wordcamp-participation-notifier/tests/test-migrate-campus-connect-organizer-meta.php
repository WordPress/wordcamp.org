<?php

namespace WordCamp\Participation_Notifier\Tests;

use WordCamp_Participation_Notifier;
use WP_UnitTestCase;

use function WordCamp\Participation_Notifier\Migrate_Campus_Connect_Organizer_Meta\migrate_organizer_meta_for_site;

defined( 'WPINC' ) || die();

/**
 * The one-time migration behind `wp wc-cc-organizer-meta migrate`.
 *
 * Goes when `mu-plugins/wp-cli-commands/migrate-campus-connect-organizer-meta.php` does.
 * `Test_Campus_Connect_Organizer_Association` covers what stays.
 *
 * @group wordcamp-participation-notifier
 * @group wp-cli-migration
 */
class Test_Migrate_Campus_Connect_Organizer_Meta extends WP_UnitTestCase {

	/**
	 * Absolute path to the one-time CLI migration file.
	 *
	 * @var string
	 */
	protected static $cli_file;

	/**
	 * Whether the CLI migration file was found and loaded.
	 *
	 * @var bool
	 */
	protected static $cli_available = false;

	/**
	 * Soft-fail the whole class when the disposable CLI file has been deleted.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		self::$cli_file = SUT_WPMU_PLUGIN_DIR . '/wp-cli-commands/migrate-campus-connect-organizer-meta.php';

		if ( ! file_exists( self::$cli_file ) ) {
			return;
		}

		require_once self::$cli_file;
		self::$cli_available = true;
	}

	/**
	 * Incomplete (not a failure) when the one-time command file is gone — same class of signal as a
	 * test class with no tests: the suite stays green, but the run notes that this file can go too.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! self::$cli_available ) {
			$this->markTestIncomplete(
				'wp-cli-commands/migrate-campus-connect-organizer-meta.php was not found and may have been removed. ' .
				'Delete tests/test-migrate-campus-connect-organizer-meta.php.'
			);
		}
	}

	/**
	 * @covers \WordCamp\Participation_Notifier\Migrate_Campus_Connect_Organizer_Meta\migrate_organizer_meta_for_site
	 */
	public function test_migrate_renames_legacy_campus_connect_organizer_keys() {
		$user_id = self::factory()->user->create();
		$post_id = 99;
		$site_id = 1234;

		$old_key = WordCamp_Participation_Notifier::WORDCAMP_ORGANIZER_META_PREFIX . $site_id . '_' . $post_id;
		$new_key = WordCamp_Participation_Notifier::CAMPUS_CONNECT_ORGANIZER_META_PREFIX . $site_id . '_' . $post_id;

		update_user_meta( $user_id, $old_key, true );

		$result = migrate_organizer_meta_for_site( $site_id );

		$this->assertSame( 1, $result['renamed'] );
		$this->assertSame( '', get_user_meta( $user_id, $old_key, true ) );
		$this->assertSame( '1', (string) get_user_meta( $user_id, $new_key, true ) );
	}

	/**
	 * @covers \WordCamp\Participation_Notifier\Migrate_Campus_Connect_Organizer_Meta\migrate_organizer_meta_for_site
	 */
	public function test_migrate_drops_legacy_key_when_new_key_already_exists() {
		$user_id = self::factory()->user->create();
		$post_id = 100;
		$site_id = 1235;

		$old_key = WordCamp_Participation_Notifier::WORDCAMP_ORGANIZER_META_PREFIX . $site_id . '_' . $post_id;
		$new_key = WordCamp_Participation_Notifier::CAMPUS_CONNECT_ORGANIZER_META_PREFIX . $site_id . '_' . $post_id;

		update_user_meta( $user_id, $old_key, true );
		update_user_meta( $user_id, $new_key, true );

		$result = migrate_organizer_meta_for_site( $site_id );

		$this->assertSame( 1, $result['deleted_duplicate'] );
		$this->assertSame( '', get_user_meta( $user_id, $old_key, true ) );
		$this->assertSame( '1', (string) get_user_meta( $user_id, $new_key, true ) );
	}

	/**
	 * @covers \WordCamp\Participation_Notifier\Migrate_Campus_Connect_Organizer_Meta\migrate_organizer_meta_for_site
	 */
	public function test_migrate_does_not_touch_unrelated_site_keys() {
		$user_id          = self::factory()->user->create();
		$campus_site_id   = 2001;
		$wordcamp_site_id = 2002;

		$cc_old_key = WordCamp_Participation_Notifier::WORDCAMP_ORGANIZER_META_PREFIX . $campus_site_id . '_11';
		$wc_old_key = WordCamp_Participation_Notifier::WORDCAMP_ORGANIZER_META_PREFIX . $wordcamp_site_id . '_22';
		$cc_new_key = WordCamp_Participation_Notifier::CAMPUS_CONNECT_ORGANIZER_META_PREFIX . $campus_site_id . '_11';

		update_user_meta( $user_id, $cc_old_key, true );
		update_user_meta( $user_id, $wc_old_key, true );

		migrate_organizer_meta_for_site( $campus_site_id );

		$this->assertSame( '', get_user_meta( $user_id, $cc_old_key, true ) );
		$this->assertSame( '1', (string) get_user_meta( $user_id, $cc_new_key, true ) );
		$this->assertSame( '1', (string) get_user_meta( $user_id, $wc_old_key, true ) );
	}

	/**
	 * @covers \WordCamp\Participation_Notifier\Migrate_Campus_Connect_Organizer_Meta\migrate_organizer_meta_for_site
	 */
	public function test_dry_run_does_not_write() {
		$user_id = self::factory()->user->create();
		$site_id = 2003;
		$old_key = WordCamp_Participation_Notifier::WORDCAMP_ORGANIZER_META_PREFIX . $site_id . '_33';
		$new_key = WordCamp_Participation_Notifier::CAMPUS_CONNECT_ORGANIZER_META_PREFIX . $site_id . '_33';

		update_user_meta( $user_id, $old_key, true );

		$result = migrate_organizer_meta_for_site( $site_id, true );

		$this->assertSame( 1, $result['renamed'] );
		$this->assertSame( '1', (string) get_user_meta( $user_id, $old_key, true ) );
		$this->assertSame( '', get_user_meta( $user_id, $new_key, true ) );
	}
}
