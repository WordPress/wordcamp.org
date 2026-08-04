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
		$registered   = array_keys( \WP_Block_Type_Registry::get_instance()->get_all_registered() );
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

	/**
	 * The attendee summary is a native button and its avatar stack is
	 * decorative, so attendee names do not pollute the control's name.
	 */
	public function test_event_rsvp_summary_uses_accessible_markup() {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
				'post_title'  => 'Accessible RSVP Event',
			)
		);
		$user_id = self::factory()->user->create(
			array(
				'display_name' => 'Avatar Name Must Stay Decorative',
			)
		);

		$rsvp     = new \GatherPress\Core\Rsvp\Rsvp( $event_id );
		$response = $rsvp->save( $user_id, 'attending' );

		$this->assertSame( 'attending', $response['status'] );

		$this->go_to( home_url( "?p={$event_id}&post_type=gatherpress_event" ) );
		$output = do_blocks( '<!-- wp:wporg/event-rsvp /-->' );

		$this->assertMatchesRegularExpression( '/<button\s+type="button"\s+class="wporg-event-rsvp__summary"/', $output );
		$this->assertStringNotContainsString( 'role="button"', $output );
		$this->assertStringNotContainsString( 'actions.handleSummaryKeydown', $output );
		$this->assertSame( 2, substr_count( $output, 'alt=""' ) );
		$this->assertStringNotContainsString( 'alt="Avatar Name Must Stay Decorative"', $output );
	}

	/**
	 * RSVP success and failure messages need an always-present live region;
	 * the modal itself is hidden for the main RSVP-button flow.
	 */
	public function test_event_rsvp_renders_live_status_region_outside_modal() {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
			)
		);

		$this->go_to( home_url( "?p={$event_id}&post_type=gatherpress_event" ) );
		$output = do_blocks( '<!-- wp:wporg/event-rsvp /-->' );

		$status_position = strpos( $output, 'class="screen-reader-text wporg-event-rsvp__notice"' );
		$modal_position  = strpos( $output, 'class="wporg-event-rsvp__modal"' );

		$this->assertNotFalse( $status_position );
		$this->assertNotFalse( $modal_position );
		$this->assertLessThan( $modal_position, $status_position );
		$this->assertStringContainsString( 'role="status"', $output );
		$this->assertStringContainsString( 'aria-live="polite"', $output );
		$this->assertStringContainsString( 'aria-atomic="true"', $output );
		$this->assertStringContainsString( 'data-wp-text="context.rsvpNotice"', $output );
	}
}
