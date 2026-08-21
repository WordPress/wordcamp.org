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
		$user_id  = self::factory()->user->create(
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
	 * The RSVP action should precede the attendee summary in the rendered block.
	 */
	public function test_event_rsvp_action_precedes_attendee_summary() {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
				'post_title'  => 'RSVP Action Order Event',
			)
		);

		$this->go_to( home_url( "?p={$event_id}&post_type=gatherpress_event" ) );
		$output = do_blocks( '<!-- wp:wporg/event-rsvp /-->' );

		$action_position  = strpos( $output, 'class="wp-block-button__link wp-element-button' );
		$summary_position = strpos( $output, 'class="wporg-event-rsvp__summary' );

		$this->assertNotFalse( $action_position );
		$this->assertNotFalse( $summary_position );
		$this->assertLessThan( $summary_position, $action_position );
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
	 * A country code that no longer resolves is dropped from the label.
	 */
	public function test_group_location_block_omits_unrecognized_country() {
		update_site_meta( get_current_blog_id(), 'wporg_group_location_type', 'physical' );
		update_site_meta( get_current_blog_id(), 'wporg_group_location_city', 'İstanbul' );
		update_site_meta( get_current_blog_id(), 'wporg_group_location_country', 'ZZ' );

		$output = do_blocks( '<!-- wp:wporg/group-location /-->' );

		$this->assertStringContainsString( 'İstanbul', $output );
		$this->assertStringNotContainsString( 'İstanbul,', $output );
		$this->assertStringNotContainsString( 'ZZ', $output );
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
	 * A city with a country code that no longer resolves to a name (e.g.
	 * CLDR data changed since it was saved) still renders — with just the
	 * city — rather than disappearing or printing a dangling "City, ".
	 */
	public function test_group_location_block_renders_city_when_country_code_is_stale() {
		update_site_meta( get_current_blog_id(), 'wporg_group_location_type', 'physical' );
		update_site_meta( get_current_blog_id(), 'wporg_group_location_city', 'Warsaw' );
		update_site_meta( get_current_blog_id(), 'wporg_group_location_country', 'ZZ' );

		$output = do_blocks( '<!-- wp:wporg/group-location /-->' );

		$this->assertStringContainsString( 'Warsaw', $output );
		$this->assertStringNotContainsString( 'Warsaw,', $output );
	}

	/**
	 * The default combined variant preserves the original unheaded output.
	 * A plain member gets no role badge; see
	 * `test_group_membership_block_badges_event_manager_roles()`.
	 */
	public function test_group_membership_block_defaults_to_combined_variant() {
		$member_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $member_id );

		$output = do_blocks( '<!-- wp:wporg/group-membership /-->' );

		$this->assertStringNotContainsString( 'wporg-group-membership__badge', $output );
		$this->assertStringContainsString( 'wporg-group-membership__count', $output );
		$this->assertStringContainsString( 'wporg-group-membership__leave', $output );
		$this->assertStringContainsString( 'class="wporg-group-membership__preference"', $output );
		$this->assertStringNotContainsString( 'wporg-group-membership__heading', $output );
		$this->assertStringNotContainsString( 'wporg-group-membership__preference-heading', $output );
	}

	/**
	 * Membership placements include their heading, and omit both the
	 * preference and the member count — the count belongs to the hero's
	 * standalone `count` variant.
	 */
	public function test_group_membership_block_renders_membership_variant() {
		$member_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $member_id );

		$output = do_blocks(
			'<!-- wp:wporg/group-membership {"variant":"membership"} /-->'
		);

		$this->assertStringContainsString( '<h2 class="wporg-group-membership__heading">', $output );
		$this->assertStringContainsString( 'Membership', $output );
		$this->assertStringContainsString( 'wporg-group-membership__leave', $output );
		$this->assertStringNotContainsString( 'wporg-group-membership__count', $output );
		$this->assertStringNotContainsString( 'wporg-group-membership__preference', $output );
	}

	/**
	 * Event-manager roles keep the role badge a plain member doesn't get.
	 */
	public function test_group_membership_block_badges_event_manager_roles() {
		$organizer_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $organizer_id );

		$output = do_blocks(
			'<!-- wp:wporg/group-membership {"variant":"membership"} /-->'
		);

		$this->assertStringContainsString( 'wporg-group-membership__badge', $output );
		$this->assertStringNotContainsString( 'wporg-group-membership__count', $output );
	}

	/**
	 * Force `count_users()` to a known result and record how often it runs.
	 *
	 * Returns the call counter; the filter is removed in `tear_down()` via
	 * `remove_all_filters()` on the WordPress test case.
	 *
	 * @param int $total_users The member count to report.
	 *
	 * @return object A `{ calls: int }` counter, updated as the filter fires.
	 */
	private function stub_count_users( int $total_users ): object {
		$counter = new \stdClass();

		$counter->calls = 0;

		add_filter(
			'pre_count_users',
			static function () use ( &$counter, $total_users ) {
				++$counter->calls;

				return array(
					'total_users' => $total_users,
					'avail_roles' => array( 'subscriber' => $total_users ),
				);
			}
		);

		return $counter;
	}

	/**
	 * The hero's standalone count prints a locale-formatted total that links
	 * to the member directory.
	 */
	public function test_group_membership_count_variant_renders_count_and_members_link() {
		$this->stub_count_users( 1234 );

		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Members',
				'post_name'   => 'members',
			)
		);

		$output = do_blocks( '<!-- wp:wporg/group-membership {"variant":"count"} /-->' );

		$this->assertStringContainsString( 'wporg-group-membership__count', $output );
		$this->assertStringContainsString( '1,234 members', $output );
		$this->assertStringContainsString(
			'href="' . esc_url( get_permalink( get_page_by_path( 'members' ) ) ) . '"',
			$output
		);
		$this->assertStringNotContainsString( 'wporg-group-membership__leave', $output );
		$this->assertStringNotContainsString( 'Join this group', $output );
	}

	/**
	 * The count's own text comes from the interactivity store, not from the
	 * PHP fallback: the server-side directive processor blanks a
	 * `data-wp-text` node whose `state.*` reference isn't registered, so the
	 * registered `countLabel` is what actually reaches the page.
	 */
	public function test_group_membership_count_variant_registers_server_rendered_count_label() {
		$this->stub_count_users( 42 );

		$output = do_blocks( '<!-- wp:wporg/group-membership {"variant":"count"} /-->' );

		$state = wp_interactivity_state( 'wporg/group-membership' );

		$this->assertSame( '42 members', $state['countLabel'] );
		$this->assertSame( 42, $state['memberCount'] );
		$this->assertMatchesRegularExpression(
			'/data-wp-text="state\.countLabel"[^>]*>\s*42 members\s*</',
			$output
		);
	}

	/**
	 * The front page renders a `count` block in the hero and a `membership`
	 * block in the sidebar, and only the first needs the total.
	 * `count_users()` runs Core's usermeta aggregate, so a second block
	 * asking for it is a second aggregate on every request.
	 */
	public function test_group_membership_counts_members_once_per_front_page_render() {
		$counter = $this->stub_count_users( 7 );

		$member_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $member_id );

		do_blocks(
			'<!-- wp:wporg/group-membership {"variant":"count"} /-->'
			. '<!-- wp:wporg/group-membership {"variant":"membership"} /-->'
		);

		$this->assertSame( 1, $counter->calls );
	}

	/**
	 * A logged-out visitor still gets a labelled Join button. The label is
	 * server-rendered through `state.buttonLabel`, which the membership
	 * variant has to keep registering even though it no longer counts
	 * members — an unregistered reference renders the button empty.
	 */
	public function test_group_membership_membership_variant_renders_labelled_join_button() {
		wp_set_current_user( 0 );

		$output = do_blocks(
			'<!-- wp:wporg/group-membership {"variant":"membership"} /-->'
		);

		$this->assertMatchesRegularExpression(
			'/data-wp-text="state\.buttonLabel"[^>]*>\s*Join this group\s*</',
			$output
		);
	}

	/**
	 * Combined placements keep both halves: the count and the membership
	 * controls around it.
	 */
	public function test_group_membership_combined_variant_renders_count_and_controls() {
		$this->stub_count_users( 9 );

		$member_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $member_id );

		$output = do_blocks( '<!-- wp:wporg/group-membership {"variant":"combined"} /-->' );

		$this->assertStringContainsString( 'wporg-group-membership__count', $output );
		$this->assertStringContainsString( '9 members', $output );
		$this->assertStringContainsString( 'wporg-group-membership__leave', $output );
		$this->assertStringContainsString( 'wporg-group-membership__meta-divider', $output );
	}

	/**
	 * Preference placements include their heading and omit membership controls.
	 */
	public function test_group_membership_block_renders_preference_variant() {
		$member_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $member_id );

		$output = do_blocks(
			'<!-- wp:wporg/group-membership {"variant":"preference"} /-->'
		);

		$this->assertStringContainsString( '<h2 class="wporg-group-membership__preference-heading">', $output );
		$this->assertStringContainsString( 'Email preferences', $output );
		$this->assertStringContainsString( 'class="wporg-group-membership__preference"', $output );
		$this->assertStringNotContainsString( 'wporg-group-membership__heading', $output );
		$this->assertStringNotContainsString( 'wporg-group-membership__badge', $output );
		$this->assertStringNotContainsString( 'wporg-group-membership__count', $output );
		$this->assertStringNotContainsString( 'wporg-group-membership__leave', $output );
	}

	/**
	 * A member with nothing coming up gets no "My upcoming events" section at
	 * all — the block leaves the space to the page rather than printing a
	 * permanent empty state.
	 */
	public function test_my_events_block_is_hidden_without_upcoming_events() {
		$member_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $member_id );

		$this->assertSame( '', trim( do_blocks( '<!-- wp:wporg/my-events /-->' ) ) );
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

		$this->assertStringContainsString( '<h2 class="wporg-section-heading wporg-group-news__heading">News</h2>', $output );
		$this->assertStringContainsString( 'A group update', $output );
		$this->assertStringContainsString( 'What the group has been working on.', $output );
	}
}
