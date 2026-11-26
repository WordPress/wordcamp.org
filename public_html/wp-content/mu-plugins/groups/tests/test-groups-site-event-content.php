<?php

namespace WordCamp\Groups\Tests;

use WP_UnitTestCase;
use function WordCamp\Groups\Site\strip_blocks;
use const WordCamp\Groups\Site\EVENT_TEMPLATE_BLOCKS;

defined( 'WPINC' ) || die();

/**
 * The GatherPress blocks the single-event view strips from an event's content.
 *
 * `single-event.html` renders the date, venue, RSVP and attendees itself, so
 * the same blocks left in `post_content` showed up twice: attendees listed
 * once by `gatherpress/rsvp-response` in the content and again by
 * `wporg/event-attendees` below it.
 *
 * @group groups
 */
class Test_Groups_Site_Event_Content extends WP_UnitTestCase {

	/**
	 * Load the theme's functions. `groups-site` isn't the active theme in this
	 * suite, so its `functions.php` isn't picked up on its own.
	 *
	 * @param \WP_UnitTest_Factory $factory Shared fixture factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		require_once SUT_WP_CONTENT_DIR . 'themes/groups-site/functions.php';
	}

	/**
	 * Re-add the hooks under test: `WP_UnitTestCase` restores its hook
	 * snapshot after each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		add_filter( 'the_content', 'WordCamp\\Groups\\Site\\strip_event_metadata_blocks', 5 );
		add_filter( 'render_block_core/group', 'WordCamp\\Groups\\Site\\hide_empty_event_details', 10, 2 );
	}

	/**
	 * Strip the template's blocks from markup and serialize what is left.
	 *
	 * @param string $content Block markup.
	 * @return string Remaining block markup.
	 */
	private function strip( string $content ): string {
		return serialize_blocks( strip_blocks( parse_blocks( $content ), EVENT_TEMPLATE_BLOCKS ) );
	}

	/**
	 * The RSVP button and attendee list go, along with the metadata blocks.
	 */
	public function test_strips_rsvp_and_attendee_blocks(): void {
		$output = $this->strip(
			'<!-- wp:paragraph --><p>Description</p><!-- /wp:paragraph -->' .
			'<!-- wp:gatherpress/rsvp --><div class="wp-block-gatherpress-rsvp"></div><!-- /wp:gatherpress/rsvp -->' .
			'<!-- wp:gatherpress/rsvp-response --><div class="wp-block-gatherpress-rsvp-response"></div><!-- /wp:gatherpress/rsvp-response -->'
		);

		$this->assertStringContainsString( '<p>Description</p>', $output );
		$this->assertStringNotContainsString( 'gatherpress/rsvp', $output );
	}

	/**
	 * Blocks an organizer moved into a group are stripped too, and the group
	 * they leave empty goes with them.
	 */
	public function test_strips_nested_blocks_and_the_group_they_empty(): void {
		$output = $this->strip(
			'<!-- wp:paragraph --><p>Description</p><!-- /wp:paragraph -->' .
			'<!-- wp:group {"layout":{"type":"flex"}} --><div class="wp-block-group">' .
			'<!-- wp:gatherpress/online-event --><div class="wp-block-gatherpress-online-event"></div><!-- /wp:gatherpress/online-event -->' .
			'<!-- wp:gatherpress/add-to-calendar --><div class="wp-block-gatherpress-add-to-calendar"></div><!-- /wp:gatherpress/add-to-calendar -->' .
			'</div><!-- /wp:group -->'
		);

		$this->assertSame( '<!-- wp:paragraph --><p>Description</p><!-- /wp:paragraph -->', $output );
	}

	/**
	 * A group that still holds the organizer's own content keeps it.
	 */
	public function test_keeps_the_rest_of_a_group(): void {
		$output = $this->strip(
			'<!-- wp:group --><div class="wp-block-group">' .
			'<!-- wp:paragraph --><p>Bring a laptop</p><!-- /wp:paragraph -->' .
			'<!-- wp:gatherpress/venue --><div class="wp-block-gatherpress-venue"></div><!-- /wp:gatherpress/venue -->' .
			'</div><!-- /wp:group -->'
		);

		$this->assertSame(
			'<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Bring a laptop</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
			$output
		);
	}

	/**
	 * The "Details" section only renders when the event has a description.
	 */
	public function test_details_section_hides_for_a_blank_description(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a template file from disk, as test-groups-site-more-events.php does.
		$template = file_get_contents( SUT_WP_CONTENT_DIR . 'themes/groups-site/templates/single-event.html' );
		$section  = $this->find_details_section( parse_blocks( $template ) );
		$this->assertNotNull( $section, 'single-event.html no longer has the "Details" section.' );
		$section = serialize_block( $section );

		// The event form's empty paragraph, next to GatherPress's starter blocks.
		$blank = "<!-- wp:gatherpress/event-date /-->\n\n<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->";
		$this->assertSame( '', $this->render_on_event_page( $section, $blank ) );

		$output = $this->render_on_event_page( $section, '<!-- wp:paragraph --><p>Bring snacks.</p><!-- /wp:paragraph -->' );
		$this->assertStringContainsString( 'groups-site-event-details', $output );
		$this->assertStringContainsString( 'Bring snacks.', $output );
	}

	/**
	 * Find the "Details" group inside a parsed block tree.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return array|null The group, or null when it's missing.
	 */
	private function find_details_section( array $blocks ): ?array {
		foreach ( $blocks as $block ) {
			if ( str_contains( $block['attrs']['className'] ?? '', 'groups-site-event-details' ) ) {
				return $block;
			}

			$found = $this->find_details_section( $block['innerBlocks'] );
			if ( $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * Render block markup on the single view of a new event with the given content.
	 *
	 * @param string $markup  Block markup to render.
	 * @param string $content The event's content.
	 * @return string The rendered markup.
	 */
	private function render_on_event_page( string $markup, string $content ): string {
		$event_id = self::factory()->post->create(
			array(
				'post_type'    => 'gatherpress_event',
				'post_content' => $content,
			)
		);

		// Not `go_to()`: its `wp` action reaches sites this suite doesn't create.
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Viewing the event; tear_down() resets the query.
		$GLOBALS['wp_query']     = new \WP_Query(
			array(
				'p'         => $event_id,
				'post_type' => 'gatherpress_event',
			)
		);
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
		the_post();

		return trim( do_blocks( $markup ) );
	}
}
