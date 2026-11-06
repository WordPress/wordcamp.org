<?php

namespace WordCamp\Groups\Tests;

use WordCamp\Groups\Action_Log;
use WordCamp\Groups\Archive;
use WordCamp\Groups\Messaging;
use WordCamp\Groups\Network_Export;
use WordCamp\Groups\Ownership_Transfer;
use WordCamp\Groups\Site_Provisioning;
use WPDieException;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__, 2 ) . '/wporg-groups-frontend/tests/class-groups-testcase.php';
require_once __DIR__ . '/trait-program-supporter-fixtures.php';

/**
 * The log of who used the Groups tools (#2294).
 *
 * The handler tests act as a Program Supporter, so they also cover the
 * tools end to end for the people this change is for.
 *
 * @group groups
 */
class Test_Group_Action_Log extends Groups_TestCase {
	use Program_Supporter_Fixtures;

	/** Thrown from `wp_redirect` so a handler's `exit` never runs. */
	const REDIRECTED = 'wporg-groups-action-log-redirected';

	/**
	 * A group site for the handlers to act on.
	 *
	 * @var int
	 */
	private $group_site_id;

	/**
	 * Create a group to act on.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->save_role_fixtures();

		$this->group_site_id = self::factory()->blog->create(
			array(
				'domain'     => 'events.wordpress.test',
				'path'       => '/group/action-log-test/',
				'network_id' => GROUPS_NETWORK_ID,
			)
		);

		add_filter( 'pre_wp_mail', '__return_true' );
	}

	/**
	 * Clean up queued jobs while their network is still current, then the fixtures.
	 */
	protected function tearDown(): void {
		remove_filter( 'pre_wp_mail', '__return_true' );

		delete_site_option( Messaging\JOBS_OPTION );
		delete_site_option( Network_Export\JOB_OPTION );
		Network_Export\delete_site_rows( $this->group_site_id );
		wp_clear_scheduled_hook( Messaging\CRON_HOOK );
		wp_clear_scheduled_hook( Network_Export\CRON_HOOK );

		$_POST    = array();
		$_REQUEST = array();

		wp_delete_site( $this->group_site_id );

		$this->restore_role_fixtures();

		parent::tearDown();
	}

	/**
	 * Entries by one user, newest first.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return array[]
	 */
	private function entries_by( int $user_id ): array {
		return array_values(
			array_filter(
				Action_Log\get_entries()['entries'],
				static function ( $entry ) use ( $user_id ) {
					return $entry['user_id'] === $user_id;
				}
			)
		);
	}

	/**
	 * Post a form to a handler, as `$_POST` and `$_REQUEST` both see it.
	 *
	 * @param array $fields Form fields.
	 */
	private function post( array $fields ): void {
		$_POST    = $fields;
		$_REQUEST = $fields;
	}

	/**
	 * Run a handler whose success path redirects and exits.
	 *
	 * @param callable $handler The handler.
	 *
	 * @throws \Exception Anything other than the redirect.
	 */
	private function run_redirecting_handler( callable $handler ): void {
		$catcher = static function () {
			throw new \Exception( esc_html( self::REDIRECTED ) );
		};

		add_filter( 'wp_redirect', $catcher );

		try {
			$handler();
			$this->fail( 'The handler should have redirected.' );
		} catch ( \Exception $thrown ) {
			if ( self::REDIRECTED !== $thrown->getMessage() ) {
				throw $thrown;
			}
		} finally {
			remove_filter( 'wp_redirect', $catcher );
		}
	}

	/**
	 * An entry is a post on the Groups root site, authored by whoever acted.
	 */
	public function test_record_stores_who_did_what_where() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$entry_id = Action_Log\record( 'group_archived', $this->group_site_id, array( 'note' => 'kept' ) );

		$this->assertIsInt( $entry_id );

