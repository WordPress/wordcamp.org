<?php

namespace WordCamp\Groups\Tests;

use GatherPress\Core\Event\Event;
use GatherPress\Core\Venue\Venue;
use WordCamp\Groups\Meetup_Import;
use WPDieException;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__, 2 ) . '/wporg-groups-frontend/tests/class-groups-testcase.php';

/**
 * @group groups
 */
class Test_Meetup_Import extends Groups_TestCase {

	/**
	 * Group site the tests import into.
	 *
	 * @var int
	 */
	protected $site_id = 0;

	/**
	 * Emails captured during the current test, via `pre_wp_mail`.
	 *
	 * @var array
	 */
	protected $sent_mail = array();

	/**
	 * Create a group site with working GatherPress tables, and capture mail.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->site_id = self::factory()->blog->create(
			array(
				'domain'     => 'events.wordpress.test',
				'path'       => '/group/greenfield/',
				'network_id' => GROUPS_NETWORK_ID,
			)
		);

		$this->on_site(
			static function () {
				\GatherPress\Core\Setup::get_instance()->check_plugin_version();
			}
		);

		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );

		// Defaults for the requests every form submission makes. Tests that
		// care about one return their own from a later-priority callback.
		add_filter(
			'wcorg_meetup_import_pre_fetch_group',
			function () {
				return $this->raw_group();
			},
			5
		);
		add_filter( 'wcorg_meetup_import_pre_fetch_leaders', '__return_empty_array', 5 );
		add_filter( 'wcorg_meetup_import_pre_fetch_rsvps', '__return_empty_array', 5 );
	}

	/**
	 * Remove the site, filters, and request globals.
	 */
	protected function tearDown(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );
		remove_all_filters( 'wcorg_meetup_import_pre_fetch' );
		remove_all_filters( 'wcorg_meetup_import_pre_fetch_members' );
		remove_all_filters( 'wcorg_meetup_import_pre_sideload_photo' );
		remove_all_filters( 'wcorg_meetup_import_pre_fetch_group' );
		remove_all_filters( 'wcorg_meetup_import_pre_fetch_leaders' );
		remove_all_filters( 'wcorg_meetup_import_pre_fetch_rsvps' );
		Meetup_Import\release_lock();

		wp_delete_site( $this->site_id );

		unset( $_POST['wcorg_meetup_import_step'], $_POST['meetup_group'], $_POST['site_id'], $_POST[ Meetup_Import\NONCE_NAME ], $_REQUEST[ Meetup_Import\NONCE_NAME ] );

		$this->sent_mail = array();

