<?php

namespace WordCamp\Groups\Tests;

use function WordCamp\Groups\Frontend\Event_Language\set_event_language;
use function WordCamp\Groups\Frontend\Event_Topics\set_event_topics;
use function WordCamp\Groups\GatherPress_Tweaks\get_event_archive_query_vars;
use function WordCamp\Groups\GatherPress_Tweaks\get_legacy_event_archive_pagination_redirect;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__, 2 ) . '/wporg-groups-frontend/tests/class-groups-testcase.php';

/**
 * @group groups
 */
class Test_Groups_GatherPress_Tweaks extends Groups_TestCase {

	/**
	 * Drop the query string `go_to()` leaves in `$_GET`, which the archive's
	 * filters would otherwise read in the next test.
	 */
	protected function tearDown(): void {
		$_GET = array();

		parent::tearDown();
	}

	/**
	 * Groups-network sites always show the event timezone and never offer
	 * anonymous RSVP, regardless of GatherPress's own defaults or of what an
	 * organizer saved on the settings screen.
	 */
	public function test_gatherpress_settings_overridden() {
		$settings = get_option( 'gatherpress_settings' );

		$this->assertSame( 1, $settings['show_timezone'] );
		$this->assertSame( 0, $settings['enable_anonymous_rsvp'] );
		$this->assertSame( 0, $settings['enable_open_rsvp'] );
		$this->assertSame( 'event', $settings['events_url'] );
	}

	/**
	 * A group running in another language keeps its events under `event/`.
	 *
	 * GatherPress's own default is the event label in the site language, which
	 * the occurrence rules in `gatherpress-recurring-events` would not match.
	 * The label filter stands in for a Japanese site, since the test suite has
	 * no language packs installed. GatherPress builds its defaults once per
	 * request, so the cache is dropped to have them built under that label.
	 */
	public function test_event_permalink_base_ignores_the_site_language() {
		$settings       = \GatherPress\Core\Settings::get_instance();
		$defaults_cache = new \ReflectionProperty( $settings, 'defaults_cache' );
		$japanese_label = static function ( $labels ) {
			$labels->singular_name = 'イベント';

			return $labels;
		};

		add_filter( 'post_type_labels_gatherpress_event', $japanese_label );
		$defaults_cache->setValue( $settings, null );

		$localized  = \GatherPress\Core\Event\Setup::get_localized_post_type_slug();
		$events_url = $settings->get( 'events_url' );

		remove_filter( 'post_type_labels_gatherpress_event', $japanese_label );
		$defaults_cache->setValue( $settings, null );

		// Without the pin this is the base GatherPress would use.
		$this->assertSame( sanitize_title( 'イベント' ), $localized );
		$this->assertSame( 'event', $events_url );
	}

	/**
	 * Unrelated GatherPress settings saved on a group site must survive the
	 * forced overrides: the filter overlays the forced keys, it does not
	 * replace the whole option.
	 */
	public function test_gatherpress_settings_preserved() {
		update_option(
			'gatherpress_settings',
			array(
				'guest_limit'      => 5,
				'enable_open_rsvp' => 1,
				'show_timezone'    => 0,
				'events_url'       => 'evento',
			)
		);

		$settings = get_option( 'gatherpress_settings' );

		// Unrelated stored setting is preserved.
		$this->assertSame( 5, $settings['guest_limit'] );

		// Forced keys win regardless of what was stored.
		$this->assertSame( 1, $settings['show_timezone'] );
		$this->assertSame( 0, $settings['enable_open_rsvp'] );
		$this->assertSame( 'event', $settings['events_url'] );
	}

	/**
	 * Login is required to comment on the groups network.
	 */
	public function test_comment_registration_required_on_groups_network() {
		$this->assertSame( '1', get_option( 'comment_registration' ) );
	}

	/**
	 * Editors ("Organizers") are granted `edit_theme_options` so they can use
	 * the Site Editor to customise their group site — but nothing broader.
	 * See the `promote_users` regression test in test-capabilities.php for
	 * the capability that must NOT be granted this way.
	 */
	public function test_editor_has_edit_theme_options() {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->assertTrue( user_can( $editor_id, 'edit_theme_options' ) );
	}

