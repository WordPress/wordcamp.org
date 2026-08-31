<?php

namespace WordCamp\Groups\Tests;

use WordCamp\Groups\Messaging;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__, 2 ) . '/wporg-groups-frontend/tests/class-groups-testcase.php';

/**
 * @group groups
 */
class Test_Groups_Network_Messaging extends Groups_TestCase {
	/**
	 * Group sites created for the current test.
	 *
	 * @var int[]
	 */
	protected $group_sites = array();

	/**
	 * Emails captured during the current test.
	 *
	 * @var array[]
	 */
	protected $sent_mail = array();

	/**
	 * Create two group sites and intercept outgoing mail.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->group_sites = array(
			'brisbane' => $this->create_group_site( 'brisbane' ),
			'hobart'   => $this->create_group_site( 'hobart' ),
		);

		$this->sent_mail = array();

		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
	}

	/**
	 * Clean up sites, queued jobs, and scheduled batches.
	 */
	protected function tearDown(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );

		foreach ( $this->group_sites as $site_id ) {
			wp_delete_site( $site_id );
		}

		$this->group_sites = array();

		delete_site_option( Messaging\JOBS_OPTION );
		delete_site_option( Messaging\SUMMARY_OPTION );
		wp_clear_scheduled_hook( Messaging\CRON_HOOK );

