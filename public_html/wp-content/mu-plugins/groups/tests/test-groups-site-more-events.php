<?php

namespace WordCamp\Groups\Tests;

use GatherPress\Core\Event\Event;
use WP_Query;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/../../wporg-groups-frontend/tests/class-groups-testcase.php';

/**
 * Coverage for the "More events from this group" section on the single event
 * page (#2126).
 *
 * The section is the template's own Query Loop, so these tests render that
 * block with the attributes `templates/single-event.html` gives it. Its cards
 * are swapped for a bare post title: the card pattern has its own tests, and
 * what's under test here is which events the loop returns.
 *
 * @group groups
 */
class Test_Groups_Site_More_Events extends Groups_TestCase {

	const THEME_DIR = SUT_WP_CONTENT_DIR . 'themes/groups-site/';

	/**
	 * The main query before a test replaced it with a singular event view.
	 *
	 * @var WP_Query|null
	 */
	private $main_query;

	/**
	 * Load the theme's hooks. `groups-site` isn't the active theme in this
	 * suite, so its `functions.php` isn't picked up on its own.
	 *
	 * @param \WP_UnitTest_Factory $factory Shared fixture factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		parent::wpSetUpBeforeClass( $factory );

		require_once self::THEME_DIR . 'functions.php';
	}

	/**
	 * Re-add the hooks under test: `WP_UnitTestCase` restores its hook
	 * snapshot after each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		add_filter( 'query_loop_block_query_vars', 'WordCamp\\Groups\\Site\\exclude_current_event_from_query', 10, 2 );
		add_filter( 'render_block_core/query', 'WordCamp\\Groups\\Site\\hide_empty_more_events_section', 10, 2 );
	}

	/**
	 * Put the main query back.
	 */
	protected function tearDown(): void {
		if ( $this->main_query ) {
			$GLOBALS['wp_query'] = $this->main_query;
			$this->main_query    = null;
		}

		parent::tearDown();
	}

	/**
	 * Create a published event starting some days from now.
	 *
	 * @param string $title The event title.
	 * @param int    $days  Days from now the event starts.
	 *
	 * @return int The event post ID.
	 */
	private function create_upcoming_event( string $title, int $days ): int {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);

		( new Event( $event_id ) )->save_datetimes(
			array(
				'post_id'        => $event_id,
				'datetime_start' => gmdate( 'Y-m-d H:i:s', strtotime( "+{$days} days" ) ),
				'datetime_end'   => gmdate( 'Y-m-d H:i:s', strtotime( "+{$days} days +2 hours" ) ),
				'timezone'       => 'UTC',
			)
		);

		return $event_id;
	}

	/**
	 * View an event the way its single page does.
	 *
	 * @param int $event_id The event post ID.
	 */
	private function view_event( int $event_id ): void {
		$this->main_query    = $GLOBALS['wp_query'];
		$GLOBALS['wp_query'] = new WP_Query(
			array(
				'p'         => $event_id,
				'post_type' => 'gatherpress_event',
			)
		);
	}

	/**
	 * The section's Query Loop attributes, as the template sets them.
	 */
	private function section_attrs(): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a template file from disk, as test-groups-site-event-info-card.php does.
		$markup = file_get_contents( self::THEME_DIR . 'templates/single-event.html' );

		$section = $this->find_section( parse_blocks( $markup ) );

		$this->assertNotNull( $section, 'single-event.html no longer has the "More events from this group" section.' );

		return $section['attrs'];
	}

	/**
	 * Find the section's Query Loop inside a parsed block tree.
	 *
	 * @param array $blocks Parsed blocks to walk.
	 *
	 * @return array|null The block, or null when it isn't present.
	 */
	private function find_section( array $blocks ): ?array {
		foreach ( $blocks as $block ) {
			if ( 'core/query' === $block['blockName'] && str_contains( $block['attrs']['className'] ?? '', 'groups-site-more-events' ) ) {
				return $block;
			}

			$found = $this->find_section( $block['innerBlocks'] );

			if ( $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * Render the section with each card reduced to its title.
	 */
	private function render_section(): string {
		$markup = sprintf(
			'<!-- wp:query %s --><section class="wp-block-query"><!-- wp:heading --><h2 class="wp-block-heading">More events from this group</h2><!-- /wp:heading --><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --></section><!-- /wp:query -->',
			wp_json_encode( $this->section_attrs() )
		);

		return do_blocks( $markup );
	}

	/**
	 * The section opts in to leaving the current event out.
	 */
	public function test_section_opts_in_to_excluding_the_current_event() {
		$attrs = $this->section_attrs();

		$this->assertTrue( $attrs['query']['groups_site_exclude_current'] ?? false );
		$this->assertSame( 'upcoming', $attrs['query']['gatherpress_event_query'] ?? '' );
	}

	/**
	 * The event being viewed isn't listed among the group's other events.
	 */
	public function test_section_lists_other_upcoming_events_but_not_the_current_one() {
		$current = $this->create_upcoming_event( 'The Event Being Viewed', 3 );
		$this->create_upcoming_event( 'Another Upcoming Event', 10 );
		$this->create_upcoming_event( 'A Later Upcoming Event', 20 );

		$this->view_event( $current );

		$html = $this->render_section();

		$this->assertStringContainsString( 'Another Upcoming Event', $html );
		$this->assertStringContainsString( 'A Later Upcoming Event', $html );
		$this->assertStringNotContainsString( 'The Event Being Viewed', $html );
	}

	/**
	 * With no other upcoming events, the heading doesn't render on its own.
	 */
	public function test_section_is_hidden_when_there_are_no_other_events() {
		$current = $this->create_upcoming_event( 'The Only Upcoming Event', 3 );

		$this->view_event( $current );

		$this->assertSame( '', $this->render_section() );
	}

	/**
	 * Other Query Loops that share the class-less event query are untouched.
	 */
	public function test_other_query_loops_are_not_hidden_when_empty() {
		$html = do_blocks(
			'<!-- wp:query {"query":{"postType":"gatherpress_event","perPage":3,"inherit":false}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --><!-- wp:query-no-results --><!-- wp:paragraph --><p>No upcoming events yet.</p><!-- /wp:paragraph --><!-- /wp:query-no-results --></div><!-- /wp:query -->'
		);

		$this->assertStringContainsString( 'No upcoming events yet.', $html );
	}
}
