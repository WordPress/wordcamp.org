<?php

namespace WordCamp\Groups\Tests;

use WP_REST_Request;
use WordCamp\Groups\Network_Export;

use function WordCamp\Groups\Frontend\REST\create_event;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__, 2 ) . '/wporg-groups-frontend/tests/class-groups-testcase.php';

/**
 * Marks the exception thrown in place of the redirect-then-`exit` the
 * admin-post handlers end with, so their success paths are reachable from a
 * test. A sentinel message rather than a subclass, because this file may hold
 * only one class.
 */
const REDIRECT_SENTINEL = 'wporg-groups-export-redirected';

/**
 * @group groups
 */
class Test_Groups_Network_Export extends Groups_TestCase {
	/**
	 * Group sites created for the current test.
	 *
	 * @var int[]
	 */
	protected $group_sites = array();

	/**
	 * Create two group sites with working GatherPress tables.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->group_sites = array(
			'brisbane' => $this->create_group_site( 'brisbane' ),
			'hobart'   => $this->create_group_site( 'hobart' ),
		);
	}

	/**
	 * Clean up sites, the in-flight job, the artifact, and scheduled batches.
	 */
	protected function tearDown(): void {
		foreach ( $this->group_sites as $site_id ) {
			wp_delete_site( $site_id );
		}

		$this->group_sites = array();

		unset( $_REQUEST['_wpnonce'], $_POST['_wpnonce'], $_GET['_wpnonce'], $_GET['format'] );

		delete_site_option( Network_Export\JOB_OPTION );
		delete_site_option( Network_Export\EXPORT_OPTION );
		wp_clear_scheduled_hook( Network_Export\CRON_HOOK );

		parent::tearDown();
	}

	/**
	 * Create a site on the groups network, with GatherPress's per-blog events
	 * table installed — `Groups_TestCase` only self-heals the fixture root
	 * blog's.
	 *
	 * @param string $slug Group slug.
	 */
	protected function create_group_site( string $slug ): int {
		$site_id = self::factory()->blog->create(
			array(
				'domain'     => 'events.wordpress.test',
				'path'       => "/group/$slug/",
				'network_id' => GROUPS_NETWORK_ID,
			)
		);

		$this->with_site(
			$site_id,
			function () use ( $slug ) {
				\GatherPress\Core\Setup::get_instance()->check_plugin_version();
				update_option( 'blogname', ucfirst( $slug ) );
			}
		);

		return $site_id;
	}

