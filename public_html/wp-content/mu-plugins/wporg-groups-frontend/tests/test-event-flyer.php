<?php

namespace WordCamp\Groups\Tests;

use GatherPress\Core\Event\Event;
use WordPressdotorg\GatherPress_Recurring_Events\Context;
use WordPressdotorg\GatherPress_Recurring_Events\Database as Recurring_Events_Database;
use WordPressdotorg\GatherPress_Recurring_Events\Occurrences;
use WordPressdotorg\GatherPress_Recurring_Events\Rule;
use WordCamp\Groups\Frontend\Event_Date_Format;
use function WordCamp\Groups\Frontend\Event_Flyer\get_flyer_url;
use function WordCamp\Groups\Frontend\Event_Flyer\get_rewrite_rules;
use function WordCamp\Groups\Frontend\Event_Flyer\is_flyer_request;
use function WordCamp\Groups\Frontend\Event_Flyer\keep_flyer_url;
use function WordCamp\Groups\Frontend\Event_Flyer\prepare_request;
use function WordCamp\Groups\Frontend\Event_Flyer\register_rewrite_rules;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * The printable event flyer (#2133): its route, who can reach it, and the
 * occurrence a dated flyer is for.
 *
 * @group groups
 */
class Test_Event_Flyer extends Groups_TestCase {

	const THEME_DIR = SUT_WP_CONTENT_DIR . 'themes/groups-site/';

	/**
	 * Load the theme's hooks: `groups-site` isn't the active theme in this
	 * suite, and the flyer's template is picked by its hierarchy filter.
	 *
	 * @param \WP_UnitTest_Factory $factory Shared fixture factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		parent::wpSetUpBeforeClass( $factory );

		require_once self::THEME_DIR . 'functions.php';
	}

	/**
	 * Pretty permalinks with the flyer rules in them, as on a group site.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->set_permalink_structure( '/%postname%/' );
		register_rewrite_rules();
		flush_rewrite_rules( false );
	}

	/**
	 * Leave no occurrence behind for the next test.
	 */
	protected function tearDown(): void {
		Context::set( null );
		delete_option( Event_Date_Format\DATE_OPTION );

		parent::tearDown();
	}

	/**
	 * A published event a month out.
	 *
	 * @param array $args Post fields to override.
	 */
	private function create_event( array $args = array() ): int {
		$event_id = self::factory()->post->create(
			array_merge(
				array(
					'post_type'   => Event::POST_TYPE,
					'post_status' => 'publish',
					'post_title'  => 'Flyer Test Meetup',
				),
				$args
			)
		);

		( new Event( $event_id ) )->save_datetimes(
			array(
				'post_id'        => $event_id,
				'datetime_start' => gmdate( 'Y-m-d H:i:s', strtotime( '+30 days' ) ),
				'datetime_end'   => gmdate( 'Y-m-d H:i:s', strtotime( '+30 days +2 hours' ) ),
				'timezone'       => 'UTC',
			)
		);

		return $event_id;
	}

	/**
	 * A published weekly series of three dates, the first two weeks out.
	 */
	private function create_recurring_event(): int {
		Recurring_Events_Database::maybe_install();

		$start    = ( new \DateTimeImmutable( '+14 days', new \DateTimeZone( 'UTC' ) ) )->setTime( 18, 0 );
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => Event::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => 'Weekly Flyer Meetup',
			)
		);

		( new Event( $event_id ) )->save_datetimes(
			array(
				'post_id'        => $event_id,
				'datetime_start' => $start->format( 'Y-m-d H:i:s' ),
				'datetime_end'   => $start->modify( '+2 hours' )->format( 'Y-m-d H:i:s' ),
				'timezone'       => 'UTC',
			)
		);

		update_post_meta( $event_id, Rule::META_PREFIX . 'frequency', 'weekly' );
		update_post_meta( $event_id, Rule::META_PREFIX . 'interval', 1 );
		update_post_meta( $event_id, Rule::META_PREFIX . 'weekdays', array( strtoupper( substr( $start->format( 'D' ), 0, 2 ) ) ) );
		update_post_meta( $event_id, Rule::META_PREFIX . 'end_type', 'count' );
		update_post_meta( $event_id, Rule::META_PREFIX . 'count', 3 );

		wp_update_post(
			array(
				'ID'          => $event_id,
				'post_status' => 'publish',
			)
		);

		return $event_id;
	}

	/**
	 * The rules follow the event post type's rewrite slug, which GatherPress
	 * localizes, rather than assuming `event/`.
	 */
	public function test_rewrite_rules_follow_the_event_rewrite_slug() {
		$post_type = get_post_type_object( Event::POST_TYPE );
		$original  = $post_type->rewrite['slug'];

		$post_type->rewrite['slug'] = 'evento';

		try {
			$patterns = array_keys( get_rewrite_rules() );
		} finally {
			$post_type->rewrite['slug'] = $original;
		}

		$this->assertCount( 2, $patterns );

		foreach ( $patterns as $pattern ) {
			$this->assertStringStartsWith( '^evento/', $pattern );
			$this->assertStringEndsWith( '/flyer/?$', $pattern );
		}
	}

	/**
	 * A deploy doesn't need a manual flush: a site whose stored rules lack
	 * the flyer's gets them dropped, so WordPress rebuilds them.
	 */
	public function test_missing_rules_are_flushed() {
		$rules = get_option( 'rewrite_rules' );
		$this->assertIsArray( $rules, 'Precondition: rewrite rules are stored.' );

		foreach ( array_keys( get_rewrite_rules() ) as $pattern ) {
			unset( $rules[ $pattern ] );
		}
		update_option( 'rewrite_rules', $rules );

		register_rewrite_rules();

		$this->assertFalse( get_option( 'rewrite_rules' ) );
	}

	/**
	 * A site that already has the rules keeps its stored copy.
	 */
	public function test_present_rules_are_not_flushed() {
		$before = get_option( 'rewrite_rules' );

		register_rewrite_rules();

		$this->assertSame( $before, get_option( 'rewrite_rules' ) );
	}

	/**
	 * `…/{slug}/flyer/` is the event, as a flyer.
	 */
	public function test_flyer_url_routes_to_its_event() {
		$event_id = $this->create_event();

		$this->go_to( get_flyer_url( $event_id ) );

		$this->assertTrue( is_singular( Event::POST_TYPE ) );
		$this->assertSame( $event_id, get_queried_object_id() );
		$this->assertTrue( is_flyer_request() );
	}

	/**
	 * The event's own page isn't a flyer.
	 */
	public function test_event_page_is_not_a_flyer() {
		$event_id = $this->create_event();

		$this->go_to( get_permalink( $event_id ) );

		$this->assertTrue( is_singular( Event::POST_TYPE ) );
		$this->assertFalse( is_flyer_request() );
	}

	/**
	 * `…/{slug}/{date}/flyer/` carries the occurrence as well.
	 */
	public function test_dated_flyer_url_carries_the_occurrence() {
		$event_id = $this->create_event();

		$this->go_to( trailingslashit( get_permalink( $event_id ) ) . '20261010T180000/flyer/' );

		$this->assertSame( $event_id, get_queried_object_id() );
		$this->assertTrue( is_flyer_request() );
		$this->assertSame( '20261010T180000', get_query_var( 'gpre_occurrence' ) );
	}

	/**
	 * The flyer template leads the hierarchy on a flyer, and only there.
	 */
	public function test_flyer_template_leads_the_hierarchy_only_on_a_flyer() {
		$event_id = $this->create_event();

		$this->go_to( get_flyer_url( $event_id ) );
		$flyer = apply_filters( 'single_template_hierarchy', array( 'single.php' ) );

		$this->go_to( get_permalink( $event_id ) );
		$page = apply_filters( 'single_template_hierarchy', array( 'single.php' ) );

		$this->assertSame( 'single-event-flyer', $flyer[0] );
		$this->assertSame( 'single-event', $page[0] );
		$this->assertNotContains( 'single-event-flyer', $page );
	}

	/**
	 * The query var is public, so it can ride on any URL. Anything that
	 * isn't a single event 404s instead of rendering as itself.
	 */
	public function test_flyer_query_var_on_a_non_event_is_a_404() {
		$post_id = self::factory()->post->create( array( 'post_title' => 'A news post' ) );

		$this->go_to( add_query_arg( 'wporg_event_flyer', '1', get_permalink( $post_id ) ) );
		$this->assertTrue( is_flyer_request(), 'Precondition: the query var was parsed.' );

		prepare_request();

		$this->assertTrue( is_404() );
	}

	/**
	 * A draft or private event has no flyer for a visitor who can't read the
	 * event itself: it's the same singular request, which WordPress 404s.
	 *
	 * @dataProvider data_unreadable_statuses
	 *
	 * @param string $status Post status.
	 */
	public function test_unreadable_event_flyer_is_a_404( string $status ) {
		$event_id = $this->create_event( array( 'post_status' => $status ) );

		wp_set_current_user( 0 );
		$this->go_to( home_url( "?p={$event_id}&post_type=gatherpress_event&wporg_event_flyer=1" ) );
		prepare_request();

		$this->assertTrue( is_404() );
		$this->assertFalse( is_singular( Event::POST_TYPE ) );
	}

	/**
	 * Statuses a logged-out visitor can't read.
	 */
	public function data_unreadable_statuses(): array {
		return array(
			'draft'   => array( 'draft' ),
			'private' => array( 'private' ),
		);
	}

	/**
	 * A private event's flyer works for someone who can read the event.
	 */
	public function test_private_event_flyer_renders_for_a_reader() {
		$event_id = $this->create_event( array( 'post_status' => 'private' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->go_to( home_url( "?p={$event_id}&post_type=gatherpress_event&wporg_event_flyer=1" ) );
		prepare_request();

		$this->assertFalse( is_404() );
		$this->assertSame( $event_id, get_queried_object_id() );
	}

	/**
	 * The flyer is a copy of the event page for paper: kept out of search,
	 * and printed without the toolbar.
	 */
	public function test_flyer_is_noindex_without_the_toolbar() {
		$event_id = $this->create_event();

		$this->go_to( get_flyer_url( $event_id ) );
		prepare_request();

		$this->assertSame( 10, has_filter( 'wp_robots', 'wp_robots_no_robots' ) );
		$this->assertTrue( apply_filters( 'wp_robots', array() )['noindex'] ?? false );
		$this->assertFalse( apply_filters( 'show_admin_bar', true ) );
	}

	/**
	 * The event page itself keeps its indexing and its toolbar.
	 */
	public function test_event_page_is_left_indexable() {
		$event_id = $this->create_event();

		$this->go_to( get_permalink( $event_id ) );
		prepare_request();

		// Whether the site as a whole is indexable is its own setting; all
		// this checks is that the flyer's opt-out wasn't applied here.
		$this->assertFalse( has_filter( 'wp_robots', 'wp_robots_no_robots' ) );
		$this->assertFalse( has_filter( 'show_admin_bar', '__return_false' ) );
	}

	/**
	 * Canonical redirects leave a flyer on its URL. The recurring-events
	 * extension would otherwise send a dated flyer to the date's event page.
	 */
	public function test_canonical_redirect_keeps_the_flyer_url() {
		$event_id = $this->create_event();

		$this->go_to( get_flyer_url( $event_id ) );
		$this->assertFalse( keep_flyer_url( get_permalink( $event_id ) ) );
		$this->assertFalse( apply_filters( 'redirect_canonical', get_permalink( $event_id ), get_flyer_url( $event_id ) ) );

		$this->go_to( get_permalink( $event_id ) );
		$this->assertSame( 'https://example.test/', keep_flyer_url( 'https://example.test/' ) );
	}

	/**
	 * The event page links to the flyer.
	 */
	public function test_event_page_links_to_the_flyer() {
		$event_id = $this->create_event();

		$this->go_to( get_permalink( $event_id ) );
		$output = do_blocks( '<!-- wp:wporg/event-flyer-link /-->' );

		$this->assertStringContainsString( 'href="' . esc_url( get_flyer_url( $event_id ) ) . '"', $output );
		$this->assertStringContainsString( 'Print flyer', $output );
	}

	/**
	 * A password-protected event's details aren't offered for printing.
	 */
	public function test_no_flyer_link_behind_the_password_gate() {
		$event_id = $this->create_event( array( 'post_password' => 'secret-pass' ) );

		$this->go_to( get_permalink( $event_id ) );

		$this->assertSame( '', trim( do_blocks( '<!-- wp:wporg/event-flyer-link /-->' ) ) );
	}

	/**
	 * The flyer's bar leads back to the event and offers a print button that
	 * only shows once its script can work it.
	 */
	public function test_flyer_toolbar_leads_back_to_the_event() {
		$event_id = $this->create_event();

		$this->go_to( get_flyer_url( $event_id ) );
		$output = do_blocks( '<!-- wp:wporg/event-flyer-link {"variant":"flyer"} /-->' );

		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $event_id ) ) . '"', $output );
		$this->assertMatchesRegularExpression( '/<button[^>]*class="[^"]*wporg-event-flyer-link__print[^"]*"[^>]*\shidden/', $output );
	}

	/**
	 * The QR code encodes the event's URL, and the URL is printed under it.
	 */
	public function test_qr_code_encodes_the_event_url() {
		$event_id = $this->create_event();

		$this->go_to( get_flyer_url( $event_id ) );
		$output = do_blocks( '<!-- wp:wporg/event-flyer-qr /-->' );

		$this->assertStringContainsString( 'data-url="' . esc_attr( get_permalink( $event_id ) ) . '"', $output );
		$this->assertStringContainsString(
			esc_html( untrailingslashit( preg_replace( '#^https?://#', '', get_permalink( $event_id ) ) ) ),
			$output
		);
	}

	/**
	 * A dated flyer is for that date: the date on the sheet, the QR code and
	 * the way back all point at the occurrence, not the series.
	 */
	public function test_dated_flyer_shows_and_links_its_occurrence() {
		update_option( Event_Date_Format\DATE_OPTION, 'F j, Y' );

		$event_id    = $this->create_recurring_event();
		$occurrences = Occurrences::all( $event_id, 'upcoming', 10 );
		$this->assertCount( 3, $occurrences, 'Precondition: the series projected its dates.' );

		$first  = $occurrences[0];
		$second = $occurrences[1];

		$this->go_to( Context::occurrence_url( $event_id, $second->recurrence_id ) . 'flyer/' );
		Context::resolve();

		$this->assertTrue( is_flyer_request() );
		$this->assertSame( $second->recurrence_id, Context::recurrence_id() );

		$timezone = Occurrences::timezone( (string) $second->timezone );
		$expected = wp_date( 'F j, Y', ( new \DateTimeImmutable( $second->datetime_start, $timezone ) )->getTimestamp(), $timezone );
		$other    = wp_date( 'F j, Y', ( new \DateTimeImmutable( $first->datetime_start, $timezone ) )->getTimestamp(), $timezone );

		$date = do_blocks( '<!-- wp:gatherpress/event-date {"displayType":"start","startDateFormat":"l, F j","showTimezone":"no","className":"has-group-date-format"} /-->' );

		$this->assertStringContainsString( $expected, $date );
		$this->assertStringNotContainsString( $other, $date );

		$occurrence_url = Context::occurrence_url( $event_id, $second->recurrence_id );
		$qr             = do_blocks( '<!-- wp:wporg/event-flyer-qr /-->' );
		$toolbar        = do_blocks( '<!-- wp:wporg/event-flyer-link {"variant":"flyer"} /-->' );

		$this->assertStringContainsString( 'data-url="' . esc_attr( $occurrence_url ) . '"', $qr );
		$this->assertStringContainsString( 'href="' . esc_url( $occurrence_url ) . '"', $toolbar );
	}

	/**
	 * On a dated event page, the flyer link is for that date.
	 */
	public function test_dated_event_page_links_to_its_dated_flyer() {
		$event_id    = $this->create_recurring_event();
		$occurrences = Occurrences::all( $event_id, 'upcoming', 10 );
		$second      = $occurrences[1];

		$this->go_to( Context::occurrence_url( $event_id, $second->recurrence_id ) );
		Context::resolve();

		$output = do_blocks( '<!-- wp:wporg/event-flyer-link /-->' );

		$this->assertStringContainsString(
			'href="' . esc_url( Context::occurrence_url( $event_id, $second->recurrence_id ) . 'flyer/' ) . '"',
			$output
		);
	}
}
