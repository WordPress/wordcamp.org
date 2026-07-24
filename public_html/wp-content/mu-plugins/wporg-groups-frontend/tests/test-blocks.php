<?php

namespace WordCamp\Groups\Tests;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * @group groups
 */
class Test_Groups_Blocks extends Groups_TestCase {

	const EXPECTED_BLOCKS = array(
		'wporg/event-manage',
		'wporg/event-rsvp',
		'wporg/event-speakers',
		'wporg/group-members',
		'wporg/group-membership',
		'wporg/group-settings',
		'wporg/my-events',
		'wporg/page-content',
	);

	/**
	 * Exactly these 8 `wporg/*` blocks should be registered. The original
	 * set of 10 also included `event-rsvp-count` and `event-venue-name`;
	 * both were intentionally removed in favor of GatherPress core's own
	 * `gatherpress/rsvp-count` and `gatherpress/venue` blocks (see #1793's
	 * "Review fixes" for why) — if either reappears, or a new custom block
	 * duplicates core functionality again, this test should fail.
	 */
	public function test_exactly_the_expected_wporg_blocks_are_registered() {
		$registered = array_keys( \WP_Block_Type_Registry::get_instance()->get_all_registered() );
		$wporg_blocks = array_values(
			array_filter(
				$registered,
				static function ( $name ) {
					return str_starts_with( $name, 'wporg/' );
				}
			)
		);

		sort( $wporg_blocks );
		$expected = self::EXPECTED_BLOCKS;
		sort( $expected );

		$this->assertSame( $expected, $wporg_blocks );
	}

	/**
	 * The `render_block_gatherpress/rsvp-count` filter should suppress the
	 * block entirely when the resolved RSVP count is 0, so a "0 RSVPs" line
	 * never shows on a public page. Mirrors the `wp eval` check from #1793's
	 * manual test plan.
	 */
	public function test_rsvp_count_block_hidden_when_zero() {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
			)
		);

		global $post;
		$post = get_post( $event_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );

		$output = trim( (string) do_blocks( '<!-- wp:gatherpress/rsvp-count /-->' ) );

		wp_reset_postdata();

		$this->assertSame( '', $output );
	}
}