	/**
	 * Run a callback on a group site and restore the previous blog.
	 *
	 * @param int      $site_id  Group site.
	 * @param callable $callback What to run there.
	 * @return mixed The callback's return value.
	 */
	protected function with_site( int $site_id, callable $callback ) {
		switch_to_blog( $site_id );

		try {
			return $callback();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Make the current user a network admin of the groups network.
	 *
	 * `grant_super_admin()` reads `site_admins` off whichever network is
	 * current, which in this fixture isn't the groups network, so set the
	 * option `get_super_admins()` actually consults.
	 *
	 * @return array The previous `site_admins` value, for restoring.
	 */
	protected function become_network_admin(): array {
		$original = (array) get_site_option( 'site_admins', array() );
		$user_id  = self::factory()->user->create();

		update_site_option( 'site_admins', array( get_userdata( $user_id )->user_login ) );
		wp_set_current_user( $user_id );

		return $original;
	}

	/**
	 * Make the current user an administrator of one group site only.
	 */
	protected function become_group_administrator(): void {
		$user_id = self::factory()->user->create();

		add_user_to_blog( $this->group_sites['brisbane'], $user_id, 'administrator' );
		wp_set_current_user( $user_id );
	}

	/**
	 * Put a valid nonce where `check_admin_referer()` and the download
	 * validator look for it.
	 *
	 * @param string $action Nonce action, or a raw string for an invalid one.
	 * @param bool   $valid  Whether to mint a real nonce for `$action`.
	 */
	protected function set_nonce( string $action, bool $valid = true ): void {
		$nonce = $valid ? wp_create_nonce( $action ) : 'not-a-nonce';

		$_REQUEST['_wpnonce'] = $nonce;
		$_POST['_wpnonce']    = $nonce;
		$_GET['_wpnonce']     = $nonce;
	}

	/**
	 * Run a handler whose success path ends in a redirect, and return where it
	 * tried to send the browser.
	 *
	 * @param callable $callback Handler to run.
	 * @throws \Exception Anything the handler throws other than the redirect
	 *                    sentinel this method installs.
	 */
	protected function catch_redirect( callable $callback ): string {
		$location = '';

		$catcher = function ( $target ) use ( &$location ) {
			$location = (string) $target;

			throw new \Exception( esc_html( REDIRECT_SENTINEL ) );
		};

		add_filter( 'wp_redirect', $catcher );

		try {
			$callback();
		} catch ( \Exception $thrown ) {
			// Anything else is a real failure, not the redirect being caught.
			if ( REDIRECT_SENTINEL !== $thrown->getMessage() ) {
				throw $thrown;
			}
		} finally {
			remove_filter( 'wp_redirect', $catcher );
		}

		return $location;
	}

	/**
	 * Create a published event on a group site via the production REST path,
	 * so the GatherPress dates table is written the same way it is for real.
	 *
	 * @param int    $site_id Group site.
	 * @param string $title   Event title.
	 * @return int Event post ID.
	 */
	protected function create_site_event( int $site_id, string $title ): int {
		return $this->with_site(
			$site_id,
			function () use ( $title ) {
				$previous_user = get_current_user_id();
				wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

				$request = new WP_REST_Request( 'POST', '/wporg-groups/v1/event' );
				foreach ( array(
					'title'      => $title,
					'date'       => current_datetime()->modify( '+1 week' )->format( 'Y-m-d' ),
					'time_start' => '18:00',
					'time_end'   => '20:00',
				) as $key => $value ) {
					$request->set_param( $key, $value );
				}

				$response = create_event( $request );
				wp_set_current_user( $previous_user );

				$this->assertNotWPError( $response );

				return (int) $response->get_data()['id'];
			}
		);
	}

	/**
	 * RSVP a fresh user to an event on a group site, GatherPress-style.
	 *
	 * @param int    $site_id  Group site.
	 * @param int    $event_id Event post ID on that site.
	 * @param string $status   RSVP status term slug.
	 * @param array  $args     Optional user overrides (display_name, etc.).
	 */
	protected function create_site_rsvp( int $site_id, int $event_id, string $status, array $args = array() ): void {
		$user_id = self::factory()->user->create( $args );

		$this->with_site(
			$site_id,
			function () use ( $event_id, $status, $user_id ) {
				$comment_id = wp_insert_comment(
					array(
						'comment_post_ID'  => $event_id,
						'comment_type'     => 'gatherpress_rsvp',
						'comment_approved' => 1,
						'user_id'          => $user_id,
					)
				);

				if ( 'no_status' !== $status ) {
					wp_set_object_terms( $comment_id, $status, '_gatherpress_rsvp_status' );
				}
			}
		);
	}

	/**
	 * Drain the in-flight export, however many batches it takes.
	 */
	protected function drain_export(): void {
		$guard = 0;

		while ( Network_Export\get_job() && $guard < 50 ) {
			Network_Export\process_batch();
			++$guard;
		}
	}

	/**
	 * Parse a CSV body into rows, with the same strict RFC 4180 rules it was
	 * written under. The BOM is asserted by its own test, not stripped here.
	 *
	 * @param string $csv File body.
	 */
	protected function parse_csv( string $csv ): array {
		$csv   = preg_replace( '/^\xEF\xBB\xBF/', '', $csv );
		$lines = array_values( array_filter( explode( "\n", trim( $csv ) ), 'strlen' ) );

		return array_map(
			static function ( $line ) {
				return str_getcsv( $line, ',', '"', '' );
			},
			$lines
		);
	}

	/**
	 * Group organisers — even administrators of their own group — can't
	 * export the whole network.
	 */
	public function test_group_administrators_cannot_export() {
		$this->become_group_administrator();

		$this->assertFalse( Network_Export\current_user_can_export() );
	}

	/**
	 * Network admins (Program Managers) can.
	 */
	public function test_network_admins_can_export() {
		$original = $this->become_network_admin();

		$this->assertTrue( Network_Export\current_user_can_export() );

		update_site_option( 'site_admins', $original );
	}

	/**
	 * The start handler itself rejects non-network-admins — the capability
	 * gate must live in the handler, not only in the menu registration.
	 */
	public function test_start_handler_rejects_group_administrators() {
		$this->become_group_administrator();
		$this->set_nonce( Network_Export\FORM_ACTION );

		$this->expectException( 'WPDieException' );
		$this->expectExceptionMessage( 'permission' );

		Network_Export\handle_start_export();
	}

	/**
	 * The cancel handler rejects them too.
	 */
	public function test_cancel_handler_rejects_group_administrators() {
		$this->become_group_administrator();
		$this->set_nonce( Network_Export\CANCEL_ACTION );

		$this->expectException( 'WPDieException' );
		$this->expectExceptionMessage( 'permission' );

		Network_Export\handle_cancel_export();
	}

	/**
	 * The download handler rejects them with a 403 rather than serving a file.
	 */
	public function test_download_handler_rejects_group_administrators() {
		$this->become_group_administrator();
		$this->set_nonce( Network_Export\DOWNLOAD_ACTION );

		$this->expectException( 'WPDieException' );
		$this->expectExceptionMessage( 'permission' );

		Network_Export\handle_download();
	}

	/**
	 * A network admin still can't start an export without a valid nonce.
	 */
	public function test_start_handler_requires_a_valid_nonce() {
		$original = $this->become_network_admin();
		$this->set_nonce( Network_Export\FORM_ACTION, false );

		try {
			$this->expectException( 'WPDieException' );

			Network_Export\handle_start_export();
		} finally {
			update_site_option( 'site_admins', $original );
		}
	}

	/**
	 * The download gate checks a nonce minted for its own action — not any
	 * other action's, which would 403 every real download link.
	 */
	public function test_download_validation_checks_the_download_nonce() {
		$original = $this->become_network_admin();

		try {
			$this->set_nonce( Network_Export\DOWNLOAD_ACTION, false );
			$invalid = Network_Export\validate_download_request();
			$this->assertWPError( $invalid );
			$this->assertSame( 'export_bad_nonce', $invalid->get_error_code() );

			// A nonce for the *start* action must not unlock a download.
			$this->set_nonce( Network_Export\FORM_ACTION );
			$wrong_action = Network_Export\validate_download_request();
			$this->assertWPError( $wrong_action );
			$this->assertSame( 'export_bad_nonce', $wrong_action->get_error_code() );

			// The nonce the summary screen actually mints does.
			$this->set_nonce( Network_Export\DOWNLOAD_ACTION );
			$this->assertSame( 'csv', Network_Export\validate_download_request() );
		} finally {
			update_site_option( 'site_admins', $original );
		}
	}

	/**
	 * The format parameter is allow-listed, not passed through.
	 */
	public function test_download_validation_resolves_format() {
		$original = $this->become_network_admin();
		$this->set_nonce( Network_Export\DOWNLOAD_ACTION );

		try {
			$_GET['format'] = 'json';
			$this->assertSame( 'json', Network_Export\validate_download_request() );

			$_GET['format'] = 'xml';
			$this->assertSame( 'csv', Network_Export\validate_download_request(), 'An unknown format must fall back to CSV.' );
		} finally {
			update_site_option( 'site_admins', $original );
		}
	}

	/**
	 * The happy path: a nonce'd submission from a network admin queues a job
	 * and redirects back with the "queued" notice.
	 */
	public function test_start_handler_queues_an_export() {
		$original = $this->become_network_admin();
		$this->set_nonce( Network_Export\FORM_ACTION );

		try {
			$location = $this->catch_redirect( 'WordCamp\Groups\Network_Export\handle_start_export' );

			$this->assertStringContainsString( 'notice=queued', $location );
			$this->assertNotEmpty( Network_Export\get_job() );
		} finally {
			update_site_option( 'site_admins', $original );
		}
	}

	/**
	 * Cancelling clears the run and unblocks a fresh start, without touching
	 * the last completed export.
	 */
	public function test_cancel_handler_clears_the_running_job() {
		$original = $this->become_network_admin();
		$this->set_nonce( Network_Export\CANCEL_ACTION );

		try {
			update_site_option(
				Network_Export\EXPORT_OPTION,
				array(
					'rows'          => array(),
					'generated_gmt' => '2026-01-01 00:00:00',
				)
			);
			Network_Export\queue_export( array_values( $this->group_sites ) );

			$location = $this->catch_redirect( 'WordCamp\Groups\Network_Export\handle_cancel_export' );

			$this->assertStringContainsString( 'notice=cancelled', $location );
			$this->assertSame( array(), Network_Export\get_job() );
			$this->assertFalse( wp_next_scheduled( Network_Export\CRON_HOOK ) );
			$this->assertIsArray( get_site_option( Network_Export\EXPORT_OPTION ), 'The previous export must survive a cancel.' );
		} finally {
			update_site_option( 'site_admins', $original );
		}
	}

	/**
	 * Starting a run leaves the previous export downloadable, so a failed run
	 * can't destroy the last good file.
	 */
	public function test_starting_a_run_keeps_the_previous_artifact() {
		update_site_option(
			Network_Export\EXPORT_OPTION,
			array(
				'rows'          => array(),
				'generated_gmt' => '2026-01-01 00:00:00',
			)
		);

		Network_Export\queue_export( array_values( $this->group_sites ) );

		$this->assertIsArray( get_site_option( Network_Export\EXPORT_OPTION ) );
	}

	/**
	 * Only one export can be in flight; a second start is refused and does
	 * not disturb the running job.
	 */
	public function test_second_start_is_refused_while_running() {
		$first = Network_Export\queue_export( array_values( $this->group_sites ) );
		$this->assertNotSame( '', $first );

		$job_before = Network_Export\get_job();

		$this->assertSame( '', Network_Export\queue_export( array_values( $this->group_sites ) ) );
		$this->assertSame( $job_before, Network_Export\get_job(), 'The in-flight job must be untouched.' );
	}

	/**
	 * A batch in progress holds a lease on the job: a concurrent run must
	 * not double-process it, and a new start must still see it as busy.
	 */
	public function test_batch_claim_blocks_concurrent_runs() {
		$job = $this->build_job(
			array(
				'id'            => 'leased-job',
				'claimed_until' => time() + 100,
				'claim_token'   => 'held-by-another-run',
			)
		);
		update_site_option( Network_Export\JOB_OPTION, $job );

		Network_Export\process_batch();

		$this->assertSame( $job, Network_Export\get_job(), 'A leased job must not be double-processed.' );
		$this->assertSame( '', Network_Export\queue_export( array_values( $this->group_sites ) ) );
	}

	/**
	 * An expired lease — a batch whose process died — is resumed by the next
	 * run instead of stalling the export forever.
	 */
	public function test_expired_claim_is_resumed() {
		update_site_option(
			Network_Export\JOB_OPTION,
			$this->build_job(
				array(
					'id'            => 'abandoned-job',
					'sites'         => array( $this->group_sites['brisbane'] ),
					'pending_sites' => array( $this->group_sites['brisbane'] ),
					'claimed_until' => time() - 10,
					'claim_token'   => 'stale-token',
				)
			)
		);

		$this->drain_export();

		$this->assertSame( array(), Network_Export\get_job() );
		$this->assertIsArray( get_site_option( Network_Export\EXPORT_OPTION ) );
	}

	/**
	 * A batch that overran its lease must not commit its stale cursor over
	 * the progress of the run that took the job over — the job ID is shared
	 * by every batch, so only the per-claim token can tell them apart.
	 */
	public function test_overrunning_batch_cannot_commit_over_a_newer_claim() {
		$sites = array_values( $this->group_sites );

		update_site_option(
			Network_Export\JOB_OPTION,
			$this->build_job(
				array(
					'id'            => 'shared-id',
					'sites'         => $sites,
					'pending_sites' => $sites,
					'claim_token'   => 'run-b',
					'claimed_until' => time() + 100,
				)
			)
		);

		// Run A: same job ID, its own (now superseded) claim token, and a
		// cursor from before run B took over.
		$stale_job = $this->build_job(
			array(
				'id'            => 'shared-id',
				'sites'         => $sites,
				'pending_sites' => array(),
				'rows'          => array( array( 'event_title' => 'stale' ) ),
				'claim_token'   => 'run-a',
			)
		);

		$committed = Network_Export\commit_job( $stale_job, true );

		$this->assertFalse( $committed, 'A stale claim must not commit.' );
		$this->assertSame( 'run-b', Network_Export\get_job()['claim_token'], "Run B's lease must be intact." );
		$this->assertSame( $sites, Network_Export\get_job()['pending_sites'], 'The cursor must not rewind.' );
		$this->assertFalse( get_site_option( Network_Export\EXPORT_OPTION ), 'No artifact may be published by a stale claim.' );
	}

	/**
	 * If the artifact can't be stored, the job stays put for another attempt
	 * instead of the run's work disappearing.
	 */
	public function test_failed_artifact_write_keeps_the_job() {
		$job = $this->build_job(
			array(
				'id'            => 'finishing-job',
				'pending_sites' => array(),
				'rows'          => array( array( 'event_title' => 'Kept' ) ),
				'claim_token'   => 'mine',
			)
		);
		update_site_option( Network_Export\JOB_OPTION, $job );

		$block_write = function ( $value, $old_value ) {
			unset( $value );

			// Returning the old value makes update_site_option() a no-op, so
			// it reports failure the way a real write failure would.
			return $old_value;
		};
		add_filter( 'pre_update_site_option_' . Network_Export\EXPORT_OPTION, $block_write, 10, 2 );

		$committed = Network_Export\commit_job( $job, true );

		remove_filter( 'pre_update_site_option_' . Network_Export\EXPORT_OPTION, $block_write, 10 );

		$this->assertFalse( $committed );
		$this->assertNotEmpty( Network_Export\get_job(), 'The job must survive a failed artifact write.' );
		$this->assertSame( 'Kept', Network_Export\get_job()['rows'][0]['event_title'] );
	}

	/**
	 * A site that keeps killing the process is abandoned after
	 * `MAX_SITE_ATTEMPTS` claims, so one poisonous group can't stall the
	 * export forever.
	 */
	public function test_repeatedly_claimed_site_is_abandoned() {
		$brisbane = $this->group_sites['brisbane'];
		$hobart   = $this->group_sites['hobart'];

		update_site_option(
			Network_Export\JOB_OPTION,
			$this->build_job(
				array(
					'sites'         => array( $brisbane, $hobart ),
					'pending_sites' => array( $brisbane, $hobart ),
					'site_attempts' => array( $brisbane => Network_Export\MAX_SITE_ATTEMPTS ),
				)
			)
		);

		$claim = Network_Export\claim_job();

		$this->assertSame( 'claimed', $claim['status'] );
		$this->assertSame( array( $hobart ), $claim['job']['pending_sites'], 'The poisonous site must be skipped.' );
		$this->assertArrayHasKey( $brisbane, $claim['job']['failed_sites'] );
	}

	/**
	 * A site deleted or archived between queueing and its batch is reported
	 * as failed, not silently exported as a group with no events.
	 */
	public function test_unavailable_site_is_reported_as_failed() {
		$archived = $this->group_sites['hobart'];
		update_blog_status( $archived, 'archived', '1' );

		Network_Export\queue_export( array( $this->group_sites['brisbane'], $archived ) );
		$this->drain_export();

		$artifact = get_site_option( Network_Export\EXPORT_OPTION );

		$this->assertArrayHasKey( $archived, $artifact['failed_sites'] );
		$this->assertSame( 1, $artifact['exported_site_count'] );
		$this->assertSame( 2, $artifact['site_count'] );

		// The gap has to reach whoever reads the file, not just the screen.
		$json     = json_decode( Network_Export\get_download_payload( 'json' )['body'], true );
		$expected = array(
			array(
				'site_id' => $archived,
				'name'    => '/group/hobart',
			),
		);

		$this->assertSame( $expected, $json['failed_sites'] );
		$this->assertSame( 1, $json['exported_site_count'] );
		$this->assertSame( 2, $json['site_count'] );

		update_blog_status( $archived, 'archived', '0' );
	}

	/**
	 * The artifact aggregates every site's events with per-status counts and
	 * per-site group identity; `no_status` RSVPs don't inflate any count.
	 */
	public function test_aggregation_across_sites() {
		$brisbane = $this->group_sites['brisbane'];
		$hobart   = $this->group_sites['hobart'];

		$meetup_id = $this->create_site_event( $brisbane, 'Brisbane Meetup' );
		$this->create_site_rsvp( $brisbane, $meetup_id, 'attending' );
		$this->create_site_rsvp( $brisbane, $meetup_id, 'attending' );
		$this->create_site_rsvp( $brisbane, $meetup_id, 'waiting_list' );
		$this->create_site_rsvp( $brisbane, $meetup_id, 'no_status' );

		$social_id = $this->create_site_event( $hobart, 'Hobart Social' );
		$this->create_site_rsvp( $hobart, $social_id, 'not_attending' );

		Network_Export\queue_export( array( $brisbane, $hobart ) );
		$this->drain_export();

		$artifact = get_site_option( Network_Export\EXPORT_OPTION );
		$this->assertIsArray( $artifact );
		$this->assertSame( 2, $artifact['site_count'] );
		$this->assertSame( 2, $artifact['exported_site_count'] );
		$this->assertSame( 2, $artifact['event_count'] );
		$this->assertSame( array(), $artifact['failed_sites'] );

		$rows = array_column( $artifact['rows'], null, 'event_title' );

		$this->assertSame( 'Brisbane', $rows['Brisbane Meetup']['group_name'] );
		$this->assertSame( 2, $rows['Brisbane Meetup']['attending_count'] );
		$this->assertSame( 1, $rows['Brisbane Meetup']['waiting_list_count'] );
		$this->assertSame( 0, $rows['Brisbane Meetup']['not_attending_count'] );
		$this->assertNotEmpty( $rows['Brisbane Meetup']['event_start_gmt'] );

		$this->assertSame( 'Hobart', $rows['Hobart Social']['group_name'] );
		$this->assertSame( 1, $rows['Hobart Social']['not_attending_count'] );
		$this->assertStringContainsString( '/group/hobart', $rows['Hobart Social']['group_url'] );
	}

	/**
	 * A run stops at the batch boundary and every batch's rows survive into
	 * the artifact — a resume that dropped its accumulated rows would leave
	 * only the last batch's.
	 */
	public function test_each_batch_keeps_its_rows() {
		$brisbane = $this->group_sites['brisbane'];
		$hobart   = $this->group_sites['hobart'];

		$this->create_site_event( $brisbane, 'Brisbane Meetup' );
		$this->create_site_event( $hobart, 'Hobart Social' );

		// One entry per unit of work, alternating sites: each entry yields
		// exactly one event row, so the totals below pin down per-batch
		// accumulation rather than just "it finished".
		$total   = Network_Export\SITES_PER_BATCH + 3;
		$pending = array();
		for ( $i = 0; $i < $total; $i++ ) {
			$pending[] = 0 === $i % 2 ? $brisbane : $hobart;
		}

		update_site_option(
			Network_Export\JOB_OPTION,
			$this->build_job(
				array(
					'sites'         => $pending,
					'pending_sites' => $pending,
				)
			)
		);

		Network_Export\process_batch();

		$job = Network_Export\get_job();
		$this->assertCount( 3, $job['pending_sites'], 'One run must stop at the batch boundary.' );
		$this->assertCount( Network_Export\SITES_PER_BATCH, $job['rows'], "The first batch's rows must be committed." );
		$this->assertNotFalse( wp_next_scheduled( Network_Export\CRON_HOOK ), 'The remainder must be rescheduled.' );

		$this->drain_export();

		$artifact = get_site_option( Network_Export\EXPORT_OPTION );
		$this->assertSame( array(), Network_Export\get_job() );
		$this->assertCount( $total, $artifact['rows'], 'Every batch must contribute its rows.' );
		$this->assertSame( $total, $artifact['event_count'] );
	}

	/**
	 * A site that blows up mid-collect is recorded as failed; the other
	 * site's rows survive and the export still completes.
	 */
	public function test_failed_site_is_recorded_not_silent() {
		$brisbane = $this->group_sites['brisbane'];
		$hobart   = $this->group_sites['hobart'];

		$this->create_site_event( $brisbane, 'Brisbane Meetup' );

		$sabotage = function ( $new_blog_id, $prev_blog_id, $context ) use ( $hobart ) {
			unset( $prev_blog_id );

			if ( 'switch' === $context && (int) $new_blog_id === $hobart ) {
				throw new \RuntimeException( 'boom' );
			}
		};
		add_action( 'switch_blog', $sabotage, 10, 3 );

		Network_Export\queue_export( array( $brisbane, $hobart ) );
		$this->drain_export();

		remove_action( 'switch_blog', $sabotage, 10 );

		$artifact = get_site_option( Network_Export\EXPORT_OPTION );
		$this->assertIsArray( $artifact, 'The export must still complete.' );
		$this->assertArrayHasKey( $hobart, $artifact['failed_sites'] );
		$this->assertSame( array( 'Brisbane Meetup' ), array_column( $artifact['rows'], 'event_title' ) );
		$this->assertSame( 1, $artifact['exported_site_count'] );
	}

	/**
	 * The download payload renders the artifact as CSV and JSON; with no
	 * artifact it errors instead of serving an empty file.
	 */
	public function test_download_payload() {
		$this->assertWPError( Network_Export\get_download_payload( 'csv' ) );

		$brisbane = $this->group_sites['brisbane'];
		$event_id = $this->create_site_event( $brisbane, 'Brisbane Meetup' );
		$this->create_site_rsvp( $brisbane, $event_id, 'attending' );

		Network_Export\queue_export( array( $brisbane ) );
		$this->drain_export();

		$csv = Network_Export\get_download_payload( 'csv' );
		$this->assertStringStartsWith( 'text/csv', $csv['content_type'] );
		$this->assertMatchesRegularExpression( '/^groups-network-events-\d{4}-\d{2}-\d{2}\.csv$/', $csv['filename'] );
		$this->assertStringStartsWith( "\xEF\xBB\xBF", $csv['body'], 'Excel needs the UTF-8 BOM.' );

		$rows = $this->parse_csv( $csv['body'] );
		$this->assertSame( Network_Export\CSV_COLUMNS, $rows[0] );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'Brisbane Meetup', $rows[1][3] );
		$this->assertSame( '1', $rows[1][7], 'attending_count column must carry the count.' );

		$json = Network_Export\get_download_payload( 'json' );
		$this->assertStringStartsWith( 'application/json', $json['content_type'] );
		$data = json_decode( $json['body'], true );
		$this->assertSame( 1, $data['event_count'] );
		$this->assertSame( 1, $data['exported_site_count'] );
		$this->assertSame( 'Brisbane', $data['groups'][0]['name'] );
		$this->assertSame( 1, $data['groups'][0]['events'][0]['counts']['attending'] );
	}

	/**
	 * The CSV is strict RFC 4180: quotes are doubled, a backslash is data,
	 * and separators inside a cell survive the round-trip.
	 */
	public function test_csv_quoting_round_trips() {
		$row = $this->build_row(
			array(
				'group_name'  => 'Group, Inc. "HQ"',
				// A backslash immediately before a quote: under PHP's default
				// escape character this field would not be quoted correctly
				// and the record would gain a column.
				'event_title' => 'Bad\\", Name',
				'venue'       => "Line\nbreak",
			)
		);

		$body = Network_Export\build_network_csv( array( $row ) );
		$this->assertStringStartsWith( "\xEF\xBB\xBF", $body );

		// Read it back with fgetcsv, which handles the newline inside the
		// venue cell as part of one record — the way a real reader does.
		$records = $this->read_csv_records( $body );

		$this->assertCount( 2, $records );
		$this->assertSame( Network_Export\CSV_COLUMNS, $records[0] );

		$record = $records[1];
		$this->assertCount( count( Network_Export\CSV_COLUMNS ), $record );
		$this->assertSame( 'Group, Inc. "HQ"', $record[0] );
		$this->assertSame( 'Bad\\", Name', $record[3] );
		$this->assertSame( "Line\nbreak", $record[6] );
	}

	/**
	 * Spreadsheet formula triggers are neutralised in every string column the
	 * export writes, not only in event titles.
	 */
	public function test_csv_cells_are_formula_escaped() {
		$row = $this->build_row(
			array(
				'group_name'  => '=cmd|"/c calc"!A1',
				'event_title' => '@SUM(A1)',
				'venue'       => '+1 Main Street',
			)
		);

		$rows = $this->parse_csv( Network_Export\build_network_csv( array( $row ) ) );

		$this->assertSame( "'=cmd|\"/c calc\"!A1", $rows[1][0], 'group_name must be escaped.' );
		$this->assertSame( "'@SUM(A1)", $rows[1][3], 'event_title must be escaped.' );
		$this->assertSame( "'+1 Main Street", $rows[1][6], 'venue must be escaped.' );
	}

	/**
	 * Data that can't be JSON-encoded fails the download loudly instead of
	 * serving a zero-byte file with a 200, and the CSV still works.
	 */
	public function test_unencodable_json_fails_loudly() {
		$artifact = array(
			'generated_gmt'       => '2026-01-02 03:04:05',
			'author'              => 0,
			'site_count'          => 1,
			'exported_site_count' => 1,
			'event_count'         => 1,
			'failed_sites'        => array(),

			/*
			 * A value `json_encode()` refuses outright. Invalid UTF-8 would
			 * not do: `wp_json_encode()` strips that rather than failing. This
			 * stands in for any encode failure — what's under test is that the
			 * failure becomes an error instead of a zero-byte 200.
			 */
			'rows'                => array( $this->build_row( array( 'attending_count' => INF ) ) ),
		);

		$inject = function () use ( $artifact ) {
			return $artifact;
		};
		add_filter( 'pre_site_option_' . Network_Export\EXPORT_OPTION, $inject );

		try {
			$json = Network_Export\get_download_payload( 'json' );
			$this->assertWPError( $json );
			$this->assertSame( 'export_encode_failed', $json->get_error_code() );

			$csv = Network_Export\get_download_payload( 'csv' );
			$this->assertIsArray( $csv, 'The CSV download must still work.' );
		} finally {
			remove_filter( 'pre_site_option_' . Network_Export\EXPORT_OPTION, $inject );
		}
	}

	/**
	 * No attendee or organiser identity survives into the artifact or either
	 * download format — the network export is aggregate-only by design.
	 */
	public function test_no_pii_in_artifact_or_output() {
		$brisbane = $this->group_sites['brisbane'];
		$event_id = $this->create_site_event( $brisbane, 'Brisbane Meetup' );

		$this->create_site_rsvp(
			$brisbane,
			$event_id,
			'attending',
			array(
				'display_name' => 'Patricia Privateperson',
				'user_login'   => 'patriciapriv',
				'user_email'   => 'patricia@example.test',
			)
		);

		$organiser_id = $this->with_site(
			$brisbane,
			function () use ( $event_id ) {
				return (int) get_post_field( 'post_author', $event_id );
			}
		);
		$organiser    = get_userdata( $organiser_id );

		Network_Export\queue_export( array( $brisbane ) );
		$this->drain_export();

		$haystacks = array(
			'artifact' => wp_json_encode( get_site_option( Network_Export\EXPORT_OPTION ) ),
			'csv'      => Network_Export\get_download_payload( 'csv' )['body'],
			'json'     => Network_Export\get_download_payload( 'json' )['body'],
		);

		foreach ( $haystacks as $label => $haystack ) {
			$this->assertStringNotContainsString( 'Patricia Privateperson', $haystack, "Attendee name leaked into the {$label}." );
			$this->assertStringNotContainsString( 'patriciapriv', $haystack, "Attendee login leaked into the {$label}." );
			$this->assertStringNotContainsString( 'patricia@example.test', $haystack, "Attendee email leaked into the {$label}." );
			$this->assertStringNotContainsString( $organiser->display_name, $haystack, "Organiser name leaked into the {$label}." );
		}
	}

	/**
	 * Read a CSV body back into records with `fgetcsv()`, which handles
	 * newlines inside quoted cells the way a real reader does.
	 *
	 * @param string $csv File body.
	 */
	protected function read_csv_records( string $csv ): array {
		$csv = preg_replace( '/^\xEF\xBB\xBF/', '', $csv );

		// phpcs:disable WordPress.WP.AlternativeFunctions -- In-memory stream, not a filesystem read.
		$handle = fopen( 'php://temp', 'r+' );
		fwrite( $handle, $csv );
		rewind( $handle );

		$records = array();
		while ( true ) {
			$record = fgetcsv( $handle, 0, ',', '"', '' );

			if ( false === $record || null === $record ) {
				break;
			}

			$records[] = $record;
		}

		fclose( $handle );
		// phpcs:enable WordPress.WP.AlternativeFunctions

		return $records;
	}

	/**
	 * A job in the shape `process_batch()` expects, with overrides applied.
	 *
	 * @param array $overrides Fields to replace.
	 */
	protected function build_job( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'            => 'test-job',
				'sites'         => array_values( $this->group_sites ),
				'pending_sites' => array_values( $this->group_sites ),
				'rows'          => array(),
				'failed_sites'  => array(),
				'site_attempts' => array(),
				'author'        => 0,
				'created'       => time(),
				'claimed_until' => 0,
				'claim_token'   => '',
			),
			$overrides
		);
	}

	/**
	 * An artifact row, with overrides applied.
	 *
	 * @param array $overrides Fields to replace.
	 */
	protected function build_row( array $overrides = array() ): array {
		return array_merge(
			array(
				'group_name'          => 'Brisbane',
				'group_url'           => 'https://events.wordpress.test/group/brisbane',
				'event_id'            => 1,
				'event_title'         => 'Brisbane Meetup',
				'event_start_gmt'     => '2026-02-01 08:00:00',
				'event_end_gmt'       => '2026-02-01 10:00:00',
				'venue'               => 'Salty Spaces',
				'attending_count'     => 1,
				'waiting_list_count'  => 0,
				'not_attending_count' => 0,
			),
			$overrides
		);
	}
}
