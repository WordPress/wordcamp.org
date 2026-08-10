<?php

namespace WordCamp\Groups\Tests;

use GatherPress\Core\Event\Event;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/../../wporg-groups-frontend/tests/class-groups-testcase.php';

/**
 * Regression coverage for the `groups-site` theme's event-cards patterns.
 *
 * These patterns (`archive-past-events-cards`, `archive-upcoming-events-cards`,
 * `upcoming-events-cards`) are `Inserter: no` and never placed in any
 * template via `wp:pattern` — the real archive page queries events through
 * a native Query Loop block instead, a completely separate code path. That
 * meant a broken pattern (wrong GatherPress class imported — WordPress/
 * wordcamp.org#1874) went undetected across GatherPress versions: nothing
 * in the test suite ever executed these files, and the front end never hit
 * them either. WordPress only executes a pattern's PHP when building the
 * `/wp/v2/block-patterns/patterns` REST response for the block editor, so
 * that's the one thing that ever exercised the broken code path — and nothing
 * automated ever simulated it.
 *
 * These tests `include` the actual pattern files directly (not through the
 * block-patterns REST machinery) — cheaper to run, and it's the pattern's
 * own PHP that broke, not anything REST-specific.
 *
 * @group groups
 */
class Test_Groups_Site_Event_Cards_Patterns extends Groups_TestCase {

	const PATTERNS_DIR = SUT_WP_CONTENT_DIR . 'themes/groups-site/patterns';

	public static function wpSetUpBeforeClass( $factory ) {
		parent::wpSetUpBeforeClass( $factory );

		require_once SUT_WP_CONTENT_DIR . 'themes/groups-site/inc/event-cards.php';
	}

	/**
	 * Create a published event, far enough out to count as upcoming.
	 */
	private function create_upcoming_event(): int {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
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
	 * Create a published event far enough in the past to count as past.
	 */
	private function create_past_event(): int {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
			)
		);

		( new Event( $event_id ) )->save_datetimes(
			array(
				'post_id'        => $event_id,
				'datetime_start' => gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) ),
				'datetime_end'   => gmdate( 'Y-m-d H:i:s', strtotime( '-30 days +2 hours' ) ),
				'timezone'       => 'UTC',
			)
		);

		return $event_id;
	}

	/**
	 * Executes a pattern file exactly as WordPress would when building the
	 * block-patterns REST response, and returns its output.
	 */
	private function render_pattern( string $slug ): string {
		ob_start();
		require self::PATTERNS_DIR . "/{$slug}.php";
		return (string) ob_get_clean();
	}

	public function test_upcoming_events_cards_renders_with_an_event() {
		$event_id = $this->create_upcoming_event();
		$title    = get_the_title( $event_id );

		$output = $this->render_pattern( 'upcoming-events-cards' );

		$this->assertStringContainsString( $title, $output );
		$this->assertStringContainsString( 'groups-site-event-cards--compact', $output );
	}

	public function test_upcoming_events_cards_renders_empty_state_with_no_events() {
		$output = $this->render_pattern( 'upcoming-events-cards' );

		$this->assertStringContainsString( 'No upcoming events scheduled', $output );
	}

	public function test_archive_upcoming_events_cards_renders_with_an_event() {
		$event_id = $this->create_upcoming_event();
		$title    = get_the_title( $event_id );

		$output = $this->render_pattern( 'archive-upcoming-events-cards' );

		$this->assertStringContainsString( $title, $output );
		$this->assertStringContainsString( 'groups-site-event-cards--expanded', $output );
	}

	public function test_archive_upcoming_events_cards_renders_empty_state_with_no_events() {
		$output = $this->render_pattern( 'archive-upcoming-events-cards' );

		$this->assertStringContainsString( 'No upcoming events scheduled', $output );
	}

	public function test_archive_past_events_cards_renders_with_an_event() {
		$event_id = $this->create_past_event();
		$title    = get_the_title( $event_id );

		$output = $this->render_pattern( 'archive-past-events-cards' );

		$this->assertStringContainsString( $title, $output );
		$this->assertStringContainsString( 'groups-site-event-cards--expanded', $output );
	}

	public function test_archive_past_events_cards_renders_empty_state_with_no_events() {
		$output = $this->render_pattern( 'archive-past-events-cards' );

		$this->assertStringContainsString( 'No past events to show', $output );
	}

	/**
	 * A future (upcoming) event must not leak into the past-events pattern,
	 * and vice versa — confirms `Event\Query::get_past_events()`/
	 * `get_upcoming_events()` are actually being called (not just that the
	 * method call itself doesn't fatal), since a query that silently
	 * returned everything would still pass the tests above.
	 */
	public function test_past_and_upcoming_patterns_do_not_cross_contaminate() {
		$upcoming_id = $this->create_upcoming_event();
		$past_id     = $this->create_past_event();

		$upcoming_output = $this->render_pattern( 'archive-upcoming-events-cards' );
		$past_output      = $this->render_pattern( 'archive-past-events-cards' );

		$this->assertStringContainsString( get_the_title( $upcoming_id ), $upcoming_output );
		$this->assertStringNotContainsString( get_the_title( $past_id ), $upcoming_output );

		$this->assertStringContainsString( get_the_title( $past_id ), $past_output );
		$this->assertStringNotContainsString( get_the_title( $upcoming_id ), $past_output );
	}
}