		$entries = $this->entries_by( $user_id );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'group_archived', $entries[0]['action'] );
		$this->assertSame( $this->group_site_id, $entries[0]['site_id'] );
		$this->assertSame( array( 'note' => 'kept' ), $entries[0]['context'] );
		$this->assertEqualsWithDelta( time(), $entries[0]['time'], 60 );

		switch_to_blog( GROUPS_ROOT_BLOG_ID );
		$post = get_post( $entry_id );
		restore_current_blog();

		$this->assertSame( Action_Log\POST_TYPE, $post->post_type );
		$this->assertSame( $user_id, (int) $post->post_author );
	}

	/**
	 * Unknown actions and logged-out requests are refused, not stored.
	 */
	public function test_record_refuses_unknown_actions_and_anonymous_users() {
		wp_set_current_user( 0 );
		$this->assertWPError( Action_Log\record( 'group_archived' ) );

		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$this->assertWPError( Action_Log\record( 'made_up_action' ) );

		$this->assertSame( array(), $this->entries_by( $user_id ) );
	}

	/**
	 * Entries are the record behind future profile credit, so removing the
	 * user from the site that stores them mustn't delete them.
	 */
	public function test_entries_survive_their_author_being_removed() {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$entry_id = Action_Log\record( 'export_started', 0, array( 'group_count' => 3 ) );

		switch_to_blog( GROUPS_ROOT_BLOG_ID );
		add_user_to_blog( GROUPS_ROOT_BLOG_ID, $user_id, 'subscriber' );
		wp_delete_user( $user_id );
		$post = get_post( $entry_id );
		restore_current_blog();

		$this->assertNotNull( $post );
	}

	/**
	 * Creating a group logs it, with its lead organizer.
	 */
	public function test_creating_a_group_is_logged() {
		require_once ABSPATH . 'wp-admin/includes/template.php';

		self::factory()->user->create( array( 'user_login' => 'actionlogorganizer' ) );
		$user_id = $this->become_program_supporter();

		$this->post(
			array(
				'wcorg_create_group_site'      => '1',
				Site_Provisioning\NONCE_NAME   => wp_create_nonce( Site_Provisioning\NONCE_ACTION ),
				'group_title'                  => 'Action Log Meetup',
				'group_slug'                   => 'action-log-meetup',
				'organizer_login'              => 'actionlogorganizer',
			)
		);

		Site_Provisioning\handle_form_submission();

		$entries = $this->entries_by( $user_id );
		$site    = get_site_by_path( 'events.wordpress.test', '/group/action-log-meetup/' );

		$this->assertNotFalse( $site, 'A Program Supporter should be able to create a group.' );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'group_created', $entries[0]['action'] );
		$this->assertSame( (int) $site->blog_id, $entries[0]['site_id'] );
		$this->assertSame( 'actionlogorganizer', $entries[0]['context']['organizer_login'] );

		wp_delete_site( (int) $site->blog_id );
	}

	/**
	 * A failed create logs nothing.
	 */
	public function test_failed_group_creation_is_not_logged() {
		require_once ABSPATH . 'wp-admin/includes/template.php';

		$user_id = $this->become_program_supporter();

		$this->post(
			array(
				'wcorg_create_group_site'    => '1',
				Site_Provisioning\NONCE_NAME => wp_create_nonce( Site_Provisioning\NONCE_ACTION ),
				'group_title'                => 'Nobody Leads This',
				'group_slug'                 => 'nobody-leads-this',
				'organizer_login'            => 'no-such-user-anywhere',
			)
		);

		Site_Provisioning\handle_form_submission();

		$this->assertSame( array(), $this->entries_by( $user_id ) );
	}

	/**
	 * Archiving and reactivating are each logged against the group.
	 */
	public function test_archiving_and_reactivating_are_logged() {
		$user_id = $this->become_program_supporter();
		$nonce   = wp_create_nonce( Archive\UPDATE_ACTION . '_' . $this->group_site_id );

		$this->post(
			array(
				'site_id'  => (string) $this->group_site_id,
				'archived' => '1',
				'_wpnonce' => $nonce,
			)
		);
		$this->run_redirecting_handler( 'WordCamp\Groups\Archive\handle_update' );

		$this->assertSame( '1', get_site( $this->group_site_id )->archived, 'A Program Supporter should be able to archive a group.' );

		$this->post(
			array(
				'site_id'  => (string) $this->group_site_id,
				'archived' => '0',
				'_wpnonce' => $nonce,
			)
		);
		$this->run_redirecting_handler( 'WordCamp\Groups\Archive\handle_update' );

		$actions = wp_list_pluck( $this->entries_by( $user_id ), 'action' );
		$sites   = wp_list_pluck( $this->entries_by( $user_id ), 'site_id' );

		$this->assertEqualsCanonicalizing( array( 'group_archived', 'group_reactivated' ), $actions );
		$this->assertSame( array( $this->group_site_id, $this->group_site_id ), $sites );
	}

	/**
	 * A refused archive (here, the network root) logs nothing.
	 */
	public function test_refused_archive_is_not_logged() {
		$user_id = $this->become_program_supporter();

		$this->post(
			array(
				'site_id'  => (string) GROUPS_ROOT_BLOG_ID,
				'archived' => '1',
				'_wpnonce' => wp_create_nonce( Archive\UPDATE_ACTION . '_' . GROUPS_ROOT_BLOG_ID ),
			)
		);

		try {
			Archive\handle_update();
			$this->fail( 'Archiving the network root should have been refused.' );
		} catch ( WPDieException $exception ) {
			$this->assertSame( array(), $this->entries_by( $user_id ) );
		}
	}

	/**
	 * Deciding a transfer is logged with who it was between.
	 */
	public function test_transfer_decisions_are_logged() {
		// Someone for the "awaiting approval" email to go to, or it warns.
		$GLOBALS['super_admins'] = array( get_userdata( self::factory()->user->create() )->user_login ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		switch_to_blog( $this->group_site_id );
		$owner_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		Ownership_Transfer\initiate_transfer( $this->group_site_id, $owner_id, $candidate_id, $owner_id );
		Ownership_Transfer\accept_transfer( $this->group_site_id, $candidate_id );
		restore_current_blog();

		unset( $GLOBALS['super_admins'] );

		$user_id = $this->become_program_supporter();

		$this->post(
			array(
				'site_id'  => (string) $this->group_site_id,
				'decision' => 'approve',
				'_wpnonce' => wp_create_nonce( Ownership_Transfer\DECIDE_ACTION . '_' . $this->group_site_id ),
			)
		);
		$this->run_redirecting_handler( 'WordCamp\Groups\Ownership_Transfer\handle_decision' );

		$this->assertNull( Ownership_Transfer\get_pending_transfer( $this->group_site_id ), 'A Program Supporter should be able to approve a transfer.' );

		$entries = $this->entries_by( $user_id );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'transfer_approved', $entries[0]['action'] );
		$this->assertSame( $this->group_site_id, $entries[0]['site_id'] );
		$this->assertSame(
			array(
				'from_user_id' => $owner_id,
				'to_user_id'   => $candidate_id,
			),
			$entries[0]['context']
		);
	}

	/**
	 * Queuing a message logs what was sent to whom, but not the body.
	 */
	public function test_queuing_a_message_is_logged() {
		$user_id = $this->become_program_supporter();

		$this->post(
			array(
				'subject'  => 'Hello organizers',
				'body'     => 'Private details that the log should not keep.',
				'audience' => Messaging\AUDIENCE_ORGANIZERS,
				'scope'    => 'selected',
				'groups'   => array( (string) $this->group_site_id ),
				'_wpnonce' => wp_create_nonce( Messaging\FORM_ACTION ),
			)
		);
		$this->run_redirecting_handler( 'WordCamp\Groups\Messaging\handle_form_post' );

		$entries = $this->entries_by( $user_id );

		$this->assertNotEmpty( get_site_option( Messaging\JOBS_OPTION ), 'A Program Supporter should be able to queue a message.' );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'message_queued', $entries[0]['action'] );
		$this->assertSame(
			array(
				'audience'    => Messaging\AUDIENCE_ORGANIZERS,
				'scope'       => 'selected',
				'group_count' => 1,
				'subject'     => 'Hello organizers',
				'group_ids'   => array( $this->group_site_id ),
			),
			$entries[0]['context']
		);
	}

	/**
	 * A message that isn't queued logs nothing.
	 */
	public function test_rejected_message_is_not_logged() {
		$user_id = $this->become_program_supporter();

		$this->post(
			array(
				'subject'  => '',
				'body'     => '',
				'audience' => Messaging\AUDIENCE_ORGANIZERS,
				'scope'    => 'all',
				'_wpnonce' => wp_create_nonce( Messaging\FORM_ACTION ),
			)
		);
		$this->run_redirecting_handler( 'WordCamp\Groups\Messaging\handle_form_post' );

		$this->assertSame( array(), $this->entries_by( $user_id ) );
	}

	/**
	 * Starting a network export is logged.
	 */
	public function test_starting_an_export_is_logged() {
		$user_id = $this->become_program_supporter();

		$this->post( array( '_wpnonce' => wp_create_nonce( Network_Export\FORM_ACTION ) ) );
		$this->run_redirecting_handler( 'WordCamp\Groups\Network_Export\handle_start_export' );

		$entries = $this->entries_by( $user_id );

		$this->assertNotEmpty( Network_Export\get_job(), 'A Program Supporter should be able to start an export.' );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'export_started', $entries[0]['action'] );
		$this->assertSame( 0, $entries[0]['site_id'] );
		$this->assertGreaterThanOrEqual( 1, $entries[0]['context']['group_count'] );
	}

	/**
	 * The Activity screen lists entries and copes with deleted users and sites.
	 */
	public function test_activity_screen_renders_entries() {
		$user_id = $this->become_program_supporter();

		Action_Log\record( 'group_archived', $this->group_site_id );
		Action_Log\record( 'group_reactivated', 999999 );
		Action_Log\record(
			'transfer_rejected',
			$this->group_site_id,
			array(
				'from_user_id' => 999998,
				'to_user_id'   => $user_id,
			)
		);

		ob_start();
		Action_Log\render_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Archived a group', $html );
		$this->assertStringContainsString( gmdate( 'Y-m-d' ) . ' ', $html, 'Each entry shows when it happened.' );
		$this->assertStringContainsString( 'Deleted site 999999', $html );
		$this->assertStringContainsString( '(deleted user)', $html );
		$this->assertStringContainsString( get_userdata( $user_id )->user_login, $html );
	}

	/**
	 * The Activity screen is for the same people as the tools.
	 */
	public function test_activity_screen_refuses_group_administrators() {
		$this->use_groups_network();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->expectException( WPDieException::class );

		Action_Log\render_page();
	}
}