		parent::tearDown();
	}

	/**
	 * Record mail instead of sending it.
	 *
	 * @param null|bool $short_circuit Whether to short-circuit `wp_mail()`.
	 * @param array     $atts          `wp_mail()` arguments.
	 * @return bool
	 */
	public function capture_mail( $short_circuit, $atts ) {
		$this->sent_mail[] = $atts;

		return true;
	}

	/**
	 * Create a site on the groups network.
	 *
	 * @param string $slug Group slug.
	 * @return int
	 */
	protected function create_group_site( string $slug ): int {
		return self::factory()->blog->create(
			array(
				'domain'     => 'events.wordpress.test',
				'path'       => "/group/$slug/",
				'network_id' => GROUPS_NETWORK_ID,
			)
		);
	}

	/**
	 * Add a user to a group site with a given role.
	 *
	 * @param int    $site_id Group site.
	 * @param string $role    Role to assign.
	 * @param string $email   Email address.
	 * @return int User ID.
	 */
	protected function add_member( int $site_id, string $role, string $email ): int {
		$user_id = self::factory()->user->create( array( 'user_email' => $email ) );

		add_user_to_blog( $site_id, $user_id, $role );

		return $user_id;
	}

	/**
	 * Get the "to" address of every captured email, lowercased.
	 *
	 * @return string[]
	 */
	protected function get_sent_addresses(): array {
		return array_map(
			function ( $mail ) {
				$to = is_array( $mail['to'] ) ? $mail['to'][0] : $mail['to'];

				// `send_message()` sends to `Display Name <email>`.
				if ( preg_match( '/<([^>]+)>/', $to, $matches ) ) {
					$to = $matches[1];
				}

				return strtolower( trim( $to ) );
			},
			$this->sent_mail
		);
	}

	/**
	 * Drain the whole queue, however many batches it takes.
	 */
	protected function process_queue(): void {
		$guard = 0;

		while ( Messaging\get_jobs() && $guard < 50 ) {
			Messaging\process_batch();
			++$guard;
		}
	}

	/**
	 * Every group on the network is a candidate recipient site, but the
	 * network's root site is the `/group/` landing page, not a group.
	 */
	public function test_group_site_ids_exclude_the_network_root() {
		$site_ids = Messaging\get_group_site_ids();

		$this->assertContains( $this->group_sites['brisbane'], $site_ids );
		$this->assertContains( $this->group_sites['hobart'], $site_ids );
		$this->assertNotContains( GROUPS_ROOT_BLOG_ID, $site_ids );
	}

	/**
	 * Sites on other networks are never messaged, even though they share the
	 * `events.wordpress.test` host.
	 */
	public function test_group_site_ids_exclude_other_networks() {
		$events_site_id = self::factory()->blog->create(
			array(
				'domain'     => 'events.wordpress.test',
				'path'       => '/othernetwork/2025/meetup/',
				'network_id' => EVENTS_NETWORK_ID,
			)
		);

		$this->assertNotContains( $events_site_id, Messaging\get_group_site_ids() );

		wp_delete_site( $events_site_id );
	}

	/**
	 * Archived groups are inactive, so their members shouldn't be emailed.
	 */
	public function test_group_site_ids_exclude_archived_groups() {
		wp_update_site( $this->group_sites['hobart'], array( 'archived' => 1 ) );

		$site_ids = Messaging\get_group_site_ids();

		$this->assertContains( $this->group_sites['brisbane'], $site_ids );
		$this->assertNotContains( $this->group_sites['hobart'], $site_ids );
	}

	/**
	 * The organizer audience is the "Organizer" tier only — editors and
	 * administrators. Authors ("Event Organizers") and members are excluded.
	 */
	public function test_organizer_audience_only_includes_editors_and_admins() {
		$site_id = $this->group_sites['brisbane'];

		$this->add_member( $site_id, 'editor', 'editor@example.test' );
		$this->add_member( $site_id, 'administrator', 'admin@example.test' );
		$this->add_member( $site_id, 'author', 'author@example.test' );
		$this->add_member( $site_id, 'subscriber', 'subscriber@example.test' );

		$emails = wp_list_pluck(
			Messaging\get_site_recipients( $site_id, Messaging\AUDIENCE_ORGANIZERS, 100 ),
			'email'
		);

		sort( $emails );

		$this->assertSame( array( 'admin@example.test', 'editor@example.test' ), $emails );
	}

	/**
	 * The member audience is everyone on the site, whatever their role.
	 */
	public function test_member_audience_includes_every_role() {
		$site_id = $this->group_sites['brisbane'];

		$this->add_member( $site_id, 'editor', 'editor@example.test' );
		$this->add_member( $site_id, 'author', 'author@example.test' );
		$this->add_member( $site_id, 'subscriber', 'subscriber@example.test' );

		$emails = wp_list_pluck(
			Messaging\get_site_recipients( $site_id, Messaging\AUDIENCE_MEMBERS, 100 ),
			'email'
		);

		$this->assertContains( 'editor@example.test', $emails );
		$this->assertContains( 'author@example.test', $emails );
		$this->assertContains( 'subscriber@example.test', $emails );
	}

	/**
	 * Queueing stores the job and schedules a batch, without sending anything
	 * in the submit request itself.
	 */
	public function test_queue_message_stores_a_job_and_schedules_a_batch() {
		Messaging\queue_message(
			'Subject',
			'Body',
			Messaging\AUDIENCE_ORGANIZERS,
			array_values( $this->group_sites )
		);

		$jobs = Messaging\get_jobs();

		$this->assertCount( 1, $jobs );
		$this->assertSame( 'Subject', $jobs[0]['subject'] );
		$this->assertSame( Messaging\AUDIENCE_ORGANIZERS, $jobs[0]['audience'] );
		$this->assertSame( array_values( $this->group_sites ), $jobs[0]['pending_sites'] );
		$this->assertIsInt( wp_next_scheduled( Messaging\CRON_HOOK ) );
		$this->assertSame( array(), $this->sent_mail );
	}

	/**
	 * #1775: a broadcast reaches every organizer on every group, and nobody
	 * else.
	 */
	public function test_broadcast_reaches_every_organizer_on_the_network() {
		$this->add_member( $this->group_sites['brisbane'], 'editor', 'brisbane-organiser@example.test' );
		$this->add_member( $this->group_sites['brisbane'], 'subscriber', 'brisbane-member@example.test' );
		$this->add_member( $this->group_sites['hobart'], 'administrator', 'hobart-organiser@example.test' );
		$this->add_member( $this->group_sites['hobart'], 'author', 'hobart-author@example.test' );

		Messaging\queue_message( 'Hello', 'Body', Messaging\AUDIENCE_ORGANIZERS, Messaging\get_group_site_ids() );
		$this->process_queue();

		$addresses = $this->get_sent_addresses();

		$this->assertContains( 'brisbane-organiser@example.test', $addresses );
		$this->assertContains( 'hobart-organiser@example.test', $addresses );
		$this->assertNotContains( 'brisbane-member@example.test', $addresses );
		$this->assertNotContains( 'hobart-author@example.test', $addresses );
	}

	/**
	 * #1776: a segmented message reaches every member of the selected groups,
	 * and nobody from the groups that weren't selected.
	 */
	public function test_segmented_message_reaches_only_the_selected_groups() {
		$this->add_member( $this->group_sites['brisbane'], 'subscriber', 'brisbane-member@example.test' );
		$this->add_member( $this->group_sites['hobart'], 'subscriber', 'hobart-member@example.test' );

		Messaging\queue_message(
			'Hello',
			'Body',
			Messaging\AUDIENCE_MEMBERS,
			array( $this->group_sites['brisbane'] )
		);
		$this->process_queue();

		$addresses = $this->get_sent_addresses();

		$this->assertContains( 'brisbane-member@example.test', $addresses );
		$this->assertNotContains( 'hobart-member@example.test', $addresses );
	}

	/**
	 * Someone who belongs to two selected groups is emailed once.
	 */
	public function test_recipients_in_overlapping_groups_are_emailed_once() {
		$user_id = $this->add_member( $this->group_sites['brisbane'], 'editor', 'both@example.test' );

		add_user_to_blog( $this->group_sites['hobart'], $user_id, 'editor' );

		Messaging\queue_message(
			'Hello',
			'Body',
			Messaging\AUDIENCE_ORGANIZERS,
			array( $this->group_sites['brisbane'], $this->group_sites['hobart'] )
		);
		$this->process_queue();

		$addresses = array_filter(
			$this->get_sent_addresses(),
			function ( $address ) {
				return 'both@example.test' === $address;
			}
		);

		$this->assertCount( 1, $addresses );
	}

	/**
	 * A large send is spread over several cron runs rather than blocking one
	 * request until it finishes.
	 */
	public function test_a_run_sends_at_most_one_batch() {
		$queue = array();

		for ( $i = 0; $i < Messaging\BATCH_SIZE + 10; $i++ ) {
			$queue[] = array(
				'email' => "recipient$i@example.test",
				'name'  => "Recipient $i",
			);
		}

		update_site_option(
			Messaging\JOBS_OPTION,
			array(
				array(
					'id'            => 'test-job',
					'subject'       => 'Hello',
					'body'          => 'Body',
					'audience'      => Messaging\AUDIENCE_ORGANIZERS,
					'sites'         => array_values( $this->group_sites ),
					'pending_sites' => array(),
					'site_offset'   => 0,
					'queue'         => $queue,
					'sent'          => array(),
					'sent_count'    => 0,
					'author'        => 0,
					'created'       => time(),
				),
			)
		);

		Messaging\process_batch();

		$this->assertCount( Messaging\BATCH_SIZE, $this->sent_mail );

		$jobs = Messaging\get_jobs();

		$this->assertCount( 1, $jobs, 'The unfinished job should stay queued.' );
		$this->assertCount( 10, $jobs[0]['queue'] );
		$this->assertIsInt( wp_next_scheduled( Messaging\CRON_HOOK ), 'Another batch should be scheduled.' );

		$this->process_queue();

		$this->assertCount( Messaging\BATCH_SIZE + 10, $this->sent_mail );
		$this->assertSame( array(), Messaging\get_jobs() );
	}

	/**
	 * Duplicates are work too. A recipient who belongs to many selected groups
	 * is skipped rather than mailed, and a run made entirely of such skips
	 * must still stop at the batch limit instead of draining the whole queue.
	 */
	public function test_duplicate_recipients_still_count_against_the_batch() {
		$queue = array();
		$sent  = array();

		for ( $i = 0; $i < Messaging\BATCH_SIZE + 10; $i++ ) {
			$queue[] = array(
				'email' => "recipient$i@example.test",
				'name'  => "Recipient $i",
			);

			$sent[ "recipient$i@example.test" ] = true;
		}

		update_site_option(
			Messaging\JOBS_OPTION,
			array(
				array(
					'id'            => 'test-job',
					'subject'       => 'Hello',
					'body'          => 'Body',
					'audience'      => Messaging\AUDIENCE_ORGANIZERS,
					'sites'         => array_values( $this->group_sites ),
					'pending_sites' => array(),
					'site_offset'   => 0,
					'queue'         => $queue,
					'sent'          => $sent,
					'sent_count'    => 0,
					'author'        => 0,
					'created'       => time(),
				),
			)
		);

		Messaging\process_batch();

		$jobs = Messaging\get_jobs();

		$this->assertSame( array(), $this->sent_mail, 'Already-mailed addresses should not be mailed again.' );
		$this->assertCount( 1, $jobs, 'The job should not have drained in one run.' );
		$this->assertCount( 10, $jobs[0]['queue'] );
	}

	/**
	 * A finished job leaves the queue and is summarised for the admin screen.
	 */
	public function test_completed_job_is_summarised() {
		$this->add_member( $this->group_sites['brisbane'], 'editor', 'organiser@example.test' );

		Messaging\queue_message(
			'Newsletter',
			'Body',
			Messaging\AUDIENCE_ORGANIZERS,
			array( $this->group_sites['brisbane'] )
		);
		$this->process_queue();

		$summary = get_site_option( Messaging\SUMMARY_OPTION );

		$this->assertSame( array(), Messaging\get_jobs() );
		$this->assertSame( 'Newsletter', $summary['subject'] );
		$this->assertSame( 1, $summary['sent_count'] );
		$this->assertSame( 1, $summary['site_count'] );
	}

	/**
	 * A display name is user-controlled, and `wp_mail()` splits a string `to`
	 * on commas — so one containing a comma must not be able to add a second
	 * recipient to every message of a broadcast.
	 */
	public function test_display_name_cannot_smuggle_in_an_extra_recipient() {
		$user_id = $this->add_member( $this->group_sites['brisbane'], 'editor', 'organiser@example.test' );

		wp_update_user(
			array(
				'ID'           => $user_id,
				'display_name' => 'evil@example.test, Jane',
			)
		);

		Messaging\queue_message(
			'Hello',
			'Body',
			Messaging\AUDIENCE_ORGANIZERS,
			array( $this->group_sites['brisbane'] )
		);
		$this->process_queue();

		$this->assertCount( 1, $this->sent_mail );

		$to = $this->sent_mail[0]['to'];

		// Mirror what core does with the value it was handed: a string `to` is
		// split on commas, an array is taken as-is. Passing the string form
		// here would yield two addresses.
		$recipients = is_array( $to ) ? $to : explode( ',', $to );

		$this->assertCount( 1, $recipients, 'The display name must not be split into a second recipient.' );
		$this->assertStringContainsString( 'organiser@example.test', $recipients[0] );
	}

	/**
	 * A transport failure must not retire the recipient. Marking the address
	 * done before `wp_mail()` returned meant a transient failure dropped that
	 * person from the send permanently, with nothing recorded.
	 */
	public function test_a_failed_send_is_not_marked_as_delivered() {
		$this->add_member( $this->group_sites['brisbane'], 'editor', 'organiser@example.test' );

		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );
		add_filter( 'pre_wp_mail', '__return_false' );

		Messaging\queue_message(
			'Hello',
			'Body',
			Messaging\AUDIENCE_ORGANIZERS,
			array( $this->group_sites['brisbane'] )
		);
		$this->process_queue();

		remove_filter( 'pre_wp_mail', '__return_false' );

		$summary = get_site_option( Messaging\SUMMARY_OPTION );

		$this->assertSame( 0, $summary['sent_count'] );
		$this->assertSame( 1, $summary['failed_count'], 'The failure should be counted, not silently swallowed.' );
	}

	/**
	 * A `Throwable` escaping the send loop -- a rogue mail filter, a `TypeError`,
	 * anything `send_message()` doesn't turn into a clean `false` -- must not
	 * lose the job. The claim step already removed it from `JOBS_OPTION` before
	 * the loop runs, so without a `catch` around the loop, a crash skips the
	 * commit and the job -- and everyone still in it -- vanishes: not sent,
	 * not re-queued, not summarised, nothing in the log to explain why.
	 */
	public function test_a_crash_mid_batch_does_not_lose_the_job() {
		$this->add_member( $this->group_sites['brisbane'], 'editor', 'organiser@example.test' );

		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );

		$throw = function () {
			throw new \Error( 'Simulated fatal mid-batch' );
		};

		add_filter( 'pre_wp_mail', $throw );

		Messaging\queue_message(
			'Hello',
			'Body',
			Messaging\AUDIENCE_ORGANIZERS,
			array( $this->group_sites['brisbane'] )
		);
		Messaging\process_batch();

		remove_filter( 'pre_wp_mail', $throw );

		$jobs = Messaging\get_jobs();

		$this->assertCount( 1, $jobs, 'The job must still be queued for retry, not silently dropped.' );
		$this->assertContains(
			$this->group_sites['brisbane'],
			$jobs[0]['pending_sites'],
			'The recipient the crash was mid-send to must still be pending.'
		);
		$this->assertIsInt( wp_next_scheduled( Messaging\CRON_HOOK ), 'A retry must still be scheduled.' );
	}

	/**
	 * A recipient who failed on one group is retried when they turn up again
	 * through another — the `sent` map is a record of delivery, not of
	 * attempts.
	 */
	public function test_a_failed_recipient_is_retried_via_another_group() {
		$user_id = $this->add_member( $this->group_sites['brisbane'], 'editor', 'both@example.test' );

		add_user_to_blog( $this->group_sites['hobart'], $user_id, 'editor' );

		$attempts = 0;

		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );

		$fail_first = function () use ( &$attempts ) {
			++$attempts;

			return 1 === $attempts ? false : true;
		};

		add_filter( 'pre_wp_mail', $fail_first );

		Messaging\queue_message( 'Hello', 'Body', Messaging\AUDIENCE_ORGANIZERS, array_values( $this->group_sites ) );
		$this->process_queue();

		remove_filter( 'pre_wp_mail', $fail_first );

		$this->assertSame( 2, $attempts, 'The second membership should give the failed address another attempt.' );

		$summary = get_site_option( Messaging\SUMMARY_OPTION );

		$this->assertSame( 1, $summary['sent_count'] );
		$this->assertSame( 1, $summary['failed_count'] );
	}

	/**
	 * A message queued while a batch is mid-flight must survive the batch
	 * writing its own progress back. Both sides read-modify-write the same
	 * option, so without the lock the batch's write would drop the new job.
	 */
	public function test_a_message_queued_during_a_batch_is_not_lost() {
		$this->add_member( $this->group_sites['brisbane'], 'editor', 'organiser@example.test' );

		Messaging\queue_message(
			'First',
			'Body',
			Messaging\AUDIENCE_ORGANIZERS,
			array( $this->group_sites['brisbane'] )
		);

		// Queue a second message from "another request" while the first job is
		// claimed and mid-send, which is exactly the interleaving that used to
		// lose it.
		$queue_during_send = function ( $short_circuit, $atts ) {
			static $queued = false;

			if ( ! $queued ) {
				$queued = true;

				Messaging\queue_message(
					'Second',
					'Body',
					Messaging\AUDIENCE_ORGANIZERS,
					array( $this->group_sites['hobart'] )
				);
			}

			return $this->capture_mail( $short_circuit, $atts );
		};

		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );
		add_filter( 'pre_wp_mail', $queue_during_send, 10, 2 );

		Messaging\process_batch();

		remove_filter( 'pre_wp_mail', $queue_during_send, 10 );

		$subjects = wp_list_pluck( Messaging\get_jobs(), 'subject' );

		$this->assertContains( 'Second', $subjects, 'The concurrently queued job should still be in the queue.' );
	}

	/**
	 * Group organizers — even administrators of their own group — can't reach
	 * the whole network.
	 */
	public function test_group_administrators_cannot_send_messages() {
		$user_id = $this->add_member( $this->group_sites['brisbane'], 'administrator', 'group-admin@example.test' );

		wp_set_current_user( $user_id );

		$this->assertFalse( Messaging\current_user_can_send_messages() );
	}

	/**
	 * Network admins (Program Managers) can.
	 */
	public function test_network_admins_can_send_messages() {
		$user_id = self::factory()->user->create();

		// `grant_super_admin()` reads `site_admins` off whichever network is
		// current, which in this fixture isn't the groups network. Set the
		// option `get_super_admins()` actually consults instead.
		$original = get_site_option( 'site_admins' );

		update_site_option( 'site_admins', array( get_userdata( $user_id )->user_login ) );
		wp_set_current_user( $user_id );

		$this->assertTrue( Messaging\current_user_can_send_messages() );

		update_site_option( 'site_admins', $original );
	}
}
