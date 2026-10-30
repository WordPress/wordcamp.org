<?php

namespace WordCamp\Participation_Notifier\Tests;

use WordCamp_Participation_Notifier;
use WP_UnitTestCase;

use function WordCamp\Participation_Notifier\Migrate_Campus_Connect_Organizer_Meta\migrate_organizer_meta_for_site;
use function WordCamp\Participation_Notifier\Migrate_Campus_Connect_Organizer_Meta\reconcile_profiles_badges_for_user;
use function WordCamp\Participation_Notifier\Migrate_Campus_Connect_Organizer_Meta\user_deserves_campus_connect_organizer;
use function WordCamp\Participation_Notifier\Migrate_Campus_Connect_Organizer_Meta\user_deserves_wordcamp_organizer;

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
		$this->assertSame( array( $user_id ), $result['user_ids'] );
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

	/**
	 * @covers \WordCamp\Participation_Notifier\Migrate_Campus_Connect_Organizer_Meta\user_deserves_campus_connect_organizer
	 * @covers \WordCamp\Participation_Notifier\Migrate_Campus_Connect_Organizer_Meta\user_deserves_wordcamp_organizer
	 */
	public function test_dry_run_entitlement_treats_legacy_cc_keys_as_campus_connect_only() {
		$user_id        = self::factory()->user->create();
		$campus_site_id = 3001;
		$cc_site_ids    = array( $campus_site_id );

		update_user_meta(
			$user_id,
			WordCamp_Participation_Notifier::WORDCAMP_ORGANIZER_META_PREFIX . $campus_site_id . '_1',
			true
		);

		$this->assertTrue( user_deserves_campus_connect_organizer( $user_id, $cc_site_ids, true ) );
		$this->assertFalse( user_deserves_wordcamp_organizer( $user_id, $cc_site_ids, true ) );
	}

	/**
	 * @covers \WordCamp\Participation_Notifier\Migrate_Campus_Connect_Organizer_Meta\user_deserves_wordcamp_organizer
	 */
	public function test_mixed_organizer_keeps_wordcamp_entitlement_after_excluding_legacy_cc_keys() {
		$user_id          = self::factory()->user->create();
		$campus_site_id   = 3002;
		$wordcamp_site_id = 3003;
		$cc_site_ids      = array( $campus_site_id );

		update_user_meta(
			$user_id,
			WordCamp_Participation_Notifier::WORDCAMP_ORGANIZER_META_PREFIX . $campus_site_id . '_1',
			true
		);
		update_user_meta(
			$user_id,
			WordCamp_Participation_Notifier::WORDCAMP_ORGANIZER_META_PREFIX . $wordcamp_site_id . '_2',
			true
		);

		$this->assertTrue( user_deserves_wordcamp_organizer( $user_id, $cc_site_ids, true ) );
	}

	/**
	 * @covers \WordCamp\Participation_Notifier\Migrate_Campus_Connect_Organizer_Meta\reconcile_profiles_badges_for_user
	 */
	public function test_dry_run_reconcile_reports_add_and_remove_for_cc_only_organizer() {
		$user_id     = self::factory()->user->create( array( 'user_login' => 'cc_only_org' ) );
		$cc_site_ids = array( 4001 );

		update_user_meta(
			$user_id,
			WordCamp_Participation_Notifier::WORDCAMP_ORGANIZER_META_PREFIX . '4001_9',
			true
		);

		$result = reconcile_profiles_badges_for_user( $user_id, $cc_site_ids, true );

		$this->assertTrue( $result['add_campus_connect'] );
		$this->assertTrue( $result['remove_wordcamp'] );
		$this->assertSame( 'cc_only_org', $result['login'] );
		// Dry-run must not rename meta.
		$this->assertSame(
			'1',
			(string) get_user_meta(
				$user_id,
				WordCamp_Participation_Notifier::WORDCAMP_ORGANIZER_META_PREFIX . '4001_9',
				true
			)
		);
	}

	/**
	 * @covers \WordCamp\Participation_Notifier\Migrate_Campus_Connect_Organizer_Meta\reconcile_profiles_badges_for_user
	 */
	public function test_dry_run_reconcile_keeps_wordcamp_badge_for_mixed_organizer() {
		$user_id     = self::factory()->user->create();
		$cc_site_ids = array( 4002 );

		update_user_meta(
			$user_id,
			WordCamp_Participation_Notifier::WORDCAMP_ORGANIZER_META_PREFIX . '4002_1',
			true
		);
		update_user_meta(
			$user_id,
			WordCamp_Participation_Notifier::WORDCAMP_ORGANIZER_META_PREFIX . '5000_1',
			true
		);

		$result = reconcile_profiles_badges_for_user( $user_id, $cc_site_ids, true );

		$this->assertTrue( $result['add_campus_connect'] );
		$this->assertFalse( $result['remove_wordcamp'] );
	}
}
