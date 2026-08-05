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
	 * The organiser audience is the "Organiser" tier only — editors and
	 * administrators. Authors ("Event Organisers") and members are excluded.
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
	 * #1775: a broadcast reaches every organiser on every group, and nobody
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
	 * Group organisers — even administrators of their own group — can't reach
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
