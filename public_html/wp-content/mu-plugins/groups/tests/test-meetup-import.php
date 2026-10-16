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
	}

	/**
	 * Remove the site, filters, and request globals.
	 */
	protected function tearDown(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );
		remove_all_filters( 'wcorg_meetup_import_pre_fetch' );

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
}
