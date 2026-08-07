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
		'wporg/group-location',
		'wporg/group-settings',
		'wporg/group-news',
		'wporg/my-events',
		'wporg/page-content',
		'wporg/sponsors',
	);

	/**
	 * Exactly these 11 `wporg/*` blocks should be registered. An earlier
	 * set also included `event-rsvp-count` and `event-venue-name`;
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

	/**
	 * Unspecified locations leave no empty header markup.
	 */
	public function test_group_location_block_is_hidden_when_unspecified() {
		delete_site_meta( get_current_blog_id(), 'wporg_group_location_type' );
		delete_site_meta( get_current_blog_id(), 'wporg_group_location_city' );
		delete_site_meta( get_current_blog_id(), 'wporg_group_location_country' );

		$this->assertSame( '', trim( do_blocks( '<!-- wp:wporg/group-location /-->' ) ) );
	}

	/**
	 * Physical locations render their city and localized country.
	 */
	public function test_group_location_block_renders_physical_location() {
		update_site_meta( get_current_blog_id(), 'wporg_group_location_type', 'physical' );
		update_site_meta( get_current_blog_id(), 'wporg_group_location_city', 'İstanbul' );
		update_site_meta( get_current_blog_id(), 'wporg_group_location_country', 'TR' );

		$output = do_blocks( '<!-- wp:wporg/group-location /-->' );

		$this->assertStringContainsString( 'İstanbul', $output );
		$this->assertStringContainsString( wcorg_get_country_name_from_code( 'TR' ), $output );
		$this->assertStringContainsString( '<svg', $output );
		$this->assertStringContainsString( 'aria-hidden="true"', $output );
	}

	/**
	 * The online label depends only on the group location type.
	 */
	public function test_group_location_block_renders_online_location() {
		update_site_meta( get_current_blog_id(), 'wporg_group_location_type', 'online' );
		delete_site_meta( get_current_blog_id(), 'wporg_group_location_city' );
		delete_site_meta( get_current_blog_id(), 'wporg_group_location_country' );

		$output = do_blocks( '<!-- wp:wporg/group-location /-->' );

		$this->assertStringContainsString( 'Online', $output );
	}

	/**
	 * Combined identity and preference sections use an h2/h3 heading hierarchy.
	 */
	public function test_group_membership_block_renders_optional_sidebar_headings() {
		$member_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $member_id );

		$output = do_blocks(
			'<!-- wp:wporg/group-membership {"showLeave":false,"showPreference":true,"showHeadings":true} /-->'
		);

		$this->assertStringContainsString( '<h2 class="wporg-group-membership__heading">', $output );
		$this->assertStringContainsString( 'Membership', $output );
		$this->assertStringContainsString( '<h3 class="wporg-group-membership__preference-heading">', $output );
		$this->assertStringContainsString( 'Email preferences', $output );
	}

	/**
	 * Preference-only placements use an h2 and omit identity markup.
	 */
	public function test_group_membership_block_renders_standalone_preference_heading() {
		$member_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $member_id );

		$output = do_blocks(
			'<!-- wp:wporg/group-membership {"showIdentity":false,"showLeave":false,"showPreference":true,"showHeadings":true} /-->'
		);

		$this->assertStringContainsString(
			'<h2 class="wporg-group-membership__preference-heading wporg-group-membership__preference-heading--standalone">',
			$output
		);
		$this->assertStringNotContainsString( 'wporg-group-membership__heading', $output );
		$this->assertStringNotContainsString( 'wporg-group-membership__badge', $output );
		$this->assertStringNotContainsString( 'wporg-group-membership__count', $output );
	}

	/**
	 * Empty news blocks leave no heading or wrapper markup.
	 */
	public function test_group_news_block_is_hidden_without_posts() {
		$existing_posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $existing_posts as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		$this->assertSame( '', trim( do_blocks( '<!-- wp:wporg/group-news /-->' ) ) );
	}

	/**
	 * Published posts render their title and excerpt in the News section.
	 */
	public function test_group_news_block_renders_published_posts() {
		self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'A group update',
				'post_excerpt' => 'What the group has been working on.',
			)
		);

		$output = do_blocks( '<!-- wp:wporg/group-news /-->' );

		$this->assertStringContainsString( '<h2 class="wporg-group-news__heading">News</h2>', $output );
		$this->assertStringContainsString( 'A group update', $output );
		$this->assertStringContainsString( 'What the group has been working on.', $output );
	}
}
