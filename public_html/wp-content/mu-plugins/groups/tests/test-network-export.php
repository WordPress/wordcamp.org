<?php

namespace WordCamp\Groups\Tests;

use WP_REST_Request;
use WordCamp\Groups\Network_Export;

use function WordCamp\Groups\Frontend\REST\create_event;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__, 2 ) . '/wporg-groups-frontend/tests/class-groups-testcase.php';

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
	 * Group organisers — even administrators of their own group — can't
	 * export the whole network.
	 */
	public function test_group_administrators_cannot_export() {
		$user_id = self::factory()->user->create();
		add_user_to_blog( $this->group_sites['brisbane'], $user_id, 'administrator' );

		wp_set_current_user( $user_id );

		$this->assertFalse( Network_Export\current_user_can_export() );
	}

	/**
	 * Network admins (Program Managers) can.
	 */
	public function test_network_admins_can_export() {
		$user_id = self::factory()->user->create();

		// `grant_super_admin()` reads `site_admins` off whichever network is
		// current, which in this fixture isn't the groups network. Set the
		// option `get_super_admins()` actually consults instead.
		$original = get_site_option( 'site_admins' );

		update_site_option( 'site_admins', array( get_userdata( $user_id )->user_login ) );
		wp_set_current_user( $user_id );

		$this->assertTrue( Network_Export\current_user_can_export() );

		update_site_option( 'site_admins', $original );
	}

	/**
	 * The start handler itself rejects non-network-admins — the capability
	 * gate must live in the handler, not only in the menu registration.
	 */
	public function test_start_handler_rejects_group_administrators() {
		$user_id = self::factory()->user->create();
		add_user_to_blog( $this->group_sites['brisbane'], $user_id, 'administrator' );
		wp_set_current_user( $user_id );

		$this->expectException( 'WPDieException' );
		$this->expectExceptionMessage( 'permission' );

		Network_Export\handle_start_export();
	}

	/**
	 * The download handler rejects non-network-admins the same way.
	 */
	public function test_download_handler_rejects_group_administrators() {
		$user_id = self::factory()->user->create();
		add_user_to_blog( $this->group_sites['brisbane'], $user_id, 'administrator' );
		wp_set_current_user( $user_id );

		$this->expectException( 'WPDieException' );
		$this->expectExceptionMessage( 'permission' );

		Network_Export\handle_download();
	}

	/**
	 * Even a network admin can't start or download without a valid nonce.
	 */
	public function test_handlers_require_a_valid_nonce() {
		$user_id  = self::factory()->user->create();
		$original = get_site_option( 'site_admins' );

		update_site_option( 'site_admins', array( get_userdata( $user_id )->user_login ) );
		wp_set_current_user( $user_id );

		try {
			$died = 0;

			foreach ( array( 'handle_start_export', 'handle_download' ) as $handler ) {
				try {
					call_user_func( "WordCamp\Groups\Network_Export\\{$handler}" );
				} catch ( \WPDieException $e ) {
					++$died;
				}
			}

			$this->assertSame( 2, $died, 'Both handlers must die on a missing nonce.' );
		} finally {
			update_site_option( 'site_admins', $original );
		}
	}

	/**
	 * A batch in progress holds a lease on the job: a concurrent run must
	 * not double-process it, and a new start must still see it as busy.
	 */
	public function test_batch_claim_blocks_concurrent_runs() {
		$job = array(
			'id'            => 'leased-job',
			'sites'         => array_values( $this->group_sites ),
			'pending_sites' => array_values( $this->group_sites ),
			'rows'          => array(),
			'failed_sites'  => array(),
			'author'        => 0,
			'created'       => time(),
			'claimed_until' => time() + 100,
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
			array(
				'id'            => 'abandoned-job',
				'sites'         => array( $this->group_sites['brisbane'] ),
				'pending_sites' => array( $this->group_sites['brisbane'] ),
				'rows'          => array(),
				'failed_sites'  => array(),
				'author'        => 0,
				'created'       => time(),
				'claimed_until' => time() - 10,
			)
		);

		$this->drain_export();

		$this->assertSame( array(), Network_Export\get_job() );
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
	 * A single run processes at most `SITES_PER_BATCH` sites, then
	 * reschedules; draining finishes the job.
	 */
	public function test_a_run_processes_at_most_one_batch() {
		$pending = array();
		for ( $i = 0; $i < Network_Export\SITES_PER_BATCH + 3; $i++ ) {
			$pending[] = array_values( $this->group_sites )[ $i % 2 ];
		}

		update_site_option(
			Network_Export\JOB_OPTION,
			array(
				'id'            => 'test-job',
				'sites'         => $pending,
				'pending_sites' => $pending,
				'rows'          => array(),
				'failed_sites'  => array(),
				'author'        => 0,
				'created'       => time(),
			)
		);

		Network_Export\process_batch();

		$job = Network_Export\get_job();
		$this->assertCount( 3, $job['pending_sites'], 'One run must stop at the batch boundary.' );
		$this->assertNotFalse( wp_next_scheduled( Network_Export\CRON_HOOK ), 'The remainder must be rescheduled.' );

		$this->drain_export();

		$this->assertSame( array(), Network_Export\get_job() );
		$this->assertIsArray( get_site_option( Network_Export\EXPORT_OPTION ) );
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

		$lines = array_filter( explode( "\n", trim( preg_replace( '/^\xEF\xBB\xBF/', '', $csv['body'] ) ) ), 'strlen' );
		$rows  = array_map(
			static function ( $line ) {
				return str_getcsv( $line, ',', '"', '' );
			},
			array_values( $lines )
		);
		$this->assertSame( Network_Export\CSV_COLUMNS, $rows[0] );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'Brisbane Meetup', $rows[1][3] );
		$this->assertSame( '1', $rows[1][7], 'attending_count column must carry the count.' );

		$json = Network_Export\get_download_payload( 'json' );
		$this->assertStringStartsWith( 'application/json', $json['content_type'] );
		$data = json_decode( $json['body'], true );
		$this->assertSame( 1, $data['event_count'] );
		$this->assertSame( 'Brisbane', $data['groups'][0]['name'] );
		$this->assertSame( 1, $data['groups'][0]['events'][0]['counts']['attending'] );
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
			'artifact' => serialize( get_site_option( Network_Export\EXPORT_OPTION ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Flattening for a substring assertion only.
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
	 * Spreadsheet formula triggers in group data are neutralised in the CSV.
	 */
	public function test_csv_cells_are_formula_escaped() {
		$brisbane = $this->group_sites['brisbane'];
		$this->create_site_event( $brisbane, '=HYPERLINK("https://evil.example")' );

		Network_Export\queue_export( array( $brisbane ) );
		$this->drain_export();

		$body = Network_Export\get_download_payload( 'csv' )['body'];

		$this->assertStringContainsString( "'=HYPERLINK", $body );
		$this->assertStringNotContainsString( "\n\"=HYPERLINK", $body );
	}
}