		parent::tearDown();
	}

	/**
	 * Record an outgoing email instead of sending it.
	 *
	 * @param null|bool $short_circuit Short-circuit value.
	 * @param array     $atts          `wp_mail()` arguments.
	 */
	public function capture_mail( $short_circuit, $atts ) {
		$this->sent_mail[] = $atts;

		return true;
	}

	/**
	 * Run a callback on the test group site.
	 *
	 * @param callable $callback What to run there.
	 * @return mixed
	 */
	protected function on_site( callable $callback ) {
		switch_to_blog( $this->site_id );

		try {
			return $callback();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * A Meetup group node, as `fetch_group()` returns it.
	 *
	 * @param array $overrides Fields to replace.
	 */
	protected function raw_group( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'            => '26903462',
				'name'          => 'Western Mass WordPress Meetups',
				'urlname'       => 'wma-wordpress-meetup',
				'description'   => "We're a group of local WordPress users.\n\nJoin us!",
				'city'          => 'Greenfield',
				'country'       => 'us',
				'foundedDate'   => '2017-06-01T10:00:00-04:00',
				'keyGroupPhoto' => array(
					'id'         => '480000001',
					'highResUrl' => 'https://secure.meetupstatic.com/photos/event/1/2/3/4/highres_480000001.jpeg',
				),
			),
			$overrides
		);
	}

	/**
	 * A Meetup event node shaped like `Meetup_Client::get_group_events()` returns.
	 *
	 * @param array $overrides Fields to replace.
	 */
	protected function raw_event( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'          => '315889305',
				'title'       => 'Greenfield WordPress Meetup - General Q&A',
				'description' => "Come join us!\n\nBring a project.",
				'eventUrl'    => 'https://www.meetup.com/wma-wordpress-meetup/events/315889305/',
				'status'      => 'ACTIVE',
				'dateTime'    => '2026-11-11T18:00:00-05:00',
				'endTime'     => '2026-11-11T20:00:00-05:00',
				'duration'    => 7200,
				'eventType'   => 'PHYSICAL',
				'howToFindUs' => 'Room E103',
				'group'       => array( 'timezone' => 'America/New_York' ),
				'venues'      => array(
					array(
						'id'         => '27654321',
						'name'       => 'Greenfield Community College',
						'address'    => '1 College Dr',
						'city'       => 'Greenfield',
						'state'      => 'MA',
						'postalCode' => '01301',
						'country'    => 'us',
					),
				),
			),
			$overrides
		);
	}

	/**
	 * The imported event post for a Meetup ID, in any status.
	 *
	 * @param string $meetup_id Meetup event ID.
	 */
	protected function find_event( string $meetup_id ): ?\WP_Post {
		return $this->on_site(
			static function () use ( $meetup_id ) {
				return Meetup_Import\find_imported_post( Event::POST_TYPE, Meetup_Import\EVENT_ID_META, $meetup_id );
			}
		);
	}

	/**
	 * Count posts of a type on the test site, in any status.
	 *
	 * @param string $post_type Post type.
	 */
	protected function count_posts( string $post_type ): int {
		return $this->on_site(
			static function () use ( $post_type ) {
				return count(
					get_posts(
						array(
							'post_type'      => $post_type,
							'post_status'    => 'any,trash',
							'posts_per_page' => -1,
							'fields'         => 'ids',
						)
					)
				);
			}
		);
	}

	/**
	 * @dataProvider data_parse_group_urlname
	 *
	 * @param string $input    What an admin pastes.
	 * @param string $expected The urlname.
	 */
	public function test_parse_group_urlname( string $input, string $expected ) {
		$this->assertSame( $expected, Meetup_Import\parse_group_urlname( $input ) );
	}

	/**
	 * Inputs for `test_parse_group_urlname()`.
	 */
	public function data_parse_group_urlname(): array {
		return array(
			'bare urlname'    => array( 'wma-wordpress-meetup', 'wma-wordpress-meetup' ),
			'group url'       => array( 'https://www.meetup.com/wma-wordpress-meetup/', 'wma-wordpress-meetup' ),
			'event url'       => array( 'https://www.meetup.com/wma-wordpress-meetup/events/315889299/', 'wma-wordpress-meetup' ),
			'no scheme'       => array( 'meetup.com/wma-wordpress-meetup', 'wma-wordpress-meetup' ),
			'query string'    => array( 'https://www.meetup.com/wma-wordpress-meetup?utm=x', 'wma-wordpress-meetup' ),
			'other domain'    => array( 'https://example.com/wma-wordpress-meetup/', '' ),
			'invalid chars'   => array( 'foo bar', '' ),
			'empty'           => array( '', '' ),
		);
	}

	/**
	 * A physical event maps to local times in the group's timezone, with its venue.
	 */
	public function test_normalize_physical_event() {
		$event = Meetup_Import\normalize_event( $this->raw_event() );

		$this->assertSame( '315889305', $event['meetup_id'] );
		$this->assertSame( 'America/New_York', $event['timezone'] );
		$this->assertSame( '2026-11-11 18:00:00', $event['start'] );
		$this->assertSame( '2026-11-11 20:00:00', $event['end'] );
		$this->assertFalse( $event['is_online'] );
		$this->assertSame( '27654321', $event['venue']['meetup_id'] );
		$this->assertSame( 'Greenfield Community College', $event['venue']['name'] );
		$this->assertSame( '1 College Dr, Greenfield, MA 01301, US', $event['venue']['address'] );
		$this->assertStringContainsString( '<p>Bring a project.</p>', $event['description'] );
		$this->assertStringContainsString( 'How to find us: Room E103', $event['description'] );
	}

	/**
	 * Meetup's offset is converted, not trusted as the group's local time.
	 */
	public function test_normalize_converts_to_group_timezone() {
		$event = Meetup_Import\normalize_event(
			$this->raw_event(
				array(
					'dateTime' => '2026-11-11T23:00:00Z',
					'endTime'  => '2026-11-12T01:00:00Z',
				)
			)
		);

		$this->assertSame( '2026-11-11 18:00:00', $event['start'] );
		$this->assertSame( '2026-11-11 20:00:00', $event['end'] );
	}

	/**
	 * Without an end time, the duration (in seconds after the client's back-compat) is used.
	 */
	public function test_normalize_falls_back_to_duration() {
		$event = Meetup_Import\normalize_event(
			$this->raw_event(
				array(
					'endTime'  => null,
					'duration' => 5400,
				)
			)
		);

		$this->assertSame( '2026-11-11 19:30:00', $event['end'] );
	}

	/**
	 * An online event drops Meetup's placeholder venue.
	 */
	public function test_normalize_online_event_ignores_placeholder_venue() {
		$event = Meetup_Import\normalize_event(
			$this->raw_event(
				array(
					'eventType' => 'ONLINE',
					'venues'    => array(
						array(
							'id'   => (string) Meetup_Import\ONLINE_VENUE_ID,
							'name' => 'Online event',
						),
					),
				)
			)
		);

		$this->assertTrue( $event['is_online'] );
		$this->assertNull( $event['venue'] );
	}

	/**
	 * A hybrid event is online and keeps its physical venue.
	 */
	public function test_normalize_hybrid_event() {
		$event = Meetup_Import\normalize_event( $this->raw_event( array( 'eventType' => 'HYBRID' ) ) );

		$this->assertTrue( $event['is_online'] );
		$this->assertSame( '27654321', $event['venue']['meetup_id'] );
	}

	/**
	 * Markup from Meetup is run through `wp_kses_post()`.
	 */
	public function test_normalize_sanitizes_description() {
		$event = Meetup_Import\normalize_event(
			$this->raw_event( array( 'description' => 'Hi <script>alert(1)</script><strong>there</strong>' ) )
		);

		$this->assertStringNotContainsString( '<script', $event['description'] );
		$this->assertStringContainsString( '<strong>there</strong>', $event['description'] );
	}

	/**
	 * Happy path: events land as drafts with datetimes, venue, and source meta.
	 */
	public function test_import_creates_draft_events() {
		$events  = array( Meetup_Import\normalize_event( $this->raw_event() ) );
		$results = Meetup_Import\import_events( $this->site_id, $events );

		$this->assertSame( 'created', $results[0]['status'] );

		$post = $this->find_event( '315889305' );
		$this->assertNotNull( $post );
		$this->assertSame( 'draft', $post->post_status );
		// Core's kses title filter stores `&` as `&amp;`, as it does for titles typed in the frontend.
		$this->assertSame( 'Greenfield WordPress Meetup - General Q&A', html_entity_decode( $post->post_title ) );

		$this->on_site(
			function () use ( $post ) {
				$this->assertSame( '2026-11-11 18:00:00', get_post_meta( $post->ID, 'gatherpress_datetime_start', true ) );
				$this->assertSame( '2026-11-11 20:00:00', get_post_meta( $post->ID, 'gatherpress_datetime_end', true ) );
				$this->assertSame(
					'https://www.meetup.com/wma-wordpress-meetup/events/315889305/',
					get_post_meta( $post->ID, Meetup_Import\EVENT_URL_META, true )
				);

				$venue_post = Meetup_Import\find_imported_post( Venue::POST_TYPE, Meetup_Import\VENUE_ID_META, '27654321' );
				$this->assertNotNull( $venue_post );
				$this->assertSame( '1 College Dr, Greenfield, MA 01301, US', get_post_meta( $venue_post->ID, 'gatherpress_address', true ) );

				$term = ( new Venue( $venue_post->ID ) )->get_term();
				$this->assertTrue( has_term( $term->term_id, $term->taxonomy, $post->ID ) );
				$this->assertEmpty( get_post_meta( $post->ID, 'gatherpress_online_event_link', true ) );
			}
		);

		$this->assertSame( array(), $this->sent_mail );
	}

	/**
	 * Running the same import twice creates nothing new.
	 */
	public function test_rerun_is_idempotent() {
		$events = array(
			Meetup_Import\normalize_event( $this->raw_event() ),
			Meetup_Import\normalize_event( $this->raw_event( array(
				'id' => '315889306', 'dateTime' => '2026-12-09T18:00:00-05:00', 'endTime' => '2026-12-09T20:00:00-05:00',
			) ) ),
		);

		Meetup_Import\import_events( $this->site_id, $events );
		$results = Meetup_Import\import_events( $this->site_id, $events );

		$this->assertSame( array( 'updated', 'updated' ), wp_list_pluck( $results, 'status' ) );
		$this->assertSame( 2, $this->count_posts( Event::POST_TYPE ) );
		// Both events share one Meetup venue.
		$this->assertSame( 1, $this->count_posts( Venue::POST_TYPE ) );
	}

	/**
	 * A draft picks up a date change made on Meetup.
	 */
	public function test_rerun_refreshes_draft() {
		Meetup_Import\import_events( $this->site_id, array( Meetup_Import\normalize_event( $this->raw_event() ) ) );

		$moved = $this->raw_event(
			array(
				'title'    => 'Moved meetup',
				'dateTime' => '2026-11-18T18:00:00-05:00',
				'endTime'  => '2026-11-18T20:00:00-05:00',
			)
		);
		Meetup_Import\import_events( $this->site_id, array( Meetup_Import\normalize_event( $moved ) ) );

		$post = $this->find_event( '315889305' );
		$this->assertSame( 'Moved meetup', $post->post_title );
		$this->assertSame(
			'2026-11-18 18:00:00',
			$this->on_site(
				static function () use ( $post ) {
					return get_post_meta( $post->ID, 'gatherpress_datetime_start', true );
				}
			)
		);
	}

	/**
	 * Once published or trashed, the organizer owns the event: reruns leave it alone.
	 *
	 * @dataProvider data_owned_statuses
	 *
	 * @param string $status Post status the organizer moved it to.
	 */
	public function test_rerun_skips_owned_events( string $status ) {
		Meetup_Import\import_events( $this->site_id, array( Meetup_Import\normalize_event( $this->raw_event() ) ) );

		$post = $this->find_event( '315889305' );
		$this->on_site(
			static function () use ( $post, $status ) {
				wp_update_post(
					array(
						'ID'          => $post->ID,
						'post_status' => $status,
						'post_title'  => 'Organizer title',
					)
				);
			}
		);
		$this->sent_mail = array();

		$results = Meetup_Import\import_events(
			$this->site_id,
			array( Meetup_Import\normalize_event( $this->raw_event( array( 'title' => 'Meetup title' ) ) ) )
		);

		$this->assertSame( 'skipped', $results[0]['status'] );
		$this->assertSame( 'Organizer title', $this->find_event( '315889305' )->post_title );
		$this->assertSame( 1, $this->count_posts( Event::POST_TYPE ) );
		$this->assertSame( array(), $this->sent_mail );
	}

	/**
	 * Statuses for `test_rerun_skips_owned_events()`.
	 */
	public function data_owned_statuses(): array {
		return array(
			'published' => array( 'publish' ),
			'trashed'   => array( 'trash' ),
		);
	}

	/**
	 * Online events get the online term and no link.
	 */
	public function test_import_online_event() {
		$raw = $this->raw_event(
			array(
				'eventType' => 'ONLINE',
				'venues'    => array(),
			)
		);
		Meetup_Import\import_events( $this->site_id, array( Meetup_Import\normalize_event( $raw ) ) );

		$post = $this->find_event( '315889305' );

		$this->on_site(
			function () use ( $post ) {
				$taxonomy = \GatherPress\Core\Venue\Setup::get_instance()->taxonomy_for_event_post_type( Event::POST_TYPE );

				$this->assertTrue( has_term( 'online-event', $taxonomy, $post->ID ) );
				$this->assertEmpty( get_post_meta( $post->ID, 'gatherpress_online_event_link', true ) );
			}
		);
		$this->assertSame( 0, $this->count_posts( Venue::POST_TYPE ) );
	}

	/**
	 * The preview reports what would happen and writes nothing.
	 */
	public function test_plan_writes_nothing() {
		$events = array( Meetup_Import\normalize_event( $this->raw_event() ) );

		$plan = Meetup_Import\plan_events( $this->site_id, $events );
		$this->assertSame( 'create', $plan[0]['status'] );
		$this->assertSame( 0, $this->count_posts( Event::POST_TYPE ) );

		Meetup_Import\import_events( $this->site_id, $events );
		$this->assertSame( 'update', Meetup_Import\plan_events( $this->site_id, $events )[0]['status'] );
	}

	/**
	 * Only live group sites can be imported into.
	 */
	public function test_rejects_invalid_target_sites() {
		$events = array( Meetup_Import\normalize_event( $this->raw_event() ) );

		$this->assertWPError( Meetup_Import\import_events( GROUPS_ROOT_BLOG_ID, $events ) );
		$this->assertWPError( Meetup_Import\import_events( 999999, $events ) );

		update_blog_status( $this->site_id, 'archived', 1 );
		$this->assertWPError( Meetup_Import\import_events( $this->site_id, $events ) );
		$this->assertSame( 0, $this->count_posts( Event::POST_TYPE ) );
	}

	/**
	 * Users without `manage_sites` can't run the import.
	 */
	public function test_form_handler_requires_manage_sites() {
		$user_id = self::factory()->user->create();
		add_user_to_blog( $this->site_id, $user_id, 'administrator' );
		wp_set_current_user( $user_id );

		$_POST['wcorg_meetup_import_step'] = 'import';

		$this->expectException( WPDieException::class );
		Meetup_Import\handle_form_submission();
	}

	/**
	 * The render is guarded too, not just the menu registration.
	 */
	public function test_render_requires_manage_sites() {
		wp_set_current_user( self::factory()->user->create() );

		$this->expectException( WPDieException::class );
		Meetup_Import\render_admin_page();
	}

	/**
	 * A network admin can import through the form, from fetched (filtered) data.
	 */
	public function test_form_handler_imports_for_network_admin() {
		$GLOBALS['super_admins'] = array( 'meetupimporter' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_set_current_user( self::factory()->user->create( array( 'user_login' => 'meetupimporter' ) ) );

		add_filter(
			'wcorg_meetup_import_pre_fetch',
			function ( $events, $urlname ) {
				$this->assertSame( 'wma-wordpress-meetup', $urlname );
				return array( $this->raw_event() );
			},
			10,
			2
		);

		$_POST['wcorg_meetup_import_step']    = 'import';
		$_POST['meetup_group']                = 'https://www.meetup.com/wma-wordpress-meetup/';
		$_POST['site_id']                     = (string) $this->site_id;
		$_POST[ Meetup_Import\NONCE_NAME ]    = wp_create_nonce( Meetup_Import\NONCE_ACTION );
		$_REQUEST[ Meetup_Import\NONCE_NAME ] = $_POST[ Meetup_Import\NONCE_NAME ];

		try {
			Meetup_Import\handle_form_submission();
		} finally {
			unset( $GLOBALS['super_admins'] );
		}

		$this->assertSame( 'draft', $this->find_event( '315889305' )->post_status );
		$this->assertSame( 'created', Meetup_Import\request_state()['results'][0]['status'] );
	}

	/**
	 * A past Meetup event, with Meetup's RSVP counts.
	 *
	 * @param array $overrides Fields to replace.
	 */
	protected function raw_past_event( array $overrides = array() ): array {
		return $this->raw_event(
			array_merge(
				array(
					'id'       => '300000001',
					'status'   => 'PAST',
					'dateTime' => '2025-03-12T18:00:00-04:00',
					'endTime'  => '2025-03-12T20:00:00-04:00',
					'rsvps'    => array(
						'yesCount'      => 14,
						'attendedCount' => 11,
					),
				),
				$overrides
			)
		);
	}

	/**
	 * Past events carry their status and Meetup's counts through normalization.
	 */
	public function test_normalize_past_event() {
		$event = Meetup_Import\normalize_event( $this->raw_past_event() );

		$this->assertTrue( $event['is_past'] );
		$this->assertSame( 14, $event['rsvp_yes'] );
		$this->assertSame( 11, $event['attended'] );
		$this->assertFalse( Meetup_Import\normalize_event( $this->raw_event() )['is_past'] );
	}

	/**
	 * Past events only come through when asked for.
	 */
	public function test_fetch_drops_past_events_unless_asked() {
		add_filter(
			'wcorg_meetup_import_pre_fetch',
			function () {
				return array( $this->raw_event(), $this->raw_past_event() );
			}
		);

		$this->assertSame( array( '315889305' ), wp_list_pluck( Meetup_Import\fetch_group_events( 'wma-wordpress-meetup' ), 'id' ) );
		$this->assertCount( 2, Meetup_Import\fetch_group_events( 'wma-wordpress-meetup', true ) );
	}

	/**
	 * Re-attach the new-event notification hook, which `Groups_TestCase`
	 * detaches for every test, and start from an empty queue.
	 */
	protected function watch_publish_notifications(): void {
		\WordCamp\Groups\Frontend\Notifications\pending_notification_queue( null, true );

		add_action( 'transition_post_status', 'WordCamp\Groups\Frontend\Notifications\schedule_new_event_notification', 10, 3 );
	}

	/**
	 * Detach the hook again and drain the queue, so nothing renders at shutdown.
	 *
	 * @return int[] Event IDs that were queued for a notification.
	 */
	protected function stop_watching_publish_notifications(): array {
		remove_action( 'transition_post_status', 'WordCamp\Groups\Frontend\Notifications\schedule_new_event_notification', 10 );

		return \WordCamp\Groups\Frontend\Notifications\pending_notification_queue( null, true );
	}

	/**
	 * A past event is published as history, with its counts, and emails nobody.
	 */
	public function test_import_publishes_past_event_silently() {
		$this->watch_publish_notifications();

		try {
			$results = Meetup_Import\import_events( $this->site_id, array( Meetup_Import\normalize_event( $this->raw_past_event() ) ) );
		} finally {
			$queued = $this->stop_watching_publish_notifications();
		}

		$this->assertSame( 'created', $results[0]['status'] );

		$post = $this->find_event( '300000001' );
		$this->assertSame( 'publish', $post->post_status );

		$this->on_site(
			function () use ( $post ) {
				$this->assertSame( '14', get_post_meta( $post->ID, Meetup_Import\RSVP_YES_META, true ) );
				$this->assertSame( '11', get_post_meta( $post->ID, Meetup_Import\ATTENDED_META, true ) );
				$this->assertSame( '2025-03-12 18:00:00', get_post_meta( $post->ID, 'gatherpress_datetime_start', true ) );
			}
		);

		$this->assertSame( array(), $queued );
		$this->assertSame( array(), $this->sent_mail );
	}

	/**
	 * Organizers publishing an imported upcoming draft still notifies members as usual.
	 */
	public function test_publishing_imported_draft_still_notifies() {
		$this->watch_publish_notifications();

		try {
			Meetup_Import\import_events( $this->site_id, array( Meetup_Import\normalize_event( $this->raw_event() ) ) );
			$post = $this->find_event( '315889305' );

			// Importing the draft queues nothing...
			$this->assertSame( array(), \WordCamp\Groups\Frontend\Notifications\pending_notification_queue() );

			$this->on_site(
				static function () use ( $post ) {
					wp_update_post(
						array(
							'ID'          => $post->ID,
							'post_status' => 'publish',
						)
					);
				}
			);
		} finally {
			$queued = $this->stop_watching_publish_notifications();
		}

		// ...but the organizer publishing it notifies members as usual.
		$this->assertSame( array( $post->ID ), $queued );
	}

	/**
	 * A past event that's already on the site is never touched again.
	 */
	public function test_rerun_skips_imported_past_event() {
		$events = array( Meetup_Import\normalize_event( $this->raw_past_event() ) );

		Meetup_Import\import_events( $this->site_id, $events );
		$results = Meetup_Import\import_events( $this->site_id, $events );

		$this->assertSame( 'skipped', $results[0]['status'] );
		$this->assertSame( 1, $this->count_posts( Event::POST_TYPE ) );
	}

	/**
	 * Once published, an imported event takes RSVPs through the same route as any other.
	 */
	public function test_published_imported_event_accepts_rsvp() {
		$start = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( '+30 days' ) );
		$end   = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( '+30 days 2 hours' ) );

		Meetup_Import\import_events(
			$this->site_id,
			array(
				Meetup_Import\normalize_event(
					$this->raw_event(
						array(
							'dateTime' => $start,
							'endTime'  => $end,
						)
					)
				),
			)
		);

		$post    = $this->find_event( '315889305' );
		$user_id = self::factory()->user->create();
		add_user_to_blog( $this->site_id, $user_id, 'subscriber' );

		$response = $this->on_site(
			static function () use ( $post, $user_id ) {
				wp_update_post(
					array(
						'ID'          => $post->ID,
						'post_status' => 'publish',
					)
				);

				wp_set_current_user( $user_id );

				$request = new \WP_REST_Request( 'POST', "/wporg-groups/v1/event/{$post->ID}/rsvp" );
				$request->set_param( 'status', 'attending' );

				return rest_get_server()->dispatch( $request );
			}
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$this->on_site(
			function () use ( $post, $user_id ) {
				$rsvp = ( new Event( $post->ID ) )->rsvp->get( $user_id );
				$this->assertSame( 'attending', $rsvp['status'] );
			}
		);
	}

	/**
	 * Count every user in the install, across all sites.
	 */
	protected function count_all_users(): int {
		return count(
			get_users(
				array(
					'blog_id' => 0,
					'fields'  => 'ids',
				)
			)
		);
	}

	/**
	 * Meetup members in each of the ways member matching can go.
	 *
	 * @return array{0: array, 1: int[]} Raw members, and the IDs of the users that exist.
	 */
	protected function setup_members(): array {
		$users = array(
			'match'   => self::factory()->user->create( array( 'user_email' => 'match@example.org' ) ),
			'already' => self::factory()->user->create( array( 'user_email' => 'already@example.org' ) ),
		);

		add_user_to_blog( $this->site_id, $users['already'], 'subscriber' );

		$raw = array(
			array(
				'id'    => 'm1',
				'email' => 'Match@Example.org',
			),
			array(
				'id'    => 'm2',
				'email' => 'already@example.org',
			),
			array(
				'id'    => 'm3',
				'email' => 'nobody@example.org',
			),
			array(
				'id'    => 'm4',
				'email' => '',
			),
			array( 'id' => 'm5' ),
		);

		return array( $raw, $users );
	}

	/**
	 * Matches join as Members with event emails off; nobody else gets an account.
	 */
	public function test_import_members() {
		list( $raw, $users ) = $this->setup_members();
		$user_count          = $this->count_all_users();

		$counts = Meetup_Import\import_members( $this->site_id, array_map( 'WordCamp\Groups\Meetup_Import\normalize_member', $raw ) );

		$this->assertSame( 1, $counts['join'] );
		$this->assertSame( 1, $counts['already_member'] );
		$this->assertSame( 1, $counts['unmatched'] );
		$this->assertSame( 2, $counts['no_email'] );

		$this->assertTrue( is_user_member_of_blog( $users['match'], $this->site_id ) );
		$this->assertSame( array( 'subscriber' ), ( new \WP_User( $users['match'], '', $this->site_id ) )->roles );
		$this->assertSame( '0', get_user_meta( $users['match'], \WordCamp\Groups\Frontend\Notifications\get_scoped_opt_in_meta_key( $this->site_id ), true ) );

		// The member who was already there keeps whatever they had chosen.
		$this->assertSame( '', get_user_meta( $users['already'], \WordCamp\Groups\Frontend\Notifications\get_scoped_opt_in_meta_key( $this->site_id ), true ) );

		$this->assertSame( $user_count, $this->count_all_users() );
		$this->assertSame( array(), $this->sent_mail );
	}

	/**
	 * Someone who leaves after being imported isn't added back by a rerun.
	 */
	public function test_member_who_left_is_not_re_added() {
		list( $raw, $users ) = $this->setup_members();
		$members             = array_map( 'WordCamp\Groups\Meetup_Import\normalize_member', $raw );

		Meetup_Import\import_members( $this->site_id, $members );
		remove_user_from_blog( $users['match'], $this->site_id );

		$counts = Meetup_Import\import_members( $this->site_id, $members );

		$this->assertSame( 0, $counts['join'] );
		$this->assertSame( 2, $counts['previously_imported'] );
		$this->assertFalse( is_user_member_of_blog( $users['match'], $this->site_id ) );
	}

	/**
	 * The member preview counts without adding anyone.
	 */
	public function test_plan_members_writes_nothing() {
		list( $raw, $users ) = $this->setup_members();

		$counts = Meetup_Import\plan_members( $this->site_id, array_map( 'WordCamp\Groups\Meetup_Import\normalize_member', $raw ) );

		$this->assertSame( 1, $counts['join'] );
		$this->assertFalse( is_user_member_of_blog( $users['match'], $this->site_id ) );
		$this->assertSame( array(), (array) get_blog_option( $this->site_id, Meetup_Import\MEMBERS_OPTION, array() ) );
	}

	/**
	 * Members are only fetched and imported when the box is ticked.
	 */
	public function test_form_handler_imports_members_only_when_asked() {
		$GLOBALS['super_admins'] = array( 'meetupimporter' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_set_current_user( self::factory()->user->create( array( 'user_login' => 'meetupimporter' ) ) );

		list( $raw, $users ) = $this->setup_members();
		$fetched_members     = 0;

		add_filter(
			'wcorg_meetup_import_pre_fetch',
			function () {
				return array( $this->raw_event() );
			}
		);
		add_filter(
			'wcorg_meetup_import_pre_fetch_members',
			function () use ( $raw, &$fetched_members ) {
				++$fetched_members;
				return $raw;
			}
		);

		$_POST['wcorg_meetup_import_step']    = 'import';
		$_POST['meetup_group']                = 'wma-wordpress-meetup';
		$_POST['site_id']                     = (string) $this->site_id;
		$_POST[ Meetup_Import\NONCE_NAME ]    = wp_create_nonce( Meetup_Import\NONCE_ACTION );
		$_REQUEST[ Meetup_Import\NONCE_NAME ] = $_POST[ Meetup_Import\NONCE_NAME ];

		try {
			Meetup_Import\handle_form_submission();
			$this->assertSame( 0, $fetched_members );
			$this->assertFalse( is_user_member_of_blog( $users['match'], $this->site_id ) );

			$_POST['match_people'] = '1';
			Meetup_Import\handle_form_submission();
		} finally {
			unset( $GLOBALS['super_admins'], $_POST['match_people'] );
		}

		$this->assertSame( 1, $fetched_members );
		$this->assertTrue( is_user_member_of_blog( $users['match'], $this->site_id ) );
		$this->assertSame( 1, Meetup_Import\request_state()['member_counts']['join'] );
	}

	/**
	 * Only one request can hold the import lock at a time.
	 */
	public function test_lock_is_exclusive() {
		$this->assertTrue( Meetup_Import\acquire_lock() );
		$this->assertFalse( Meetup_Import\acquire_lock() );

		Meetup_Import\release_lock();
		$this->assertTrue( Meetup_Import\acquire_lock() );
	}

	/**
	 * The lock is one row for the whole network, whichever site the request is on.
	 */
	public function test_lock_is_network_wide() {
		$this->assertTrue( Meetup_Import\acquire_lock() );
		$this->assertFalse( $this->on_site( 'WordCamp\Groups\Meetup_Import\acquire_lock' ) );
	}

	/**
	 * A lock left behind by an import that died is taken over once it expires.
	 */
	public function test_stale_lock_is_taken_over() {
		global $wpdb;

		$this->assertTrue( Meetup_Import\acquire_lock() );

		$wpdb->update(
			Meetup_Import\lock_table(),
			array( 'option_value' => (string) ( time() - 1 ) ),
			array( 'option_name' => Meetup_Import\LOCK_OPTION )
		);

		$this->assertTrue( Meetup_Import\acquire_lock() );
		$this->assertFalse( Meetup_Import\acquire_lock() );
	}

	/**
	 * While another import holds the lock, the form imports nothing and says why.
	 */
	public function test_form_handler_refuses_while_locked() {
		$GLOBALS['super_admins'] = array( 'meetupimporter' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_set_current_user( self::factory()->user->create( array( 'user_login' => 'meetupimporter' ) ) );

		add_filter(
			'wcorg_meetup_import_pre_fetch',
			function () {
				return array( $this->raw_event() );
			}
		);

		$_POST['wcorg_meetup_import_step']    = 'import';
		$_POST['meetup_group']                = 'wma-wordpress-meetup';
		$_POST['site_id']                     = (string) $this->site_id;
		$_POST[ Meetup_Import\NONCE_NAME ]    = wp_create_nonce( Meetup_Import\NONCE_ACTION );
		$_REQUEST[ Meetup_Import\NONCE_NAME ] = $_POST[ Meetup_Import\NONCE_NAME ];

		Meetup_Import\acquire_lock();

		try {
			Meetup_Import\handle_form_submission();
		} finally {
			unset( $GLOBALS['super_admins'] );
		}

		$this->assertSame( 0, $this->count_posts( Event::POST_TYPE ) );
		$this->assertContains( 'import_running', wp_list_pluck( get_settings_errors( 'wcorg-meetup-import' ), 'code' ) );

		// The lock is still the other import's, untouched by the refused one.
		$this->assertFalse( Meetup_Import\acquire_lock() );
	}

	/**
	 * A finished import gives the lock back.
	 */
	public function test_form_handler_releases_lock() {
		$GLOBALS['super_admins'] = array( 'meetupimporter' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_set_current_user( self::factory()->user->create( array( 'user_login' => 'meetupimporter' ) ) );

		add_filter(
			'wcorg_meetup_import_pre_fetch',
			function () {
				return array( $this->raw_event() );
			}
		);

		$_POST['wcorg_meetup_import_step']    = 'import';
		$_POST['meetup_group']                = 'wma-wordpress-meetup';
		$_POST['site_id']                     = (string) $this->site_id;
		$_POST[ Meetup_Import\NONCE_NAME ]    = wp_create_nonce( Meetup_Import\NONCE_ACTION );
		$_REQUEST[ Meetup_Import\NONCE_NAME ] = $_POST[ Meetup_Import\NONCE_NAME ];

		try {
			Meetup_Import\handle_form_submission();
		} finally {
			unset( $GLOBALS['super_admins'] );
		}

		$this->assertSame( 1, $this->count_posts( Event::POST_TYPE ) );
		$this->assertTrue( Meetup_Import\acquire_lock() );
	}

	/**
	 * A featured photo as the Meetup API returns it.
	 *
	 * @param string $id  Meetup photo ID.
	 * @param string $url High-resolution URL.
	 */
	protected function raw_photo( string $id = '536071599', string $url = 'https://secure.meetupstatic.com/photos/event/7/b/6/f/highres_536071599.jpeg' ): array {
		return array(
			'id'         => $id,
			'highResUrl' => $url,
		);
	}

	/**
	 * Stand in for downloading photos, counting each download.
	 *
	 * @param int $downloads Incremented on every download.
	 */
	protected function fake_photo_downloads( int &$downloads ): void {
		add_filter(
			'wcorg_meetup_import_pre_sideload_photo',
			static function ( $attachment_id, $url, $post_id ) use ( &$downloads ) {
				++$downloads;

				return self::factory()->attachment->create_object(
					basename( $url ),
					$post_id,
					array(
						'post_mime_type' => 'image/jpeg',
						'post_type'      => 'attachment',
					)
				);
			},
			10,
			3
		);
	}

	/**
	 * Only photos on Meetup's own image host, over HTTPS, are kept.
	 *
	 * @dataProvider data_normalize_photo
	 *
	 * @param mixed $photo    `featuredEventPhoto` from the API.
	 * @param bool  $is_kept  Whether it should survive normalization.
	 */
	public function test_normalize_photo( $photo, bool $is_kept ) {
		$normalized = Meetup_Import\normalize_photo( $photo );

		if ( $is_kept ) {
			$this->assertSame( $photo['highResUrl'], $normalized['url'] );
			$this->assertSame( $photo['id'], $normalized['meetup_id'] );
		} else {
			$this->assertNull( $normalized );
		}
	}

	/**
	 * Inputs for `test_normalize_photo()`.
	 */
	public function data_normalize_photo(): array {
		return array(
			'meetup photo' => array(
				array(
					'id'         => '1',
					'highResUrl' => 'https://secure.meetupstatic.com/photos/event/highres_1.jpeg',
				),
				true,
			),
			'plain http' => array(
				array(
					'id'         => '1',
					'highResUrl' => 'http://secure.meetupstatic.com/photos/event/highres_1.jpeg',
				),
				false,
			),
			'other host' => array(
				array(
					'id'         => '1',
					'highResUrl' => 'https://example.org/highres_1.jpeg',
				),
				false,
			),
			'lookalike host' => array(
				array(
					'id'         => '1',
					'highResUrl' => 'https://meetupstatic.com.example.org/highres_1.jpeg',
				),
				false,
			),
			'missing url' => array(
				array( 'id' => '1' ),
				false,
			),
			'no photo' => array(
				null,
				false,
			),
		);
	}

	/**
	 * An event's Meetup photo becomes its featured image.
	 */
	public function test_import_sets_featured_image() {
		$downloads = 0;
		$this->fake_photo_downloads( $downloads );

		Meetup_Import\import_events(
			$this->site_id,
			array( Meetup_Import\normalize_event( $this->raw_event( array( 'featuredEventPhoto' => $this->raw_photo() ) ) ) )
		);

		$post = $this->find_event( '315889305' );

		$this->assertSame( 1, $downloads );
		$this->on_site(
			function () use ( $post ) {
				$thumbnail_id = get_post_thumbnail_id( $post->ID );

				$this->assertGreaterThan( 0, $thumbnail_id );
				$this->assertSame( '536071599', get_post_meta( $thumbnail_id, Meetup_Import\PHOTO_ID_META, true ) );
			}
		);
	}

	/**
	 * A cover photo shared by several events is downloaded once.
	 */
	public function test_shared_photo_is_downloaded_once() {
		$downloads = 0;
		$this->fake_photo_downloads( $downloads );

		Meetup_Import\import_events(
			$this->site_id,
			array(
				Meetup_Import\normalize_event( $this->raw_event( array( 'featuredEventPhoto' => $this->raw_photo() ) ) ),
				Meetup_Import\normalize_event(
					$this->raw_event(
						array(
							'id'                 => '315889306',
							'featuredEventPhoto' => $this->raw_photo(),
						)
					)
				),
			)
		);

		$first  = $this->find_event( '315889305' );
		$second = $this->find_event( '315889306' );

		$this->assertSame( 1, $downloads );
		$this->assertSame(
			$this->on_site(
				static function () use ( $first ) {
					return get_post_thumbnail_id( $first->ID );
				}
			),
			$this->on_site(
				static function () use ( $second ) {
					return get_post_thumbnail_id( $second->ID );
				}
			)
		);
	}

	/**
	 * Refreshing a draft keeps the image the organizer picked.
	 */
	public function test_refresh_keeps_organizer_image() {
		$downloads = 0;
		$this->fake_photo_downloads( $downloads );

		$events = array( Meetup_Import\normalize_event( $this->raw_event( array( 'featuredEventPhoto' => $this->raw_photo() ) ) ) );
		Meetup_Import\import_events( $this->site_id, $events );

		$post      = $this->find_event( '315889305' );
		$organizer = $this->on_site(
			static function () use ( $post ) {
				$attachment_id = self::factory()->attachment->create_object(
					'organizer.jpg',
					$post->ID,
					array(
						'post_mime_type' => 'image/jpeg',
						'post_type'      => 'attachment',
					)
				);
				set_post_thumbnail( $post->ID, $attachment_id );

				return $attachment_id;
			}
		);

		Meetup_Import\import_events( $this->site_id, $events );

		$this->assertSame(
			$organizer,
			$this->on_site(
				static function () use ( $post ) {
					return get_post_thumbnail_id( $post->ID );
				}
			)
		);
	}

	/**
	 * A photo that fails to download leaves the event imported, without an image.
	 */
	public function test_failed_photo_download_still_imports_event() {
		add_filter(
			'wcorg_meetup_import_pre_sideload_photo',
			static function () {
				return new \WP_Error( 'http_request_failed', 'Nope' );
			}
		);

		$results = Meetup_Import\import_events(
			$this->site_id,
			array( Meetup_Import\normalize_event( $this->raw_event( array( 'featuredEventPhoto' => $this->raw_photo() ) ) ) )
		);

		$post = $this->find_event( '315889305' );

		$this->assertSame( 'created', $results[0]['status'] );
		$this->assertSame(
			0,
			$this->on_site(
				static function () use ( $post ) {
					return (int) get_post_thumbnail_id( $post->ID );
				}
			)
		);
	}

	/**
	 * The Meetup ID is on an event and a venue from the moment they exist.
	 *
	 * Checked from `save_post`, the first point other code can see a new post:
	 * if the ID only arrived after, a run that died in between would leave
	 * something a rerun can't find, and it would be created twice.
	 */
	public function test_meetup_ids_are_saved_with_the_post() {
		$seen = array();

		$check = static function ( $post_id, $post ) use ( &$seen ) {
			$key                        = Venue::POST_TYPE === $post->post_type ? Meetup_Import\VENUE_ID_META : Meetup_Import\EVENT_ID_META;
			$seen[ $post->post_type ][] = get_post_meta( $post_id, $key, true );
		};

		add_action( 'save_post_' . Event::POST_TYPE, $check, 10, 2 );
		add_action( 'save_post_' . Venue::POST_TYPE, $check, 10, 2 );

		try {
			Meetup_Import\import_events( $this->site_id, array( Meetup_Import\normalize_event( $this->raw_event() ) ) );
		} finally {
			remove_action( 'save_post_' . Event::POST_TYPE, $check, 10 );
			remove_action( 'save_post_' . Venue::POST_TYPE, $check, 10 );
		}

		$this->assertSame( '315889305', $seen[ Event::POST_TYPE ][0] );
		$this->assertSame( '27654321', $seen[ Venue::POST_TYPE ][0] );
	}

	/**
	 * A past event an interrupted run left as a marked draft is finished by the next run.
	 */
	public function test_rerun_finishes_interrupted_past_event() {
		// What a run that died after creating the event, but before publishing it, leaves behind.
		$post_id = $this->on_site(
			static function () {
				return wp_insert_post(
					array(
						'post_type'   => Event::POST_TYPE,
						'post_status' => 'draft',
						'post_title'  => 'March meetup',
						'meta_input'  => array(
							Meetup_Import\EVENT_ID_META        => '300000001',
							Meetup_Import\PENDING_PUBLISH_META => 1,
							\WordCamp\Groups\Frontend\Notifications\PUBLISH_NOTIFICATION_SCHEDULED_META => 1,
						),
					)
				);
			}
		);

		$events = array( Meetup_Import\normalize_event( $this->raw_past_event() ) );

		$this->assertSame( 'create', Meetup_Import\plan_events( $this->site_id, $events )[0]['status'] );

		$this->watch_publish_notifications();

		try {
			Meetup_Import\import_events( $this->site_id, $events );
		} finally {
			$queued = $this->stop_watching_publish_notifications();
		}

		$this->assertSame( 1, $this->count_posts( Event::POST_TYPE ) );
		$this->assertSame( array(), $queued );

		$this->on_site(
			function () use ( $post_id ) {
				$this->assertSame( 'publish', get_post_status( $post_id ) );
				$this->assertSame( '', get_post_meta( $post_id, Meetup_Import\PENDING_PUBLISH_META, true ) );
				$this->assertSame( '2025-03-12 18:00:00', get_post_meta( $post_id, 'gatherpress_datetime_start', true ) );
			}
		);
	}

	/**
	 * An imported upcoming draft that has since happened stays a draft.
	 */
	public function test_draft_that_became_past_stays_draft() {
		Meetup_Import\import_events( $this->site_id, array( Meetup_Import\normalize_event( $this->raw_event() ) ) );

		$happened = $this->raw_event(
			array(
				'status'   => 'PAST',
				'dateTime' => '2025-11-11T18:00:00-05:00',
				'endTime'  => '2025-11-11T20:00:00-05:00',
			)
		);
		Meetup_Import\import_events( $this->site_id, array( Meetup_Import\normalize_event( $happened ) ) );

		$this->assertSame( 'draft', $this->find_event( '315889305' )->post_status );
	}

	/**
	 * A matched member is opted out before they become a member.
	 *
	 * If the run died between the two, the next run would see an existing
	 * member and leave them alone -- so the opt-out must already be there.
	 */
	public function test_member_is_opted_out_before_joining() {
		list( $raw, $users ) = $this->setup_members();
		$opt_in_at_join      = null;
		$key                 = \WordCamp\Groups\Frontend\Notifications\get_scoped_opt_in_meta_key( $this->site_id );

		$check = static function ( $user_id ) use ( $users, $key, &$opt_in_at_join ) {
			if ( $users['match'] === (int) $user_id ) {
				$opt_in_at_join = get_user_meta( $user_id, $key, true );
			}
		};

		add_action( 'add_user_to_blog', $check );

		try {
			Meetup_Import\import_members( $this->site_id, array_map( 'WordCamp\Groups\Meetup_Import\normalize_member', $raw ) );
		} finally {
			remove_action( 'add_user_to_blog', $check );
		}

		$this->assertSame( '0', $opt_in_at_join );
	}

	/**
	 * Releasing leaves alone a lock that another import has since taken over.
	 */
	public function test_release_keeps_someone_elses_lock() {
		global $wpdb;

		$this->assertTrue( Meetup_Import\acquire_lock() );

		// Another import took the lock over after ours expired.
		$wpdb->update(
			Meetup_Import\lock_table(),
			array( 'option_value' => ( time() + 600 ) . ':someone-else' ),
			array( 'option_name' => Meetup_Import\LOCK_OPTION )
		);

		Meetup_Import\release_lock();

		$this->assertFalse( Meetup_Import\acquire_lock() );

		$wpdb->delete( Meetup_Import\lock_table(), array( 'option_name' => Meetup_Import\LOCK_OPTION ) );
	}

	/**
	 * Releasing twice -- once after the import, once at shutdown -- is harmless.
	 */
	public function test_release_is_safe_to_repeat() {
		$this->assertTrue( Meetup_Import\acquire_lock() );

		Meetup_Import\release_lock();
		Meetup_Import\release_lock();

		$this->assertTrue( Meetup_Import\acquire_lock() );
	}

	/**
	 * The About draft seeded by Add Group Site, untouched since.
	 */
	protected function seed_about_page(): int {
		return $this->on_site(
			static function () {
				return wp_insert_post(
					array(
						'post_type'    => 'page',
						'post_name'    => 'about',
						'post_title'   => 'About',
						'post_status'  => 'draft',
						'post_content' => '<!-- wp:paragraph --><p>Example text.</p><!-- /wp:paragraph -->',
					)
				);
			}
		);
	}

	/**
	 * Group details fill every gap on a fresh site.
	 */
	public function test_import_group_fills_gaps() {
		$downloads = 0;
		$this->fake_photo_downloads( $downloads );
		$about_id = $this->seed_about_page();

		$results = Meetup_Import\import_group( $this->site_id, Meetup_Import\normalize_group( $this->raw_group() ) );

		$this->assertSame(
			array(
				'location' => 'set',
				'about'    => 'set',
				'icon'     => 'set',
			),
			$results
		);

		$this->on_site(
			function () use ( $about_id ) {
				$this->assertSame(
					array(
						'type'        => 'physical',
						'city'        => 'Greenfield',
						'countryCode' => 'US',
					),
					\WordCamp\Groups\Frontend\Group_Location\get_location()
				);

				$about = get_post( $about_id );
				$this->assertSame( 'draft', $about->post_status );
				$this->assertStringContainsString( '<!-- wp:paragraph --><p>Join us!</p><!-- /wp:paragraph -->', $about->post_content );
				$this->assertStringNotContainsString( 'Example text', $about->post_content );

				$this->assertGreaterThan( 0, (int) get_option( 'site_icon' ) );
				$this->assertSame( 'wma-wordpress-meetup', get_option( Meetup_Import\GROUP_URLNAME_OPTION ) );
				$this->assertSame( '2017-06-01', get_option( Meetup_Import\FOUNDED_OPTION ) );
			}
		);
		$this->assertSame( 1, $downloads );
	}

	/**
	 * Anything the organizer has already set stays theirs.
	 */
	public function test_import_group_keeps_what_is_set() {
		$downloads = 0;
		$this->fake_photo_downloads( $downloads );
		$about_id = $this->seed_about_page();

		$this->on_site(
			static function () use ( $about_id ) {
				\WordCamp\Groups\Frontend\Group_Location\save_location( array( 'type' => 'online' ) );
				update_option( 'site_icon', 12345 );

				// The organizer edited the About page a while after it was seeded.
				global $wpdb;
				$wpdb->update(
					$wpdb->posts,
					array(
						'post_content'  => 'Our own story.',
						'post_modified' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
					),
					array( 'ID' => $about_id )
				);
				clean_post_cache( $about_id );
			}
		);

		$results = Meetup_Import\import_group( $this->site_id, Meetup_Import\normalize_group( $this->raw_group() ) );

		$this->assertSame(
			array(
				'location' => 'kept',
				'about'    => 'kept',
				'icon'     => 'kept',
			),
			$results
		);

		$this->on_site(
			function () use ( $about_id ) {
				$this->assertSame( array( 'type' => 'online' ), \WordCamp\Groups\Frontend\Group_Location\get_location() );
				$this->assertSame( 'Our own story.', get_post( $about_id )->post_content );
				$this->assertSame( 12345, (int) get_option( 'site_icon' ) );
			}
		);
		$this->assertSame( 0, $downloads );
	}

	/**
	 * The group preview writes nothing.
	 */
	public function test_plan_group_writes_nothing() {
		$downloads = 0;
		$this->fake_photo_downloads( $downloads );
		$about_id = $this->seed_about_page();

		$results = Meetup_Import\plan_group( $this->site_id, Meetup_Import\normalize_group( $this->raw_group() ) );

		$this->assertSame( 'set', $results['location'] );
		$this->on_site(
			function () use ( $about_id ) {
				$this->assertNull( \WordCamp\Groups\Frontend\Group_Location\get_location() );
				$this->assertStringContainsString( 'Example text', get_post( $about_id )->post_content );
				$this->assertSame( 0, (int) get_option( 'site_icon' ) );
			}
		);
		$this->assertSame( 0, $downloads );
	}

	/**
	 * A group Meetup knows no city for leaves the location unset.
	 */
	public function test_import_group_without_location() {
		$results = Meetup_Import\import_group(
			$this->site_id,
			Meetup_Import\normalize_group(
				$this->raw_group(
					array(
						'city'          => '',
						'description'   => '',
						'keyGroupPhoto' => null,
					)
				)
			)
		);

		$this->assertSame( 'missing', $results['location'] );
		$this->assertSame( 'missing', $results['about'] );
		$this->assertSame( 'missing', $results['icon'] );
	}

	/**
	 * Meetup organizers in each of the ways leader matching can go.
	 *
	 * @return array{0: array, 1: int[]} Raw leader edges, and the users.
	 */
	protected function setup_leaders(): array {
		$users = array(
			'organizer' => self::factory()->user->create( array( 'user_email' => 'organizer@example.org' ) ),
			'coorg'     => self::factory()->user->create( array( 'user_email' => 'coorg@example.org' ) ),
			'admin'     => self::factory()->user->create( array( 'user_email' => 'siteadmin@example.org' ) ),
			'asst'      => self::factory()->user->create( array( 'user_email' => 'asst@example.org' ) ),
		);

		add_user_to_blog( $this->site_id, $users['coorg'], 'subscriber' );
		add_user_to_blog( $this->site_id, $users['admin'], 'administrator' );

		$edge = static function ( string $id, string $email, string $role ): array {
			return array(
				'metadata' => array( 'role' => $role ),
				'node'     => array(
					'id'    => $id,
					'email' => $email,
				),
			);
		};

		$raw = array(
			$edge( 'l1', 'organizer@example.org', 'ORGANIZER' ),
			$edge( 'l2', 'coorg@example.org', 'COORGANIZER' ),
			$edge( 'l3', 'siteadmin@example.org', 'COORGANIZER' ),
			$edge( 'l4', 'asst@example.org', 'ASST_ORGANIZER' ),
			$edge( 'l5', 'nobody@example.org', 'COORGANIZER' ),
			$edge( 'l6', '', 'EVENT_ORGANIZER' ),
			$edge( 'l7', 'member@example.org', 'MEMBER' ),
		);

		return array( $raw, $users );
	}

	/**
	 * Organizers get the matching role; nobody is demoted or made an administrator.
	 */
	public function test_import_leaders() {
		list( $raw, $users ) = $this->setup_leaders();

		$counts = Meetup_Import\import_leaders( $this->site_id, array_map( 'WordCamp\Groups\Meetup_Import\normalize_leader', $raw ) );

		$this->assertSame( 2, $counts['added'] );
		$this->assertSame( 1, $counts['promoted'] );
		$this->assertSame( 1, $counts['kept'] );
		$this->assertSame( 1, $counts['unmatched'] );
		$this->assertSame( 1, $counts['no_email'] );

		$roles = function ( int $user_id ): array {
			return ( new \WP_User( $user_id, '', $this->site_id ) )->roles;
		};

		$this->assertSame( array( 'editor' ), $roles( $users['organizer'] ) );
		$this->assertSame( array( 'editor' ), $roles( $users['coorg'] ) );
		$this->assertSame( array( 'administrator' ), $roles( $users['admin'] ) );
		$this->assertSame( array( 'author' ), $roles( $users['asst'] ) );

		// Added organizers are opted out of event emails like any imported member.
		$this->assertSame( '0', get_user_meta( $users['organizer'], \WordCamp\Groups\Frontend\Notifications\get_scoped_opt_in_meta_key( $this->site_id ), true ) );
		$this->assertSame( array(), $this->sent_mail );
	}

	/**
	 * The organizer preview changes no roles.
	 */
	public function test_plan_leaders_writes_nothing() {
		list( $raw, $users ) = $this->setup_leaders();

		$counts = Meetup_Import\plan_leaders( $this->site_id, array_map( 'WordCamp\Groups\Meetup_Import\normalize_leader', $raw ) );

		$this->assertSame( 2, $counts['added'] );
		$this->assertFalse( is_user_member_of_blog( $users['organizer'], $this->site_id ) );
		$this->assertSame( array( 'subscriber' ), ( new \WP_User( $users['coorg'], '', $this->site_id ) )->roles );
	}

	/**
	 * Matched hosts join the group if needed and are credited on the event.
	 */
	public function test_import_credits_hosts() {
		$host  = self::factory()->user->create( array( 'user_email' => 'host@example.org' ) );
		$event = $this->raw_event(
			array(
				'eventHosts' => array(
					array( 'member' => array( 'email' => 'Host@Example.org' ) ),
					array( 'member' => array( 'email' => 'stranger@example.org' ) ),
				),
			)
		);

		Meetup_Import\import_events( $this->site_id, array( Meetup_Import\normalize_event( $event ) ), true );

		$post = $this->find_event( '315889305' );

		$this->assertTrue( is_user_member_of_blog( $host, $this->site_id ) );
		$this->assertSame( '0', get_user_meta( $host, \WordCamp\Groups\Frontend\Notifications\get_scoped_opt_in_meta_key( $this->site_id ), true ) );
		$this->assertSame(
			array( $host ),
			$this->on_site(
				static function () use ( $post ) {
					return array_map( 'intval', (array) get_post_meta( $post->ID, \WordCamp\Groups\Frontend\Event_Hosts\META_KEY, true ) );
				}
			)
		);
	}

	/**
	 * Hosts aren't touched without the matching box, and an organizer's choice survives a refresh.
	 */
	public function test_hosts_need_matching_and_respect_organizer() {
		$host  = self::factory()->user->create( array( 'user_email' => 'host@example.org' ) );
		$other = self::factory()->user->create();
		add_user_to_blog( $this->site_id, $other, 'subscriber' );

		$events = array(
			Meetup_Import\normalize_event( $this->raw_event( array( 'eventHosts' => array( array( 'member' => array( 'email' => 'host@example.org' ) ) ) ) ) ),
		);

		Meetup_Import\import_events( $this->site_id, $events );
		$this->assertFalse( is_user_member_of_blog( $host, $this->site_id ) );

		$post = $this->find_event( '315889305' );
		$this->on_site(
			static function () use ( $post, $other ) {
				\WordCamp\Groups\Frontend\Event_Hosts\set_event_hosts( $post->ID, array( $other ) );
			}
		);

		Meetup_Import\import_events( $this->site_id, $events, true );

		$this->assertSame(
			array( $other ),
			$this->on_site(
				static function () use ( $post ) {
					return array_map( 'intval', (array) get_post_meta( $post->ID, \WordCamp\Groups\Frontend\Event_Hosts\META_KEY, true ) );
				}
			)
		);
	}

	/**
	 * Meetup RSVPs in every status, from matched and unmatched people.
	 *
	 * @return array{0: array, 1: int[]} Raw RSVP nodes, and the users.
	 */
	protected function setup_rsvps(): array {
		$users = array(
			'yes'      => self::factory()->user->create( array( 'user_email' => 'yes@example.org' ) ),
			'no'       => self::factory()->user->create( array( 'user_email' => 'no@example.org' ) ),
			'waitlist' => self::factory()->user->create( array( 'user_email' => 'waitlist@example.org' ) ),
			'attended' => self::factory()->user->create( array( 'user_email' => 'attended@example.org' ) ),
			'maybe'    => self::factory()->user->create( array( 'user_email' => 'maybe@example.org' ) ),
		);

		$rsvp = static function ( string $email, string $status ): array {
			return array(
				'status'      => $status,
				'guestsCount' => 0,
				'member'      => array( 'email' => $email ),
			);
		};

		$raw = array(
			$rsvp( 'Yes@Example.org', 'YES' ),
			$rsvp( 'no@example.org', 'NO' ),
			$rsvp( 'waitlist@example.org', 'WAITLIST' ),
			$rsvp( 'attended@example.org', 'ATTENDED' ),
			$rsvp( 'maybe@example.org', 'MAYBE' ),
			$rsvp( 'nobody@example.org', 'YES' ),
			$rsvp( '', 'YES' ),
		);

		return array( $raw, $users );
	}

	/**
	 * RSVPs from matched people become real RSVPs, with attendance checked in, and email nobody.
	 */
	public function test_import_rsvps() {
		list( $raw, $users ) = $this->setup_rsvps();

		add_filter(
			'wcorg_meetup_import_pre_fetch_rsvps',
			static function () use ( $raw ) {
				return $raw;
			}
		);

		// The confirmation email this guards against has to be live for the
		// "emails nobody" check below to mean anything.
		$this->assertNotFalse( has_action( 'set_object_terms', 'WordCamp\\Groups\\Frontend\\RSVP_Confirmation\\send_confirmation' ) );

		// A past event, so it ends up published -- the case a confirmation email could go out for.
		$results = Meetup_Import\import_events( $this->site_id, array( Meetup_Import\normalize_event( $this->raw_past_event() ) ), true );

		$this->assertSame(
			array(
				'saved'      => 4,
				'checked_in' => 1,
				'unmatched'  => 2,
				'skipped'    => 1,
				'failed'     => 0,
			),
			$results[0]['rsvps']
		);

		$post = $this->find_event( '300000001' );
		$this->assertSame( 'publish', $post->post_status );

		$this->on_site(
			function () use ( $post, $users ) {
				$rsvp   = ( new Event( $post->ID ) )->rsvp;
				$status = static function ( int $user_id ) use ( $rsvp ) {
					return $rsvp->get( $user_id )['status'] ?? null;
				};

				$this->assertSame( 'attending', $status( $users['yes'] ) );
				$this->assertSame( 'not_attending', $status( $users['no'] ) );
				// Meetup's waiting list comes over as attending; this event has room.
				$this->assertSame( 'attending', $status( $users['waitlist'] ) );
				$this->assertSame( 'attending', $status( $users['attended'] ) );
				$this->assertNull( $status( $users['maybe'] ) );

				$this->assertTrue( \WordCamp\Groups\Frontend\Check_In\is_checked_in( (int) $rsvp->get( $users['attended'] )['comment_id'] ) );
				$this->assertFalse( \WordCamp\Groups\Frontend\Check_In\is_checked_in( (int) $rsvp->get( $users['yes'] )['comment_id'] ) );
				$this->assertTrue( \WordCamp\Groups\Frontend\Check_In\event_has_check_ins( $post->ID ) );
			}
		);

		// RSVPing doesn't join anyone to the group.
		$this->assertFalse( is_user_member_of_blog( $users['yes'], $this->site_id ) );
		$this->assertSame( array(), $this->sent_mail );
	}

	/**
	 * Re-importing RSVPs updates them rather than adding more.
	 */
	public function test_rsvps_are_idempotent() {
		list( $raw, $users ) = $this->setup_rsvps();

		add_filter(
			'wcorg_meetup_import_pre_fetch_rsvps',
			static function () use ( $raw ) {
				return $raw;
			}
		);

		$events = array( Meetup_Import\normalize_event( $this->raw_event() ) );

		Meetup_Import\import_events( $this->site_id, $events, true );
		Meetup_Import\import_events( $this->site_id, $events, true );

		$post = $this->find_event( '315889305' );

		$this->assertSame(
			4,
			$this->on_site(
				static function () use ( $post ) {
					return count(
						get_comments(
							array(
								'post_id' => $post->ID,
								'type'    => 'gatherpress_rsvp',
								'fields'  => 'ids',
							)
						)
					);
				}
			)
		);
	}

	/**
	 * Without the matching box, no RSVPs are fetched at all.
	 */
	public function test_rsvps_need_matching() {
		$fetched = 0;

		add_filter(
			'wcorg_meetup_import_pre_fetch_rsvps',
			static function () use ( &$fetched ) {
				++$fetched;
				return array();
			}
		);

		$results = Meetup_Import\import_events( $this->site_id, array( Meetup_Import\normalize_event( $this->raw_event() ) ) );

		$this->assertSame( 0, $fetched );
		$this->assertNull( $results[0]['rsvps'] );
	}

	/**
	 * An RSVP request that fails leaves the event imported and counts the failure.
	 */
	public function test_failed_rsvp_fetch_still_imports_event() {
		add_filter(
			'wcorg_meetup_import_pre_fetch_rsvps',
			static function () {
				return new \WP_Error( 'http_request_failed', 'Nope' );
			},
			20
		);

		$results = Meetup_Import\import_events( $this->site_id, array( Meetup_Import\normalize_event( $this->raw_event() ) ), true );

		$this->assertSame( 'created', $results[0]['status'] );
		$this->assertSame( 1, $results[0]['rsvps']['failed'] );
	}

	/**
	 * Meetup statuses map to GatherPress ones; the rest are left out.
	 *
	 * @dataProvider data_normalize_rsvp
	 *
	 * @param string      $meetup     Meetup status.
	 * @param string|null $status     Expected GatherPress status.
	 * @param bool        $checked_in Whether it means they came.
	 */
	public function test_normalize_rsvp( string $meetup, ?string $status, bool $checked_in ) {
		$rsvp = Meetup_Import\normalize_rsvp(
			array(
				'status' => $meetup,
				'member' => array( 'email' => 'x@example.org' ),
			)
		);

		$this->assertSame( $status, $rsvp['status'] );
		$this->assertSame( $checked_in, $rsvp['checked_in'] );
	}

	/**
	 * Inputs for `test_normalize_rsvp()`.
	 */
	public function data_normalize_rsvp(): array {
		return array(
			'yes'              => array( 'YES', 'attending', false ),
			'yes, pending pay' => array( 'YES_PENDING_PAYMENT', 'attending', false ),
			'attended'         => array( 'ATTENDED', 'attending', true ),
			'no'               => array( 'NO', 'not_attending', false ),
			'waitlist'         => array( 'WAITLIST', 'attending', false ),
			'maybe'            => array( 'MAYBE', null, false ),
			'no show'          => array( 'NO_SHOW', null, false ),
			'excused'          => array( 'EXCUSED_ABSENCE', null, false ),
			'unanswered'       => array( 'HAVENT', null, false ),
		);
	}

	/**
	 * The form preview reports group details and organizers, and writes nothing.
	 */
	public function test_form_preview_with_matching() {
		$GLOBALS['super_admins'] = array( 'meetupimporter' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_set_current_user( self::factory()->user->create( array( 'user_login' => 'meetupimporter' ) ) );

		list( $raw_leaders, $users ) = $this->setup_leaders();

		add_filter(
			'wcorg_meetup_import_pre_fetch',
			function () {
				return array( $this->raw_event() );
			}
		);
		add_filter(
			'wcorg_meetup_import_pre_fetch_leaders',
			static function () use ( $raw_leaders ) {
				return $raw_leaders;
			}
		);
		add_filter( 'wcorg_meetup_import_pre_fetch_members', '__return_empty_array' );

		$_POST['wcorg_meetup_import_step']    = 'preview';
		$_POST['meetup_group']                = 'wma-wordpress-meetup';
		$_POST['site_id']                     = (string) $this->site_id;
		$_POST['match_people']                = '1';
		$_POST[ Meetup_Import\NONCE_NAME ]    = wp_create_nonce( Meetup_Import\NONCE_ACTION );
		$_REQUEST[ Meetup_Import\NONCE_NAME ] = $_POST[ Meetup_Import\NONCE_NAME ];

		try {
			Meetup_Import\handle_form_submission();
		} finally {
			unset( $GLOBALS['super_admins'], $_POST['match_people'] );
		}

		$state = Meetup_Import\request_state();

		$this->assertSame( 'set', $state['group_results']['location'] );
		$this->assertSame( 2, $state['leader_counts']['added'] );
		$this->assertFalse( is_user_member_of_blog( $users['organizer'], $this->site_id ) );
		$this->assertSame( 0, $this->count_posts( Event::POST_TYPE ) );
	}
}