	/**
	 * The `edit_theme_options` grant is scoped to editors only.
	 */
	public function test_subscriber_does_not_have_edit_theme_options() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertFalse( user_can( $subscriber_id, 'edit_theme_options' ) );
	}

	/**
	 * `_event_speakers` is registered on `init`, which only fires once for
	 * the whole test run; `WP_UnitTestCase::tearDown()` unregisters all meta
	 * keys after every test (a known core testing quirk), so by the time
	 * this test runs the registration from bootstrap is long gone. Re-fire
	 * the real registration function directly (rather than
	 * `do_action( 'init' )`, which would also re-run block registration and
	 * trip "already registered" `_doing_it_wrong` notices) so there's only
	 * one place the args can drift from.
	 */
	public function test_event_speakers_meta_registered_with_array_default() {
		\WordCamp\Groups\GatherPress_Tweaks\register_event_speakers_meta();

		$registered = get_registered_meta_keys( 'post', 'gatherpress_event' );

		$this->assertArrayHasKey( '_event_speakers', $registered );
		$this->assertSame( array(), $registered['_event_speakers']['default'] );
		$this->assertSame( 'array', $registered['_event_speakers']['type'] );
	}

	/**
	 * Build a `wporg/query-total` block carrying a query context.
	 *
	 * The block itself lives in the wporg design system, outside this repo,
	 * so it isn't registered here — the filter under test only reads
	 * `$block->context`, which is public and can be set directly.
	 *
	 * @param string $post_type The post type the surrounding query loop runs.
	 */
	private function make_query_total_block( string $post_type ): \WP_Block {
		$block = new \WP_Block(
			array(
				'blockName'   => 'wporg/query-total',
				'attrs'       => array(),
				'innerBlocks' => array(),
			)
		);

		$block->context = array( 'query' => array( 'postType' => $post_type ) );

		return $block;
	}

	/**
	 * The events archive counts events, not "items" — on a page whose only
	 * content is events, the generic label reads like placeholder copy.
	 */
	public function test_query_total_label_counts_events_on_event_queries() {
		$block = $this->make_query_total_block( 'gatherpress_event' );

		$this->assertSame(
			'%s event',
			apply_filters( 'wporg_query_total_label', '%s item', 1, $block )
		);
		$this->assertSame(
			'%s events',
			apply_filters( 'wporg_query_total_label', '%s items', 12, $block )
		);
	}

	/**
	 * Every other query loop keeps the design system's own label.
	 */
	public function test_query_total_label_is_untouched_on_other_queries() {
		$block = $this->make_query_total_block( 'post' );

		$this->assertSame(
			'%s item',
			apply_filters( 'wporg_query_total_label', '%s item', 1, $block )
		);
	}

	/**
	 * Read the archive's Time filter options as the query-filter block would.
	 *
	 * @param string|null $event_time The `event_time` query arg to simulate.
	 */
	private function get_event_time_filter( ?string $event_time ): array {
		if ( null === $event_time ) {
			unset( $_GET['event_time'] );
		} else {
			$_GET['event_time'] = $event_time;
		}

		$filter = apply_filters( 'wporg_query_filter_options_event_time', array() );

		unset( $_GET['event_time'] );

		return $filter;
	}

	/**
	 * Single-select filters get no count badge from the wporg block, so the
	 * toggle itself has to say which view is applied.
	 */
	public function test_event_time_filter_names_the_applied_choice() {
		$filter = $this->get_event_time_filter( 'past' );

		$this->assertSame( 'Time: Past', $filter['label'] );
		$this->assertSame( array( 'past' ), $filter['selected'] );
	}

	/**
	 * The default view names itself too. A bare "Time" told the reader which
	 * axis the control filters on but nothing about what picking it would
	 * offer, or that a view was already applied (#2059).
	 */
	public function test_event_time_filter_names_the_default_view_as_well() {
		$filter = $this->get_event_time_filter( null );

		$this->assertSame( 'Time: Upcoming', $filter['label'] );
		$this->assertSame( array( 'upcoming' ), $filter['selected'] );
	}

	/**
	 * A hand-typed `event_time` that isn't one of the three views falls back
	 * to the default, and the toggle names that fallback rather than the
	 * value the reader typed.
	 */
	public function test_event_time_filter_ignores_an_unknown_value() {
		$filter = $this->get_event_time_filter( 'whenever' );

		$this->assertSame( 'Time: Upcoming', $filter['label'] );
		$this->assertSame( array( 'upcoming' ), $filter['selected'] );
	}

	/**
	 * An event with an RSVP and nothing else.
	 *
	 * @return array{event_id: int, user_id: int}
	 */
	private function create_event_with_rsvp(): array {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
			)
		);

		( new \GatherPress\Core\Event\Event( $event_id ) )->save_datetimes(
			array(
				'post_id'        => $event_id,
				'datetime_start' => gmdate( 'Y-m-d H:i:s', strtotime( '+7 days' ) ),
				'datetime_end'   => gmdate( 'Y-m-d H:i:s', strtotime( '+7 days +2 hours' ) ),
				'timezone'       => 'UTC',
			)
		);

		$user_id = self::factory()->user->create();
		add_user_to_blog( get_current_blog_id(), $user_id, 'subscriber' );
		wp_set_current_user( $user_id );

		( new \GatherPress\Core\Rsvp\Rsvp( $event_id ) )->save( $user_id, 'attending' );

		return array(
			'event_id' => $event_id,
			'user_id'  => $user_id,
		);
	}

	/**
	 * The #2020 symptom itself: a real comment query on a site whose only
	 * comments are RSVPs must come back empty.
	 *
	 * This is the state the bug needed. GatherPress builds its exclusion by
	 * listing the comment types that *do* exist and subtracting RSVPs -- on a
	 * site with no ordinary comments that list is empty, and
	 * `WP_Comment_Query` reads an empty type filter as "no type filter", so
	 * every RSVP surfaced in the Discussion section. Asserting on the query
	 * vars alone would not have caught it; the query has to actually run.
	 */
	public function test_rsvps_do_not_leak_into_a_general_comment_query() {
		$fixture = $this->create_event_with_rsvp();

		$this->assertSame(
			array(),
			get_comments( array( 'post_id' => $fixture['event_id'] ) ),
			'A general comment query on an RSVP-only site must return nothing.'
		);

		// The same query the Discussion section runs, unscoped by post. Not
		// asserted empty -- the fixture install seeds an ordinary comment, and
		// keeping that one is the point. Asserted free of RSVPs.
		$this->assertNotContains(
			'gatherpress_rsvp',
			wp_list_pluck( get_comments( array( 'status' => 'approve' ) ), 'comment_type' )
		);
	}

	/**
	 * Asking for RSVPs explicitly still gets them: the exclusion is for
	 * queries that did not ask, and GatherPress's own reads must keep working.
	 */
	public function test_an_explicit_rsvp_query_still_returns_rsvps() {
		$fixture = $this->create_event_with_rsvp();

		$by_type = get_comments(
			array(
				'post_id' => $fixture['event_id'],
				'type'    => 'gatherpress_rsvp',
				'status'  => 'approve',
			)
		);
		$this->assertCount( 1, $by_type );

		$by_type_in = get_comments(
			array(
				'post_id'  => $fixture['event_id'],
				'type__in' => array( 'gatherpress_rsvp' ),
				'status'   => 'approve',
			)
		);
		$this->assertCount( 1, $by_type_in );

		// And through GatherPress's own reader, which is what the attendee
		// list and the RSVP counts render from.
		$this->assertSame(
			1,
			(int) ( ( new \GatherPress\Core\Rsvp\Rsvp( $fixture['event_id'] ) )->responses()['attending']['count'] ?? 0 )
		);
	}

	/**
	 * An ordinary comment alongside the RSVPs still comes back.
	 *
	 * The exclusion has to subtract RSVPs, not everything: a group site with a
	 * real discussion must keep it.
	 */
	public function test_ordinary_comments_survive_the_exclusion() {
		$fixture = $this->create_event_with_rsvp();

		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $fixture['event_id'],
				'comment_approved' => '1',
			)
		);

		$comments = get_comments( array( 'post_id' => $fixture['event_id'] ) );

		$this->assertCount( 1, $comments );
		$this->assertSame( $comment_id, (int) $comments[0]->comment_ID );
	}

	/**
	 * Read the archive's Format filter options as the query-filter block would.
	 *
	 * @param string|null $event_format The `event_format` query arg to simulate.
	 */
	private function get_event_format_filter( ?string $event_format ): array {
		if ( null === $event_format ) {
			unset( $_GET['event_format'] );
		} else {
			$_GET['event_format'] = $event_format;
		}

		$filter = apply_filters( 'wporg_query_filter_options_event_format', array() );

		unset( $_GET['event_format'] );

		return $filter;
	}

	/**
	 * The Format toggle names its applied choice, exactly as Time does — same
	 * control, same reason (#2059).
	 */
	public function test_event_format_filter_names_every_view() {
		$this->assertSame( 'Format: All', $this->get_event_format_filter( null )['label'] );
		$this->assertSame( 'Format: All', $this->get_event_format_filter( 'all' )['label'] );
		$this->assertSame( 'Format: In person', $this->get_event_format_filter( 'in-person' )['label'] );
		$this->assertSame( 'Format: Online', $this->get_event_format_filter( 'online' )['label'] );
	}

	/**
	 * A hand-typed value that isn't one of the three widens the view back to
	 * "All" rather than emptying the archive.
	 */
	public function test_event_format_filter_ignores_an_unknown_value() {
		$filter = $this->get_event_format_filter( 'hybrid' );

		$this->assertSame( 'Format: All', $filter['label'] );
		$this->assertSame( array( 'all' ), $filter['selected'] );
	}

	/**
	 * Build the query arguments the archive's grid would run with.
	 *
	 * @param string|null $event_format   The `event_format` query arg to simulate.
	 * @param string|null $event_language The `event_language` query arg to simulate.
	 * @param string|null $event_topic    The `event_topic` query arg to simulate.
	 */
	private function get_archive_query_vars( ?string $event_format, ?string $event_language = null, ?string $event_topic = null ): array {
		if ( null === $event_topic ) {
			unset( $_GET['event_topic'] );
		} else {
			$_GET['event_topic'] = $event_topic;
		}

		if ( null === $event_format ) {
			unset( $_GET['event_format'] );
		} else {
			$_GET['event_format'] = $event_format;
		}

		if ( null === $event_language ) {
			unset( $_GET['event_language'] );
		} else {
			$_GET['event_language'] = $event_language;
		}

		$query_vars = get_event_archive_query_vars(
			array(
				'post_type'               => 'gatherpress_event',
				'gatherpress_event_query' => 'upcoming',
			)
		);

		unset( $_GET['event_format'], $_GET['event_language'], $_GET['event_topic'] );

		return $query_vars;
	}

	/**
	 * Create a published event, optionally marked online.
	 *
	 * @param string $title     Event title.
	 * @param bool   $is_online Whether to give it the `online-event` term.
	 *
	 * @return int The event post ID.
	 */
	private function make_format_event( string $title, bool $is_online ): int {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);

		if ( $is_online ) {
			$taxonomy = \GatherPress\Core\Venue\Setup::get_instance()->taxonomy_for_event_post_type(
				\GatherPress\Core\Event\Event::POST_TYPE
			);

			wp_set_object_terms( $event_id, 'online-event', $taxonomy );
		}

		return $event_id;
	}

	/**
	 * "Online" narrows to the events carrying GatherPress's `online-event`
	 * venue term.
	 */
	public function test_event_format_filter_narrows_to_online_events() {
		$online = $this->make_format_event( 'Online office hours', true );
		$this->make_format_event( 'Hall meetup', false );

		$query_vars = $this->get_archive_query_vars( 'online' );

		$this->assertSame( array( $online ), $query_vars['post__in'] );
		$this->assertArrayNotHasKey( 'post__not_in', $query_vars );
	}

	/**
	 * "In person" is the absence of that term, so an event with no venue at
	 * all counts as in person — it is certainly not online.
	 */
	public function test_event_format_filter_treats_a_venueless_event_as_in_person() {
		$online = $this->make_format_event( 'Online office hours', true );
		$this->make_format_event( 'Event with no venue', false );

		$query_vars = $this->get_archive_query_vars( 'in-person' );

		$this->assertSame( array( $online ), $query_vars['post__not_in'] );
		$this->assertArrayNotHasKey( 'post__in', $query_vars );
	}

	/**
	 * A group that runs nothing online should show an empty "Online" view, not
	 * its whole archive. WP_Query ignores an empty `post__in`, so the filter
	 * has to say "no posts" explicitly.
	 */
	public function test_event_format_filter_shows_nothing_when_no_events_are_online() {
		$this->make_format_event( 'Hall meetup', false );

		$this->assertSame( array( 0 ), $this->get_archive_query_vars( 'online' )['post__in'] );
	}

	/**
	 * The filter must never put a tax query on the archive's own query.
	 *
	 * A tax query joins `term_relationships`, which makes WP_Query select
	 * `DISTINCT` — and that collapses the duplicate rows
	 * `gatherpress-recurring-events` adds in `Query::clauses()` to turn a
	 * series into one row per date. Filtering by format would then show a
	 * weekly series once instead of on each of its dates, silently and only
	 * while a filter is applied.
	 */
	public function test_event_format_filter_keeps_a_tax_query_off_the_archive_query() {
		$this->make_format_event( 'Online office hours', true );

		foreach ( array( 'online', 'in-person' ) as $format ) {
			$this->assertArrayNotHasKey(
				'tax_query',
				$this->get_archive_query_vars( $format ),
				"A tax query on the {$format} view would collapse recurring occurrences."
			);
		}
	}

	/**
	 * "All" is the absence of a constraint, not a third thing to match.
	 */
	public function test_event_format_filter_constrains_nothing_when_showing_all() {
		foreach ( array( null, 'all' ) as $format ) {
			$query_vars = $this->get_archive_query_vars( $format );

			$this->assertArrayNotHasKey( 'post__in', $query_vars );
			$this->assertArrayNotHasKey( 'post__not_in', $query_vars );
			$this->assertArrayNotHasKey( 'tax_query', $query_vars );
		}
	}

	/**
	 * Run `go_to()` as a front-end request.
	 *
	 * This bootstrap defines `WP_ADMIN`, and the archive's main query is left
	 * alone in the admin. `go_to()` clears `$current_screen` before it runs
	 * the request, so the front-end screen goes in from `parse_request`, which
	 * fires inside it, ahead of the main query.
	 *
	 * @param string $url The URL to load.
	 */
	private function go_to_front_end( string $url ): void {
		$front = static function (): void {
			set_current_screen( 'front' );
		};

		add_action( 'parse_request', $front );
		$this->go_to( $url );
		remove_action( 'parse_request', $front );
	}

	/**
	 * Load the events archive as the main query.
	 *
	 * Through its plain `?post_type=` URL, since rewrite rules aren't flushed
	 * in this suite. `go_to()` also fills `$_GET` from the URL, which is
	 * where the archive's filters read their state from.
	 *
	 * @param array  $args View state to put in the query string.
	 * @param string $url  The archive URL to start from.
	 *
	 * @return \WP_Query The main query.
	 */
	private function go_to_event_archive( array $args = array(), string $url = '/?post_type=gatherpress_event' ): \WP_Query {
		$this->go_to_front_end( add_query_arg( $args, home_url( $url ) ) );

		$this->assertTrue( is_post_type_archive( 'gatherpress_event' ), 'Could not set up the events archive request.' );

		return $GLOBALS['wp_query'];
	}

	/**
	 * The archive grid inherits the main query, so the main query is what
	 * has to carry the grid's page size, order and upcoming window.
	 */
	public function test_archive_main_query_lists_upcoming_events_twelve_to_a_page() {
		$query = $this->go_to_event_archive();

		$this->assertSame( 12, $query->get( 'posts_per_page' ) );
		$this->assertSame( 'upcoming', $query->get( 'gatherpress_event_query' ) );
		$this->assertSame( 1, $query->get( 'include_unfinished' ) );
		$this->assertSame( 'datetime', $query->get( 'orderby' ) );
		$this->assertSame( 'ASC', $query->get( 'order' ) );
	}

	/**
	 * The Time filter switches the main query between GatherPress's past and
	 * upcoming windows, or drops the window for "All".
	 */
	public function test_archive_main_query_follows_the_time_filter() {
		$query = $this->go_to_event_archive( array( 'event_time' => 'past' ) );

		$this->assertSame( 'past', $query->get( 'gatherpress_event_query' ) );
		$this->assertSame( 0, $query->get( 'include_unfinished' ) );
		$this->assertSame( 'DESC', $query->get( 'order' ) );

		$query = $this->go_to_event_archive( array( 'event_time' => 'all' ) );

		$this->assertSame( '', $query->get( 'gatherpress_event_query' ) );
		$this->assertSame( 'date', $query->get( 'orderby' ) );
		$this->assertSame( 'DESC', $query->get( 'order' ) );
	}

	/**
	 * Each view lists the events it's named for. GatherPress only applies its
	 * upcoming/past window to a query that asks for one by the time its own
	 * `pre_get_posts` runs, so the archive's settings have to be in place
	 * before that, or every view lists every event.
	 */
	public function test_archive_views_list_their_events() {
		$last_month = $this->make_dated_event( 'Last month', '-30 days' );
		$next_week  = $this->make_dated_event( 'Next week', '+7 days' );
		$next_month = $this->make_dated_event( 'Next month', '+30 days' );

		$this->assertSame(
			array( $next_week, $next_month ),
			wp_list_pluck( $this->go_to_event_archive()->posts, 'ID' ),
			'Upcoming should list the events to come, soonest first.'
		);

		$this->assertSame(
			array( $last_month ),
			wp_list_pluck( $this->go_to_event_archive( array( 'event_time' => 'past' ) )->posts, 'ID' )
		);

		$this->assertEqualsCanonicalizing(
			array( $last_month, $next_week, $next_month ),
			wp_list_pluck( $this->go_to_event_archive( array( 'event_time' => 'all' ) )->posts, 'ID' )
		);
	}

	/**
	 * Create a published event two hours long.
	 *
	 * @param string $title  Event title.
	 * @param string $starts When it starts, relative to now (`strtotime()`).
	 *
	 * @return int The event post ID.
	 */
	private function make_dated_event( string $title, string $starts ): int {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);

		( new \GatherPress\Core\Event\Event( $event_id ) )->save_datetimes(
			array(
				'post_id'        => $event_id,
				'datetime_start' => gmdate( 'Y-m-d H:i:s', strtotime( $starts ) ),
				'datetime_end'   => gmdate( 'Y-m-d H:i:s', strtotime( $starts . ' +2 hours' ) ),
				'timezone'       => 'UTC',
			)
		);

		return $event_id;
	}

	/**
	 * The archive's filters narrow the main query, and what it finds is what
	 * the grid and its result count show.
	 */
	public function test_archive_main_query_applies_the_filters() {
		$online = $this->make_format_event( 'Online office hours', true );
		$this->make_format_event( 'Hall meetup', false );

		$query = $this->go_to_event_archive(
			array(
				'event_time'   => 'all',
				'event_format' => 'online',
			)
		);

		$this->assertSame( array( $online ), $query->get( 'post__in' ) );
		$this->assertSame( array( $online ), wp_list_pluck( $query->posts, 'ID' ) );
		$this->assertSame( 1, $query->found_posts );
	}

	/**
	 * A filter that matches nothing leaves an empty archive, not a 404, so
	 * the grid can show its empty state.
	 */
	public function test_archive_with_no_matching_events_is_not_a_404() {
		$this->make_format_event( 'Hall meetup', false );

		$query = $this->go_to_event_archive( array( 'event_format' => 'online' ) );

		$this->assertSame( array(), $query->posts );
		$this->assertFalse( is_404() );
	}

	/**
	 * Only the archive's main query is changed. The front page and "More
	 * events" grids run their own queries, which keep their block settings
	 * and ignore the archive's controls even when the URL carries them.
	 */
	public function test_archive_view_state_stays_off_other_event_queries() {
		$this->make_format_event( 'Online office hours', true );
		$this->go_to_event_archive( array( 'event_format' => 'online' ) );

		$secondary = new \WP_Query( array( 'post_type' => 'gatherpress_event' ) );

		$this->assertNotSame( 12, $secondary->get( 'posts_per_page' ) );
		$this->assertSame( '', $secondary->get( 'gatherpress_event_query' ) );
		$this->assertSame( array(), $secondary->get( 'post__in' ) );

		$block          = new \WP_Block(
			array(
				'blockName'   => 'core/post-template',
				'attrs'       => array(),
				'innerBlocks' => array(),
			)
		);
		$block->context = array(
			'query' => array(
				'postType'                => 'gatherpress_event',
				'gatherpress_event_query' => 'upcoming',
			),
		);

		$query_vars = array(
			'post_type'               => 'gatherpress_event',
			'gatherpress_event_query' => 'upcoming',
		);

		$this->assertSame( $query_vars, apply_filters( 'query_loop_block_query_vars', $query_vars, $block ) );
	}

	/**
	 * Feeds of the archive keep their own query.
	 */
	public function test_archive_feed_is_left_alone() {
		$this->go_to_front_end( home_url( '/?post_type=gatherpress_event&feed=rss2' ) );

		$this->assertTrue( is_feed() );
		$this->assertSame( '', $GLOBALS['wp_query']->get( 'gatherpress_event_query' ) );
	}

	/**
	 * The Events screen in wp-admin runs a main query for the same post type
	 * and has to keep listing every event, past ones included.
	 */
	public function test_admin_events_list_is_left_alone() {
		$last_month = $this->make_dated_event( 'Last month', '-30 days' );
		$next_week  = $this->make_dated_event( 'Next week', '+7 days' );

		$screen = $GLOBALS['current_screen'] ?? null;
		set_current_screen( 'edit-gatherpress_event' );

		$this->go_to( home_url( '/?post_type=gatherpress_event' ) );
		$query = $GLOBALS['wp_query'];

		$GLOBALS['current_screen'] = $screen; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Putting back the screen this test changed.

		$this->assertNotSame( 12, $query->get( 'posts_per_page' ) );
		$this->assertEqualsCanonicalizing( array( $last_month, $next_week ), wp_list_pluck( $query->posts, 'ID' ) );
	}

	/**
	 * Old Query Loop page links move to the main query's paged URL and keep
	 * the rest of the view state.
	 */
	public function test_legacy_query_loop_page_links_redirect_to_the_paged_archive() {
		$this->go_to_event_archive(
			array(
				'query-page' => '3',
				'event_time' => 'past',
			)
		);

		$redirect = array();
		$catcher  = static function ( $location, $status ) use ( &$redirect ) {
			$redirect = array(
				'location' => $location,
				'status'   => $status,
			);

			throw new \Exception( 'redirected' );
		};

		add_filter( 'wp_redirect', $catcher, 10, 2 );

		try {
			do_action( 'template_redirect' );
		} catch ( \Exception $thrown ) {
			$this->assertSame( 'redirected', $thrown->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $catcher, 10 );
		}

		$this->assertSame( 301, $redirect['status'] ?? null, 'The archive did not redirect.' );
		$this->assertStringContainsString( '/page/3/', $redirect['location'] );
		$this->assertStringContainsString( 'event_time=past', $redirect['location'] );
		$this->assertStringNotContainsString( 'query-page', $redirect['location'] );
	}

	/**
	 * A Query Loop with an ID names its page argument after it; page 1 needs
	 * no page in the URL at all.
	 */
	public function test_legacy_numbered_query_loop_page_link_redirects_to_page_one() {
		$this->go_to_event_archive( array( 'query-7-page' => '1' ) );

		$location = get_legacy_event_archive_pagination_redirect();

		$this->assertNotSame( '', $location );
		$this->assertStringNotContainsString( '/page/', $location );
		$this->assertStringNotContainsString( 'query-7-page', $location );
	}

	/**
	 * Without an old page link, or off the archive, nothing redirects.
	 */
	public function test_archive_without_legacy_page_link_does_not_redirect() {
		$this->go_to_event_archive( array( 'event_time' => 'past' ) );
		$this->assertSame( '', get_legacy_event_archive_pagination_redirect() );

		$this->go_to_front_end( home_url( '/?query-page=2' ) );
		$this->assertSame( '', get_legacy_event_archive_pagination_redirect() );
	}

	/**
	 * Each filter's form holds only its own control, so without this the two
	 * filters would reset each other and the search term on every submit.
	 */
	public function test_filter_forms_carry_the_other_view_state() {
		$_GET['event_time']   = 'past';
		$_GET['event_format'] = 'online';
		$_GET['s']            = 'meetup';

		ob_start();
		do_action( 'wporg_query_filter_in_form', 'event_format' );
		$format_form = ob_get_clean();

		ob_start();
		do_action( 'wporg_query_filter_in_form', 'event_time' );
		$time_form = ob_get_clean();

		unset( $_GET['event_time'], $_GET['event_format'], $_GET['s'] );

		// Each form carries the sibling filter and the search, never its own
		// key -- the control itself already submits that.
		$this->assertStringContainsString( 'name="event_time" value="past"', $format_form );
		$this->assertStringContainsString( 'name="s" value="meetup"', $format_form );
		$this->assertStringNotContainsString( 'name="event_format"', $format_form );

		$this->assertStringContainsString( 'name="event_format" value="online"', $time_form );
		$this->assertStringNotContainsString( 'name="event_time"', $time_form );
	}

	/**
	 * A nested `s[r]=x` query arg is treated as an empty search instead of
	 * raising an "Array to string conversion" warning.
	 */
	public function test_array_search_param_is_treated_as_empty() {
		$_GET['event_time'] = 'all';
		$_GET['s']          = array( 'r' => 'x' );

		ob_start();
		do_action( 'wporg_query_filter_in_form', 'event_time' );
		$time_form = ob_get_clean();

		$query_vars = $this->get_archive_query_vars( null );

		unset( $_GET['event_time'], $_GET['s'] );

		$this->assertStringNotContainsString( 'name="s"', $time_form );
		$this->assertArrayNotHasKey( 'orderby', $query_vars, 'An empty search falls back to the upcoming view.' );
	}

	/**
	 * Nothing applied, nothing carried: a default view should not litter the
	 * form with hidden inputs restating the default.
	 */
	public function test_filter_forms_carry_nothing_on_the_default_view() {
		ob_start();
		do_action( 'wporg_query_filter_in_form', 'event_time' );

		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * Create a published event in a language, optionally marked online.
	 *
	 * @param string $title     Event title.
	 * @param string $language  Language subtag, or '' to leave it unset.
	 * @param bool   $is_online Whether to give it the `online-event` term.
	 *
	 * @return int The event post ID.
	 */
	private function make_language_event( string $title, string $language, bool $is_online = false ): int {
		$event_id = $this->make_format_event( $title, $is_online );

		if ( '' !== $language ) {
			set_event_language( $event_id, $language );
		}

		return $event_id;
	}

	/**
	 * Read the language filter's registered options.
	 *
	 * @param string|null $event_language The `event_language` query arg to simulate.
	 */
	private function get_event_language_filter( ?string $event_language ): array {
		if ( null === $event_language ) {
			unset( $_GET['event_language'] );
		} else {
			$_GET['event_language'] = $event_language;
		}

		$filter = apply_filters( 'wporg_query_filter_options_event_language', array() );

		unset( $_GET['event_language'] );

		return $filter;
	}

	/**
	 * The control offers the languages this group actually runs events in,
	 * not the 593 CLDR knows about.
	 */
	public function test_event_language_filter_offers_only_languages_in_use() {
		$this->make_language_event( 'Charla mensual', 'es' );
		$this->make_language_event( 'Monthly talk', 'en' );

		$options = $this->get_event_language_filter( null )['options'];

		$this->assertSame( array( 'all', 'en', 'es' ), array_keys( $options ) );
		$this->assertSame( 'English', $options['en'] );
		$this->assertSame( 'Spanish', $options['es'] );
	}

	/**
	 * A group that runs everything in one language gets no control at all:
	 * "All" and that language would select the same events.
	 */
	public function test_event_language_filter_is_hidden_without_a_choice() {
		$this->assertSame( array(), $this->get_event_language_filter( null ) );

		$this->make_language_event( 'Charla mensual', 'es' );
		$this->make_language_event( 'Otra charla', 'es' );
		$this->make_language_event( 'Untagged meetup', '' );

		$this->assertSame( array(), $this->get_event_language_filter( null ) );
	}

	/**
	 * The toggle names the applied view, the way Time and Format do.
	 */
	public function test_event_language_filter_names_every_view() {
		$this->make_language_event( 'Charla mensual', 'es' );
		$this->make_language_event( 'Monthly talk', 'en' );

		$this->assertSame( 'Language: All', $this->get_event_language_filter( null )['label'] );
		$this->assertSame( 'Language: Spanish', $this->get_event_language_filter( 'es' )['label'] );
	}

	/**
	 * A language the group does not run events in widens the archive rather
	 * than emptying it.
	 */
	public function test_event_language_filter_ignores_an_unknown_value() {
		$this->make_language_event( 'Charla mensual', 'es' );
		$this->make_language_event( 'Monthly talk', 'en' );

		$filter = $this->get_event_language_filter( 'ja' );

		$this->assertSame( 'Language: All', $filter['label'] );
		$this->assertSame( array( 'all' ), $filter['selected'] );
	}

	/**
	 * Picking a language narrows the archive to the events run in it.
	 */
	public function test_event_language_filter_narrows_to_one_language() {
		$spanish = $this->make_language_event( 'Charla mensual', 'es' );
		$this->make_language_event( 'Monthly talk', 'en' );
		$this->make_language_event( 'Untagged meetup', '' );

		$this->assertSame( array( $spanish ), $this->get_archive_query_vars( null, 'es' )['post__in'] );
	}

	/**
	 * Language and format have to narrow each other. WP_Query treats
	 * `post__in` and `post__not_in` as an if/elseif, so the in-person filter's
	 * `post__not_in` would be dropped without a word if both were left to set
	 * their own query var.
	 */
	public function test_event_language_and_format_filters_narrow_together() {
		$spanish_online    = $this->make_language_event( 'Charla en linea', 'es', true );
		$spanish_in_person = $this->make_language_event( 'Charla en el bar', 'es', false );
		$this->make_language_event( 'Online talk', 'en', true );

		$online = $this->get_archive_query_vars( 'online', 'es' );
		$this->assertSame( array( $spanish_online ), $online['post__in'] );
		$this->assertArrayNotHasKey( 'post__not_in', $online );

		$in_person = $this->get_archive_query_vars( 'in-person', 'es' );
		$this->assertSame( array( $spanish_in_person ), $in_person['post__in'] );
		$this->assertArrayNotHasKey( 'post__not_in', $in_person );
	}

	/**
	 * An empty `post__in` is ignored by WP_Query, so a combination that
	 * matches nothing has to say "no posts" explicitly rather than falling
	 * back to the whole archive.
	 */
	public function test_event_language_filter_shows_nothing_when_the_combination_is_empty() {
		$this->make_language_event( 'Charla en el bar', 'es', false );
		$this->make_language_event( 'Online talk', 'en', true );

		$this->assertSame( array( 0 ), $this->get_archive_query_vars( 'online', 'es' )['post__in'] );
	}

	/**
	 * The language filter must stay off the archive's own query for the same
	 * reason the format filter does: a join that makes WP_Query select
	 * `DISTINCT` collapses a recurring series back into a single row.
	 */
	public function test_event_language_filter_keeps_a_meta_query_off_the_archive_query() {
		$this->make_language_event( 'Charla mensual', 'es' );
		$this->make_language_event( 'Monthly talk', 'en' );

		$query_vars = $this->get_archive_query_vars( null, 'es' );

		$this->assertArrayNotHasKey( 'meta_query', $query_vars );
		$this->assertArrayNotHasKey( 'meta_key', $query_vars );
	}

	/**
	 * Each filter's form carries the applied language, so submitting one does
	 * not reset it.
	 */
	public function test_filter_forms_carry_the_applied_language() {
		$this->make_language_event( 'Charla mensual', 'es' );
		$this->make_language_event( 'Monthly talk', 'en' );

		$_GET['event_language'] = 'es';

		ob_start();
		do_action( 'wporg_query_filter_in_form', 'event_time' );
		$time_form = ob_get_clean();

		ob_start();
		do_action( 'wporg_query_filter_in_form', 'event_language' );
		$language_form = ob_get_clean();

		unset( $_GET['event_language'] );

		$this->assertStringContainsString( 'name="event_language" value="es"', $time_form );
		$this->assertStringNotContainsString( 'name="event_language"', $language_form );
	}

	/**
	 * Searching narrows the language already in view rather than resetting it.
	 */
	public function test_search_form_carries_the_applied_language() {
		global $wp_query;

		$this->make_language_event( 'Charla mensual', 'es' );
		$this->make_language_event( 'Monthly talk', 'en' );

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
		$original_query                 = $wp_query;
		$wp_query                       = new \WP_Query();
		$wp_query->is_post_type_archive = true;
		$wp_query->set( 'post_type', 'gatherpress_event' );

		$_GET['event_language'] = 'es';

		$search_form = '<form role="search" method="get" action="https://example.org"><input type="search" name="s" /></form>';
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Core's own block filter name.
		$output = apply_filters( 'render_block_core/search', $search_form );

		unset( $_GET['event_language'] );
		$wp_query = $original_query;
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited

		$this->assertStringContainsString( 'name="event_language" value="es"', $output );
	}

	/**
	 * Create a published event with topics, optionally in a language and
	 * marked online.
	 *
	 * @param string   $title     Event title.
	 * @param string[] $topics    Topic names.
	 * @param string   $language  Language subtag, or '' to leave it unset.
	 * @param bool     $is_online Whether to give it the `online-event` term.
	 *
	 * @return int The event post ID.
	 */
	private function make_topic_event( string $title, array $topics, string $language = '', bool $is_online = false ): int {
		$event_id = $this->make_language_event( $title, $language, $is_online );

		set_event_topics( $event_id, $topics );

		return $event_id;
	}

	/**
	 * Read the topic filter's registered options.
	 *
	 * @param string|null $event_topic The `event_topic` query arg to simulate.
	 */
	private function get_event_topic_filter( ?string $event_topic ): array {
		if ( null === $event_topic ) {
			unset( $_GET['event_topic'] );
		} else {
			$_GET['event_topic'] = $event_topic;
		}

		$filter = apply_filters( 'wporg_query_filter_options_event_topic', array() );

		unset( $_GET['event_topic'] );

		return $filter;
	}

	/**
	 * The control offers the topics on published events, keyed by slug, and
	 * is hidden when no event has one. A topic that only lives on a draft
	 * would be a filter that comes back empty.
	 */
	public function test_event_topic_filter_offers_only_topics_in_use() {
		$this->assertSame( array(), $this->get_event_topic_filter( null ) );

		$this->make_topic_event( 'Theme night', array( 'Block Themes' ) );

		$draft = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'draft',
			)
		);
		set_event_topics( $draft, array( 'Unpublished' ) );

		$filter = $this->get_event_topic_filter( null );

		$this->assertSame( array( 'all', 'block-themes' ), array_keys( $filter['options'] ) );
		$this->assertSame( 'Block Themes', $filter['options']['block-themes'] );
		$this->assertSame( 'Topic: All', $filter['label'] );
		$this->assertSame( 'Topic: Block Themes', $this->get_event_topic_filter( 'block-themes' )['label'] );
	}

	/**
	 * A topic the group has no events on widens the archive rather than
	 * emptying it.
	 */
	public function test_event_topic_filter_ignores_an_unknown_value() {
		$this->make_topic_event( 'Theme night', array( 'Block Themes' ) );

		$filter = $this->get_event_topic_filter( 'nope' );

		$this->assertSame( array( 'all' ), $filter['selected'] );
		$this->assertArrayNotHasKey( 'post__in', $this->get_archive_query_vars( null, null, 'nope' ) );
	}

	/**
	 * `?event_topic[]=` sends an array. It falls back to "All" like any
	 * other value the group has no events on, rather than fataling.
	 */
	public function test_event_topic_filter_ignores_an_array_value() {
		$this->make_topic_event( 'Theme night', array( 'Block Themes' ) );

		$_GET['event_topic'] = array( 'block-themes' );

		$filter = apply_filters( 'wporg_query_filter_options_event_topic', array() );

		unset( $_GET['event_topic'] );

		$this->assertSame( array( 'all' ), $filter['selected'] );
	}

	/**
	 * A topic with non-ASCII letters has a percent-encoded slug, and PHP
	 * decodes the query arg. The filter has to match it either way.
	 */
	public function test_event_topic_filter_matches_a_non_ascii_topic() {
		$event = $this->make_topic_event( 'Вечер', array( 'Блокови' ) );

		$slug = get_term_by( 'name', 'Блокови', 'gatherpress_topic' )->slug;

		$this->assertSame( array( $event ), $this->get_archive_query_vars( null, null, rawurldecode( $slug ) )['post__in'] );
		$this->assertSame( array( $event ), $this->get_archive_query_vars( null, null, $slug )['post__in'] );
	}

	/**
	 * Picking a topic narrows the archive to the events tagged with it,
	 * without a tax query on the archive's own SQL.
	 */
	public function test_event_topic_filter_narrows_to_one_topic() {
		$themes = $this->make_topic_event( 'Theme night', array( 'Block Themes', 'Design' ) );
		$this->make_topic_event( 'Plugin night', array( 'Plugins' ) );
		$this->make_topic_event( 'Untagged meetup', array() );

		$query_vars = $this->get_archive_query_vars( null, null, 'block-themes' );

		$this->assertSame( array( $themes ), $query_vars['post__in'] );
		$this->assertArrayNotHasKey( 'tax_query', $query_vars );
	}

	/**
	 * Topic, language and format all narrow each other rather than the last
	 * one applied winning.
	 */
	public function test_event_topic_language_and_format_filters_narrow_together() {
		$match = $this->make_topic_event( 'Charla de temas', array( 'Block Themes' ), 'es', false );
		$this->make_topic_event( 'Charla en linea', array( 'Block Themes' ), 'es', true );
		$this->make_topic_event( 'Theme night', array( 'Block Themes' ), 'en', false );
		$this->make_topic_event( 'Charla de plugins', array( 'Plugins' ), 'es', false );

		$in_person = $this->get_archive_query_vars( 'in-person', 'es', 'block-themes' );
		$this->assertSame( array( $match ), $in_person['post__in'] );
		$this->assertArrayNotHasKey( 'post__not_in', $in_person );

		$this->assertSame( array( 0 ), $this->get_archive_query_vars( 'online', 'en', 'block-themes' )['post__in'] );
	}

	/**
	 * Each filter's form and the search form carry the applied topic, so
	 * submitting one does not reset it.
	 */
	public function test_filter_and_search_forms_carry_the_applied_topic() {
		global $wp_query;

		$this->make_topic_event( 'Theme night', array( 'Block Themes' ) );

		$_GET['event_topic'] = 'block-themes';

		ob_start();
		do_action( 'wporg_query_filter_in_form', 'event_time' );
		$time_form = ob_get_clean();

		ob_start();
		do_action( 'wporg_query_filter_in_form', 'event_topic' );
		$topic_form = ob_get_clean();

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
		$original_query                 = $wp_query;
		$wp_query                       = new \WP_Query();
		$wp_query->is_post_type_archive = true;
		$wp_query->set( 'post_type', 'gatherpress_event' );

		$search_form = '<form role="search" method="get" action="https://example.org"><input type="search" name="s" /></form>';
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Core's own block filter name.
		$search = apply_filters( 'render_block_core/search', $search_form );

		$wp_query = $original_query;
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited

		unset( $_GET['event_topic'] );

		$this->assertStringContainsString( 'name="event_topic" value="block-themes"', $time_form );
		$this->assertStringNotContainsString( 'name="event_topic"', $topic_form );
		$this->assertStringContainsString( 'name="event_topic" value="block-themes"', $search );
	}

	/**
	 * Venues are metadata on events, not their own front-end destination -
	 * confirm the post type stays non-public even though GatherPress itself
	 * registers it.
	 */
	public function test_gatherpress_venue_post_type_is_non_public() {
		$post_type_object = get_post_type_object( 'gatherpress_venue' );

		$this->assertNotNull( $post_type_object, 'GatherPress must be active for this assertion to be meaningful.' );
		$this->assertFalse( $post_type_object->public );
		$this->assertFalse( $post_type_object->publicly_queryable );
		$this->assertFalse( $post_type_object->has_archive );
	}

	/**
	 * The venue taxonomy has no public archive either, so it cannot list the
	 * events at a venue - protected ones included - under the venue's name. Its
	 * REST exposure stays on, since the REST surfaces are gated separately.
	 */
	public function test_gatherpress_venue_taxonomy_is_not_publicly_queryable() {
		$taxonomy = get_taxonomy( '_gatherpress_venue' );

		$this->assertNotFalse( $taxonomy, 'GatherPress must be active for this assertion to be meaningful.' );
		$this->assertFalse( $taxonomy->publicly_queryable );
		$this->assertFalse( $taxonomy->query_var );
		$this->assertTrue( $taxonomy->show_in_rest );
	}

	/**
	 * Create a published event with a published venue attached to it.
	 *
	 * GatherPress links the two with a shadow term in `_gatherpress_venue`
	 * whose slug is the venue's `post_name` prefixed with an underscore.
	 *
	 * @param string $venue_name The venue's title.
	 *
	 * @return int The event's post ID.
	 */
	private function create_event_with_venue( string $venue_name = 'Salty Spaces' ): int {
		$venue_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_venue',
				'post_status' => 'publish',
				'post_title'  => $venue_name,
				'post_name'   => sanitize_title( $venue_name ),
			)
		);

		update_post_meta( $venue_id, 'gatherpress_address', 'The Wharf Mooloolaba, Mooloolaba QLD 4557' );

		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
				'post_title'  => 'Event With A Venue',
			)
		);

		$slug = '_' . get_post_field( 'post_name', $venue_id );
		$term = get_term_by( 'slug', $slug, '_gatherpress_venue' );

		if ( ! $term ) {
			$inserted = wp_insert_term( $venue_name, '_gatherpress_venue', array( 'slug' => $slug ) );
			$this->assertNotWPError( $inserted, 'Could not create the venue shadow term.' );
			$term = get_term( $inserted['term_id'], '_gatherpress_venue' );
		}

		wp_set_object_terms( $event_id, array( (int) $term->term_id ), '_gatherpress_venue', false );

		return $event_id;
	}

	/**
	 * Render the venue block in the context of an event.
	 *
	 * @param int $event_id Event to render the block for.
	 *
	 * @return string The rendered block.
	 */
	private function render_venue_block( int $event_id ): string {
		global $post;

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
		$original_post = $post;
		$post          = get_post( $event_id );
		setup_postdata( $post );

		$output = do_blocks(
			'<!-- wp:gatherpress/venue -->'
			. '<!-- wp:post-title {"level":0,"isLink":false} /-->'
			. '<!-- wp:gatherpress/venue-detail {"fieldType":"address"} /-->'
			. '<!-- /wp:gatherpress/venue -->'
		);

		wp_reset_postdata();
		$post = $original_post;
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited

		return $output;
	}

	/**
	 * The venue block must render for logged-out visitors.
	 *
	 * GatherPress 0.35.4 gates the block's source post on
	 * `is_post_publicly_viewable() || current_user_can( 'read_post' )`, and
	 * the first arm reads `publicly_queryable`, which the tweak above turns
	 * off. Anonymous visitors therefore got an empty block: no Location
	 * heading, no venue name, no address, on every public event page and
	 * card. Logged-in users passed the second arm, which is why it went
	 * unnoticed. See #2027.
	 */
	public function test_venue_block_renders_for_logged_out_visitors() {
		$event_id = $this->create_event_with_venue();

		wp_set_current_user( 0 );

		$output = $this->render_venue_block( $event_id );

		$this->assertStringContainsString( 'Salty Spaces', $output );
		$this->assertStringContainsString( 'Mooloolaba', $output );
	}

	/**
	 * The override is scoped to the venue block's own render.
	 *
	 * A standing `is_post_type_viewable` override would leak into every
	 * other caller — `WP_Sitemaps_Posts` above all, which would then list
	 * venue URLs that 404.
	 */
	public function test_venue_visibility_override_does_not_outlive_the_block() {
		$event_id = $this->create_event_with_venue();

		wp_set_current_user( 0 );

		$this->assertFalse( is_post_type_viewable( get_post_type_object( 'gatherpress_venue' ) ) );

		$this->render_venue_block( $event_id );

		$this->assertFalse(
			is_post_type_viewable( get_post_type_object( 'gatherpress_venue' ) ),
			'The venue visibility override outlived the block render.'
		);
		$this->assertNotContains(
			'gatherpress_venue',
			array_keys( ( new \WP_Sitemaps_Posts() )->get_object_subtypes() ),
			'Venues must stay out of the sitemap - they have no front-end URL.'
		);
	}

	/**
	 * The venue description is an append, not a standalone renderer.
	 *
	 * When GatherPress renders nothing, appending the description to that
	 * empty string leaves a stray sentence in the event card with no
	 * heading, name or address around it - which is what made the venue
	 * zone unrecognizable in #2027.
	 */
	public function test_venue_description_is_not_appended_to_an_empty_block() {
		$event_id = $this->create_event_with_venue();
		$venue    = get_page_by_path( 'salty-spaces', OBJECT, 'gatherpress_venue' );

		$this->assertNotNull( $venue, 'The venue fixture was not created.' );

		wp_update_post(
			array(
				'ID'           => $venue->ID,
				'post_content' => 'An entire pub floor just for us!',
			)
		);
		update_post_meta( $venue->ID, 'gatherpress_access_requirements', 'Step-free entrance.' );

		// The append only runs on a singular event, so put the query there.
		// Rewrite rules aren't flushed in this suite, so `go_to()` can't
		// resolve the permalink - set the query up directly instead.
		global $wp_query, $post;

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
		$original_query = $wp_query;
		$original_post  = $post;

		$wp_query = new \WP_Query(
			array(
				'p'         => $event_id,
				'post_type' => 'gatherpress_event',
			)
		);
		$wp_query->the_post();
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited

		$this->assertTrue( is_singular( 'gatherpress_event' ), 'Could not set up the singular event request.' );

		// phpcs:disable WordPress.NamingConventions.ValidHookName.UseUnderscores -- GatherPress's block filter name.
		$this->assertSame(
			'',
			apply_filters( 'render_block_gatherpress/venue', '', array(), null ),
			'The description was appended to an empty venue block, stranding it in the event card.'
		);

		// It must still append when GatherPress actually rendered the venue.
		$rendered = apply_filters( 'render_block_gatherpress/venue', '<div class="wp-block-gatherpress-venue"></div>', array(), null );
		// phpcs:enable WordPress.NamingConventions.ValidHookName.UseUnderscores

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_reset_postdata();
		$wp_query = $original_query;
		$post     = $original_post;
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited

		$this->assertStringContainsString( 'An entire pub floor just for us!', $rendered );
		$this->assertStringContainsString( 'Step-free entrance.', $rendered );
	}

	/**
	 * On an event page, the venue shows its description once without the seeded address, and "More events" cards show none.
	 */
	public function test_venue_description_on_the_event_page() {
		$event_id = $this->create_event_with_venue();
		$card_id  = $this->create_event_with_venue( 'Peregian Digital Hub' );

		// GatherPress seeds venue content with a venue block; the list is the organizer's description.
		wp_update_post(
			array(
				'ID'           => get_page_by_path( 'salty-spaces', OBJECT, 'gatherpress_venue' )->ID,
				'post_content' => '<!-- wp:gatherpress/venue --><!-- wp:gatherpress/venue-detail {"fieldType":"address"} /--><!-- /wp:gatherpress/venue -->'
					. '<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>Use the side door.</li><!-- /wp:list-item --></ul><!-- /wp:list -->',
			)
		);
		wp_update_post(
			array(
				'ID'           => get_page_by_path( 'peregian-digital-hub', OBJECT, 'gatherpress_venue' )->ID,
				'post_content' => 'Park out the back.',
			)
		);

		$this->go_to_front_end( home_url( "?p={$event_id}&post_type=gatherpress_event" ) );
		$this->assertTrue( is_singular( 'gatherpress_event' ), 'Could not set up the event page.' );

		$venue = $this->render_venue_block( $event_id );
		$card  = $this->render_venue_block( $card_id );

		$this->assertStringContainsString( 'Use the side door.', $venue );
		$this->assertSame( 1, substr_count( $venue, 'The Wharf Mooloolaba' ), 'The description repeats the venue address.' );
		$this->assertStringNotContainsString( 'Park out the back.', $card, 'A "More events" card got its venue description.' );
	}

	/**
	 * A password-protected event's venue block is blanked until the password
	 * is entered, so the venue does not show on the event's own page or card.
	 */
	public function test_venue_block_is_hidden_for_a_password_protected_event() {
		$event_id = $this->create_event_with_venue();
		wp_update_post(
			array(
				'ID'            => $event_id,
				'post_password' => 'secret-pass',
			)
		);

		wp_set_current_user( 0 );

		$gated = $this->render_venue_block( $event_id );
		$this->assertStringNotContainsString( 'Salty Spaces', $gated );
		$this->assertStringNotContainsString( 'Mooloolaba', $gated );

		// Entering the password reveals the venue again.
		require_once ABSPATH . WPINC . '/class-phpass.php';
		$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = ( new \PasswordHash( 8, true ) )->HashPassword( 'secret-pass' );

		$revealed = $this->render_venue_block( $event_id );

		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );

		$this->assertStringContainsString( 'Salty Spaces', $revealed );
	}

	/**
	 * The REST filter strips a protected event's speakers for a caller who
	 * cannot edit it, and keeps them for one who can.
	 */
	public function test_rest_filter_hides_speakers_for_protected_event() {
		$event = self::factory()->post->create_and_get(
			array(
				'post_type'     => 'gatherpress_event',
				'post_password' => 'secret-pass',
			)
		);

		$prepare = static function () use ( $event ): array {
			$response = new \WP_REST_Response( array( 'meta' => array( '_event_speakers' => array( 101, 102 ) ) ) );
			$request  = new \WP_REST_Request( 'GET', '/wp/v2/gatherpress_events/' . $event->ID );

			return apply_filters( 'rest_prepare_gatherpress_event', $response, $event, $request )->get_data();
		};

		wp_set_current_user( 0 );
		$hidden = $prepare();

		$this->assertArrayNotHasKey( '_event_speakers', $hidden['meta'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$kept = $prepare();

		$this->assertSame( array( 101, 102 ), $kept['meta']['_event_speakers'] );
	}

	/**
	 * A protected event's venue and `geo_*` meta stay out of REST, even on the
	 * read that GatherPress refreshes the geo meta, and an editor keeps them.
	 *
	 * GatherPress 0.36.1 blanks both itself, after `Geo_Sync` writes freshly
	 * computed values back into the response. Run through the real hooks, so a
	 * GatherPress change that drops either gate fails here.
	 */
	public function test_rest_hides_protected_event_venue_and_geo_meta() {
		$event_id = $this->create_dated_event_with_venue( 'secret-pass' );
		$event    = get_post( $event_id );
		$geo_keys = array( 'geo_address', 'geo_latitude', 'geo_longitude' );

		// GatherPress wires its venue gate on `rest_api_init`, which has
		// already fired; the hook registry is restored after each test.
		\GatherPress\Core\Venue\Setup::get_instance()->register_password_protected_venue_rest_hooks();

		$prepare = static function () use ( $event, $geo_keys ): array {
			// Stale, as on the first read after the upgrade.
			foreach ( $geo_keys as $key ) {
				delete_post_meta( $event->ID, $key );
			}

			$response = new \WP_REST_Response(
				array(
					'meta'               => array(),
					'_gatherpress_venue' => wp_get_object_terms( $event->ID, '_gatherpress_venue', array( 'fields' => 'ids' ) ),
				)
			);
			$request  = new \WP_REST_Request( 'GET', '/wp/v2/gatherpress_events/' . $event->ID );

			return apply_filters( 'rest_prepare_gatherpress_event', $response, $event, $request )->get_data();
		};

		wp_set_current_user( 0 );
		$visitor = $prepare();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$editor = $prepare();

		$this->assertNotEmpty( $editor['meta']['geo_address'] ?? '', 'GatherPress did not refresh the geo meta, so nothing was tested.' );
		$this->assertNotEmpty( $editor['_gatherpress_venue'], 'An editor lost the venue.' );
		$this->assertEmpty( $visitor['_gatherpress_venue'], 'The venue reached a logged-out visitor.' );

		foreach ( $geo_keys as $key ) {
			$this->assertEmpty( $visitor['meta'][ $key ] ?? '', "{$key} reached a logged-out visitor." );
		}
	}

	/**
	 * `post_class()` drops the venue term class for a password-protected event.
	 *
	 * Run through the `post_class` filter on a fixed class list: whether core
	 * adds the term class depends on taxonomy registration order, which is
	 * flaky in isolation.
	 */
	public function test_post_class_drops_venue_class_for_protected_event() {
		$public_id    = $this->create_event_with_venue();
		$protected_id = $this->create_dated_event_with_venue( 'secret-pass' );
		$classes      = array( 'type-gatherpress_event', '_gatherpress_venue-_salty-spaces', 'hentry' );

		wp_set_current_user( 0 );

		$this->assertContains( '_gatherpress_venue-_salty-spaces', apply_filters( 'post_class', $classes, array(), $public_id ) );
		$this->assertNotContains( '_gatherpress_venue-_salty-spaces', apply_filters( 'post_class', $classes, array(), $protected_id ) );
	}

	/**
	 * A query filtered by venue leaves out protected events for a logged-out
	 * visitor, both when it includes and when it excludes the venue.
	 */
	public function test_venue_filter_excludes_protected_events_for_anonymous() {
		$public_id    = $this->create_event_with_venue();
		$protected_id = $this->create_dated_event_with_venue( 'secret-pass' );
		$term_id      = wp_get_object_terms( $public_id, '_gatherpress_venue', array( 'fields' => 'ids' ) )[0];

		// GatherPress leaves admin screens alone, and this suite runs as one.
		set_current_screen( 'front' );
		wp_set_current_user( 0 );

		$found = static function ( string $operator ) use ( $term_id ): array {
			return ( new \WP_Query(
				array(
					'post_type' => 'gatherpress_event',
					'fields'    => 'ids',
					'tax_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						array(
							'taxonomy' => '_gatherpress_venue',
							'terms'    => array( $term_id ),
							'operator' => $operator,
						),
					),
				)
			) )->posts;
		};

		$this->assertContains( $public_id, $found( 'IN' ) );
		$this->assertNotContains( $protected_id, $found( 'IN' ) );
		$this->assertNotContains( $protected_id, $found( 'NOT IN' ) );
	}

	/**
	 * The venue terms route returns no venue for a protected event the caller
	 * cannot edit, and is left alone for a public event.
	 */
	public function test_terms_route_hides_venue_for_protected_event() {
		$public_id    = $this->create_event_with_venue();
		$protected_id = $this->create_dated_event_with_venue( 'secret-pass' );

		// GatherPress wires its venue gate on `rest_api_init`, which has
		// already fired; the hook registry is restored after each test.
		\GatherPress\Core\Venue\Setup::get_instance()->register_password_protected_venue_rest_hooks();
		wp_set_current_user( 0 );

		$query_args = static function ( int $post_id ): array {
			$request = new \WP_REST_Request( 'GET', '/wp/v2/_gatherpress_venue' );
			$request->set_param( 'post', $post_id );

			return apply_filters( 'rest__gatherpress_venue_query', array( 'post' => $post_id ), $request );
		};

		$this->assertSame( $public_id, $query_args( $public_id )['post'] );
		$this->assertSame( array( 0 ), $query_args( $protected_id )['post'] );
	}

	/**
	 * Build an event's feed excerpt and content through GatherPress's filters,
	 * the way the feed template does.
	 *
	 * @param int $event_id The event.
	 *
	 * @return string The excerpt followed by the content.
	 */
	private function get_event_feed_text( int $event_id ): string {
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
		global $post;
		$original_post = $post;
		$post          = get_post( $event_id );
		setup_postdata( $post );

		$text = apply_filters( 'gatherpress_event_feed_excerpt', '' ) . apply_filters( 'gatherpress_event_feed_content', 'Body text.' );

		$post = $original_post;
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_reset_postdata();

		return $text;
	}

	/**
	 * Data provider for test_feed_strips_venue_for_protected_event().
	 *
	 * @return array[]
	 */
	public function data_feed_venue_formats(): array {
		return array(
			'venue only'                => array( 'Salty Spaces', '%1$s: %2$s', false ),
			'after the date'            => array( 'Salty Spaces', '%1$s: %2$s', true ),
			'name with a pipe'          => array( 'Bar | Grill', '%1$s: %2$s', true ),
			// GatherPress's French translation puts a space before the colon.
			'french punctuation'        => array( 'Salty Spaces', '%1$s : %2$s', true ),
			'french punctuation, alone' => array( 'Salty Spaces', '%1$s : %2$s', false ),
			'regex metacharacters'      => array( 'Café (Rooftop) $1 / [B]', '%1$s: %2$s', true ),
		);
	}

	/**
	 * The event RSS feed excerpt/content drops the venue for a protected event
	 * and leaves a public event's untouched, whatever the venue is called and
	 * however the locale punctuates the label.
	 *
	 * @dataProvider data_feed_venue_formats
	 *
	 * @param string $venue_name The venue's title.
	 * @param string $format     The translated `%1$s: %2$s` format.
	 * @param bool   $has_date   Whether the event has a date, which precedes the venue on the metadata line.
	 */
	public function test_feed_strips_venue_for_protected_event( string $venue_name, string $format, bool $has_date ) {
		$translate = static function ( $translation, $text, $domain ) use ( $format ) {
			return ( 'gatherpress' === $domain && '%1$s: %2$s' === $text ) ? $format : $translation;
		};
		add_filter( 'gettext', $translate, 10, 3 );

		$public_id    = $this->create_event_with_venue( $venue_name );
		$protected_id = $this->create_event_with_venue( $venue_name );
		wp_update_post(
			array(
				'ID'            => $protected_id,
				'post_password' => 'secret-pass',
			)
		);

		if ( $has_date ) {
			foreach ( array( $public_id, $protected_id ) as $event_id ) {
				( new \GatherPress\Core\Event\Event( $event_id ) )->save_datetimes(
					array(
						'post_id'        => $event_id,
						'datetime_start' => gmdate( 'Y-m-d H:i:s', strtotime( '+7 days' ) ),
						'datetime_end'   => gmdate( 'Y-m-d H:i:s', strtotime( '+7 days +2 hours' ) ),
						'timezone'       => 'UTC',
					)
				);
			}
		}

		$public_out    = $this->get_event_feed_text( $public_id );
		$protected_out = $this->get_event_feed_text( $protected_id );

		remove_filter( 'gettext', $translate, 10 );

		$this->assertStringContainsString( $venue_name, $public_out, 'The public event lost its venue.' );
		$this->assertStringNotContainsString( $venue_name, $protected_out );
		$this->assertStringNotContainsString( '|', $protected_out, 'A stray separator was left behind.' );
		$this->assertStringContainsString( 'Body text.', $protected_out );

		// GatherPress 0.36.1 leaves a protected event's date out of the feed
		// too, so only the public event keeps it.
		if ( $has_date ) {
			$this->assertStringContainsString( '<strong>Date: ', $public_out, 'The public event lost its date.' );
			$this->assertStringNotContainsString( '<strong>Date: ', $protected_out );
		}
	}

	/**
	 * The meta-link block (session video and slides links) returns nothing for
	 * a password-protected post, so the links do not show above the password
	 * form.
	 */
	public function test_meta_link_block_is_hidden_for_a_password_protected_post() {
		// The block lives in the blocks mu-plugin, which this suite does not
		// load; pull in its controller so the gate can be exercised here.
		if ( ! function_exists( 'WordCamp\Blocks\MetaLink\render' ) ) {
			$controller = dirname( __DIR__, 2 ) . '/blocks/source/blocks/meta-link/controller.php';

			$this->assertFileIsReadable( $controller, 'The wordcamp/meta-link block could not be loaded.' );
			require_once $controller;
		}

		register_post_meta(
			'gatherpress_event',
			'_test_meta_link_url',
			array(
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
			)
		);

		$event_id = self::factory()->post->create( array( 'post_type' => 'gatherpress_event' ) );
		update_post_meta( $event_id, '_test_meta_link_url', 'https://example.org/slides' );

		$block = (object) array(
			'context' => array(
				'postId'   => $event_id,
				'postType' => 'gatherpress_event',
			),
		);

		$attributes = array(
			'key'  => '_test_meta_link_url',
			'text' => 'View slides',
		);

		wp_set_current_user( 0 );

		// Public post: the link renders.
		$this->assertStringContainsString(
			'https://example.org/slides',
			\WordCamp\Blocks\MetaLink\render( $attributes, '', $block )
		);

		// Password-protected: nothing.
		wp_update_post(
			array(
				'ID'            => $event_id,
				'post_password' => 'secret-pass',
			)
		);
		$this->assertSame( '', \WordCamp\Blocks\MetaLink\render( $attributes, '', $block ) );
	}

	/**
	 * Create a dated event with a venue, optionally password-protected.
	 *
	 * @param string $password The event's password, if any.
	 *
	 * @return int The event's post ID.
	 */
	private function create_dated_event_with_venue( string $password = '' ): int {
		$event_id = $this->create_event_with_venue();

		wp_update_post(
			array(
				'ID'            => $event_id,
				'post_password' => $password,
			)
		);

		( new \GatherPress\Core\Event\Event( $event_id ) )->save_datetimes(
			array(
				'post_id'        => $event_id,
				'datetime_start' => gmdate( 'Y-m-d H:i:s', strtotime( '+7 days' ) ),
				'datetime_end'   => gmdate( 'Y-m-d H:i:s', strtotime( '+7 days +2 hours' ) ),
				'timezone'       => 'UTC',
			)
		);

		return $event_id;
	}

	/**
	 * A protected event stays out of the calendar feeds, which every viewer
	 * shares, while a public one is listed.
	 */
	public function test_calendar_feed_leaves_out_protected_event() {
		$public_id    = $this->create_dated_event_with_venue();
		$protected_id = $this->create_dated_event_with_venue( 'secret-pass' );

		wp_set_current_user( 0 );

		// The arguments GatherPress's iCal feed passes.
		$listed = \GatherPress\Core\Event\Query::get_instance()->get_events_list( 'upcoming', 10, array(), array(), false )->posts;

		$this->assertContains( $public_id, $listed );
		$this->assertNotContains( $protected_id, $listed );
	}

	/**
	 * Our venue filter blanks a protected event's venue even when GatherPress
	 * renders it, so the gate doesn't rest on GatherPress hiding it first.
	 */
	public function test_venue_filter_blanks_venue_markup_for_protected_event() {
		global $post;

		$public_id    = $this->create_dated_event_with_venue();
		$protected_id = $this->create_dated_event_with_venue( 'secret-pass' );
		$markup       = '<div class="wp-block-gatherpress-venue">Salty Spaces</div>';

		wp_set_current_user( 0 );

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
		$original_post = $post;

		$post = get_post( $public_id );
		$this->assertSame( $markup, apply_filters( 'render_block_gatherpress/venue', $markup, array(), null ) );

		$post = get_post( $protected_id );
		$this->assertSame( '', apply_filters( 'render_block_gatherpress/venue', $markup, array(), null ) );

		$post = $original_post;
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * GatherPress hides a protected event's venue from a logged-out visitor,
	 * which also keeps its venue feed out of the page head, and shows it to
	 * an editor.
	 */
	public function test_protected_event_venue_is_hidden_from_visitors() {
		$public_id    = $this->create_dated_event_with_venue();
		$protected_id = $this->create_dated_event_with_venue( 'secret-pass' );
		$venue_setup  = \GatherPress\Core\Venue\Setup::get_instance();

		wp_set_current_user( 0 );

		$this->assertFalse( $venue_setup->is_event_venue_hidden( get_post( $public_id ) ) );
		$this->assertTrue( $venue_setup->is_event_venue_hidden( get_post( $protected_id ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertFalse( $venue_setup->is_event_venue_hidden( get_post( $protected_id ) ), 'An editor lost the venue.' );
	}

	/**
	 * Search block on the events archive rewrites form action, removes required,
	 * adds the event_time hidden input, and marks the form for events search clear.
	 */
	public function test_search_block_removes_required_and_handles_clearing() {
		global $wp_query;

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.ValidHookName.UseUnderscores
		$original_query                 = $wp_query;
		$wp_query                       = new \WP_Query();
		$wp_query->is_post_type_archive = true;
		$wp_query->set( 'post_type', 'gatherpress_event' );

		$input_html = '<form role="search" method="get" action="https://example.org">'
			. '<input type="search" name="s" required />'
			. '</form>';

		$output = apply_filters( 'render_block_core/search', $input_html );

		$archive_url = get_post_type_archive_link( 'gatherpress_event' );
		$this->assertStringContainsString( 'action="' . esc_url( $archive_url ) . '"', $output );
		$this->assertStringContainsString( 'data-events-search-form="1"', $output );
		$this->assertStringNotContainsString( 'required', $output );
		$this->assertStringContainsString( '<input type="hidden" name="event_time" value="all" />', $output );
		$this->assertStringNotContainsString( '<script', $output );

		$processor = new \WP_HTML_Tag_Processor( $output );
		$this->assertTrue( $processor->next_tag( 'form' ) );
		$this->assertSame( '1', $processor->get_attribute( 'data-events-search-form' ) );
		$this->assertTrue( $processor->next_tag( 'input' ) );
		$this->assertNull( $processor->get_attribute( 'required' ) );

		$wp_query = $original_query;
		// phpcs:enable
	}

	/**
	 * Search block on other pages remains untouched.
	 */
	public function test_search_block_untouched_outside_event_archive() {
		global $wp_query;

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.ValidHookName.UseUnderscores
		$original_query                 = $wp_query;
		$wp_query                       = new \WP_Query();
		$wp_query->is_post_type_archive = false;

		$input_html = '<form role="search" method="get" action="https://example.org">'
			. '<input type="search" name="s" required />'
			. '</form>';

		$output = apply_filters( 'render_block_core/search', $input_html );

		$this->assertSame( $input_html, $output );

		$wp_query = $original_query;
		// phpcs:enable
	}

	/**
	 * A search keeps the "All" view, but an empty one reverts it to upcoming.
	 *
	 * @dataProvider data_event_time_filter_with_a_search
	 *
	 * @param string      $event_time The `event_time` query arg.
	 * @param string|null $search     The `s` query arg, or null for none.
	 * @param string      $expected   The view the toggle applies.
	 * @param string      $label      The toggle's label.
	 */
	public function test_event_time_filter_with_a_search( string $event_time, ?string $search, string $expected, string $label ) {
		if ( null === $search ) {
			unset( $_GET['s'] );
		} else {
			$_GET['s'] = $search;
		}

		$filter = $this->get_event_time_filter( $event_time );
		unset( $_GET['s'] );

		$this->assertSame( $label, $filter['label'] );
		$this->assertSame( array( $expected ), $filter['selected'] );
	}

	/**
	 * Inputs for `test_event_time_filter_with_a_search()`.
	 */
	public function data_event_time_filter_with_a_search(): array {
		return array(
			'all, empty search'  => array( 'all', '', 'upcoming', 'Time: Upcoming' ),
			'all, blank search'  => array( 'all', '   ', 'upcoming', 'Time: Upcoming' ),
			'all, search text'   => array( 'all', 'wordcamp', 'all', 'Time: All' ),
			'all, no search'     => array( 'all', null, 'all', 'Time: All' ),
			'past, empty search' => array( 'past', '', 'past', 'Time: Past' ),
		);
	}

	/**
	 * Make a user a network admin.
	 *
	 * `grant_super_admin()` reads `site_admins` off whichever network is
	 * current, which in this fixture isn't the groups network, so set the
	 * option `get_super_admins()` actually consults, as the other Groups
	 * tests do.
	 *
	 * @param int $user_id User.
	 */
	protected function make_network_admin( int $user_id ): void {
		update_site_option( 'site_admins', array( get_userdata( $user_id )->user_login ) );
	}

	/**
	 * Organizers can't switch the theme, since they couldn't switch back to
	 * `groups-site` afterwards (#2368). Network admins still can.
	 */
	public function test_only_network_admins_can_switch_themes() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$super_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->make_network_admin( $super_id );

		$this->assertFalse( user_can( $admin_id, 'switch_themes' ) );
		$this->assertTrue( user_can( $admin_id, 'edit_theme_options' ) );
		$this->assertTrue( user_can( $super_id, 'switch_themes' ) );
	}

	/**
	 * A group site that ends up on another theme anyway is switched back.
	 *
	 * Core fires `after_switch_theme` from `check_theme_switched()` on the
	 * load after a switch, so that's what runs the backstop here. That load is
	 * Appearance > Themes with `?activated=true` after a switch made there,
	 * and its "New theme activated." notice is dropped, since the theme it
	 * would be about is gone again.
	 */
	public function test_group_site_switched_back_to_groups_site() {
		$site_id = self::factory()->blog->create(
			array(
				'domain'     => 'events.wordpress.test',
				'path'       => '/group/theme-backstop/',
				'network_id' => GROUPS_NETWORK_ID,
			)
		);

		switch_to_blog( $site_id );

		try {
			switch_theme( 'twentytwentyfour' );
			$_GET['activated'] = 'true';
			check_theme_switched();

			$this->assertSame( 'groups-site', get_stylesheet() );
			$this->assertArrayNotHasKey( 'activated', $_GET );
		} finally {
			unset( $_GET['activated'] );
			restore_current_blog();
		}
	}

	/**
	 * The network's root site isn't a group, so it keeps its theme.
	 */
	public function test_groups_root_site_theme_left_alone() {
		switch_to_blog( GROUPS_ROOT_BLOG_ID );

		try {
			switch_theme( 'twentytwentyfour' );
			check_theme_switched();

			$this->assertSame( 'twentytwentyfour', get_stylesheet() );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Organizers can't unlock the theme's locked blocks or edit the markup
	 * around them; network admins can.
	 */
	public function test_block_locking_and_code_editing_off_for_organizers() {
		$settings = array(
			'canLockBlocks'      => true,
			'codeEditingEnabled' => true,
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$filtered = apply_filters( 'block_editor_settings_all', $settings, new \WP_Block_Editor_Context() );

		$this->assertFalse( $filtered['canLockBlocks'] );
		$this->assertFalse( $filtered['codeEditingEnabled'] );

		$super_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->make_network_admin( $super_id );
		wp_set_current_user( $super_id );
		$filtered = apply_filters( 'block_editor_settings_all', $settings, new \WP_Block_Editor_Context() );

		$this->assertTrue( $filtered['canLockBlocks'] );
		$this->assertTrue( $filtered['codeEditingEnabled'] );
	}

	/**
	 * A locked block hidden with "Omit from published content" still renders,
	 * at the top of a template or inside another block; an unlocked one stays
	 * hidden.
	 */
	public function test_locked_blocks_ignore_hide() {
		$locked   = '<!-- wp:paragraph {"lock":{"move":true,"remove":true},"metadata":{"blockVisibility":false}} --><p>Locked</p><!-- /wp:paragraph -->';
		$nested   = '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph {"lock":{"move":true,"remove":true},"metadata":{"blockVisibility":false}} --><p>Nested</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
		$unlocked = '<!-- wp:paragraph {"metadata":{"blockVisibility":false}} --><p>Unlocked</p><!-- /wp:paragraph -->';

		$html = do_blocks( $locked . $nested . $unlocked );

		$this->assertStringContainsString( 'Locked', $html );
		$this->assertStringContainsString( 'Nested', $html );
		$this->assertStringNotContainsString( 'Unlocked', $html );
	}

	/**
	 * Run a callback with `groups-site` active from this repo's themes, so
	 * its template parts resolve.
	 *
	 * @param callable $callback What to run.
	 */
	protected function with_groups_site_theme( callable $callback ): void {
		global $wp_theme_directories;

		$directories = $wp_theme_directories;
		register_theme_directory( SUT_WP_CONTENT_DIR . 'themes' );
		wp_clean_themes_cache();
		switch_theme( 'groups-site' );

		try {
			$callback();
		} finally {
			$wp_theme_directories = $directories; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the list this changed.
			wp_clean_themes_cache();
		}
	}

	/**
	 * Organizers can't save the shared header or footer, but can save other
	 * template parts and reset the header to the theme's.
	 */
	public function test_header_and_footer_parts_locked_for_organizers() {
		$this->with_groups_site_theme( array( $this, 'assert_header_and_footer_parts_locked' ) );
	}

	/**
	 * The assertions for `test_header_and_footer_parts_locked_for_organizers()`.
	 */
	public function assert_header_and_footer_parts_locked(): void {
		// A header customized before the lock, which the organizer can reset.
		$custom_id = self::factory()->post->create(
			array(
				'post_type'    => 'wp_template_part',
				'post_name'    => 'header',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:site-title /-->',
			)
		);
		wp_set_object_terms( $custom_id, 'groups-site', 'wp_theme' );
		wp_set_object_terms( $custom_id, 'header', 'wp_template_part_area' );
		$this->assertSame( 'custom', get_block_template( 'groups-site//header', 'wp_template_part' )->source );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		foreach ( array( 'header', 'footer' ) as $slug ) {
			$request = new \WP_REST_Request( 'POST', "/wp/v2/template-parts/groups-site//$slug" );
			$request->set_param( 'content', '<!-- wp:site-title /-->' );

			$response = rest_do_request( $request );

			$this->assertSame( 403, $response->get_status(), $slug );
			$this->assertSame( 'groups_locked_template_part', $response->get_data()['code'], $slug );
		}

		// Core sanitizes a new part's slug when it saves it, so `HEADER` and
		// `Footer` would replace the shared parts too.
		foreach ( array( 'header', 'HEADER', 'Footer' ) as $slug ) {
			$request = new \WP_REST_Request( 'POST', '/wp/v2/template-parts' );
			$request->set_param( 'slug', $slug );
			$request->set_param( 'content', '<!-- wp:site-title /-->' );

			$this->assertSame( 403, rest_do_request( $request )->get_status(), "new $slug" );
		}

		$this->assertSame( 'theme', get_block_template( 'groups-site//footer', 'wp_template_part' )->source );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/template-parts' );
		$request->set_param( 'slug', 'sidebar' );
		$request->set_param( 'content', '<!-- wp:site-title /-->' );

		$this->assertSame( 201, rest_do_request( $request )->get_status(), 'other part' );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/template-parts/groups-site//header' );
		$request->set_param( 'source', 'theme' );

		$this->assertSame( 200, rest_do_request( $request )->get_status(), 'reset' );
		$this->assertSame( 'theme', get_block_template( 'groups-site//header', 'wp_template_part' )->source );
	}

	/**
	 * Network admins can still change the shared header.
	 */
	public function test_header_part_editable_by_network_admins() {
		$this->with_groups_site_theme( array( $this, 'assert_header_part_editable' ) );
	}

	/**
	 * The assertions for `test_header_part_editable_by_network_admins()`.
	 */
	public function assert_header_part_editable(): void {
		$super_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->make_network_admin( $super_id );
		wp_set_current_user( $super_id );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/template-parts/groups-site//header' );
		$request->set_param( 'content', '<!-- wp:site-title /-->' );

		$this->assertSame( 200, rest_do_request( $request )->get_status() );
	}
}
