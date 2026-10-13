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
}
