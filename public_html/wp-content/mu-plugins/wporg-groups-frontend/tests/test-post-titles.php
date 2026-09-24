<?php

namespace WordCamp\Groups\Tests;

use WP_REST_Request;

use const WordCamp\Groups\Frontend\Post_Titles\PLAIN_TEXT_TITLE_POST_TYPES;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * Covers `inc/post-titles.php`.
 *
 * These go through `rest_do_request()` against *core's* posts controller
 * rather than this plugin's REST layer, because that is the route the filter
 * exists for: the modal's venue overlay and the block editor both write
 * venues that way, and none of them reach the `wcorg_sanitize_plain_text()`
 * calls in `inc/rest.php`.
 *
 * @group groups
 */
class Test_Groups_Post_Titles extends Groups_TestCase {

	/**
	 * Restores GatherPress's post types and rebuilds the REST route map.
	 *
	 * `mu-plugins/tests/test-groups-my-events.php` re-registers
	 * `gatherpress_event` as `array( 'public' => true )`, which drops
	 * `show_in_rest` for the rest of the run -- and that suite runs before
	 * this one, so in a full-suite run `/wp/v2/gatherpress_events` is simply
	 * absent and these tests would 404 rather than test anything. Re-running
	 * GatherPress's own registration restores exactly the production args,
	 * and the route map has to be rebuilt afterwards because the REST server
	 * caches it on first use.
	 */
	public function set_up() {
		parent::set_up();

		\GatherPress\Core\Venue\Setup::get_instance()->register_post_type();
		\GatherPress\Core\Event\Setup::get_instance()->register_post_type();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * The literals in `PLAIN_TEXT_TITLE_POST_TYPES` still name GatherPress's
	 * post types.
	 *
	 * The constant can't reference the classes directly -- see the note on it
	 * -- so this is what catches an upstream rename.
	 */
	public function test_post_type_names_match_gatherpress(): void {
		$this->assertSame(
			array( \GatherPress\Core\Venue\Venue::POST_TYPE, \GatherPress\Core\Event\Event::POST_TYPE ),
			PLAIN_TEXT_TITLE_POST_TYPES
		);
	}

	/**
	 * Creates a user who does not hold `unfiltered_html`.
	 *
	 * That capability is what decides whether core runs `wp_filter_kses()`
	 * over the title at all, so a test using a super admin would pass without
	 * exercising anything. On multisite only super admins hold it, so an
	 * editor is the realistic organizer-shaped case.
	 */
	private function set_current_user_without_unfiltered_html(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$this->assertFalse(
			current_user_can( 'unfiltered_html' ),
			'Fixture precondition: the test user must not hold unfiltered_html.'
		);

		return $user_id;
	}

	/**
	 * POSTs to core's route for the given post type and returns the new post id.
	 */
	private function create_via_core_rest( string $post_type, string $title ): int {
		$rest_base = get_post_type_object( $post_type )->rest_base;

		$request = new WP_REST_Request( 'POST', "/wp/v2/{$rest_base}" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array(
			'title' => $title, 'status' => 'publish',
		) ) );

		$response = rest_do_request( $request );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );

		return (int) $response->get_data()['id'];
	}

	/**
	 * A venue named through core's route stores text, not a live anchor.
	 *
	 * This is the hole the filter closes. `wp_filter_kses()` keeps `<a href>`
	 * -- it is in `$allowedtags` -- and `core/post-title` renders the stored
	 * title into element content without `wp_kses_post()`, so before this
	 * filter the venue name rendered as a working off-site link.
	 */
	public function test_venue_title_via_core_rest_is_stored_as_text(): void {
		$this->set_current_user_without_unfiltered_html();

		$venue_id = $this->create_via_core_rest(
			'gatherpress_venue',
			'Community Hall< a href="https://example.org" >click here< /a >'
		);

		$stored = (string) get_post_field( 'post_title', $venue_id, 'raw' );

		$this->assertStringNotContainsString( '<a', $stored );
		$this->assertStringNotContainsString( '<', $stored );
		$this->assertStringContainsString( '&lt; a href=', $stored );
	}

	/**
	 * Events are reachable through core's route too, and get the same treatment.
	 */
	public function test_event_title_via_core_rest_is_stored_as_text(): void {
		$this->set_current_user_without_unfiltered_html();

		$event_id = $this->create_via_core_rest(
			'gatherpress_event',
			'Meetup< a href="https://example.org" >click here< /a >'
		);

		$stored = (string) get_post_field( 'post_title', $event_id, 'raw' );

		$this->assertStringNotContainsString( '<a', $stored );
		$this->assertStringNotContainsString( '<', $stored );
	}

	/**
	 * Angle brackets used as prose survive core's route.
	 *
	 * Without the filter this is the destructive case rather than the unsafe
	 * one: kses drops the whole `<...>` span, so `Hall < 100 > seats` reaches
	 * the database as `Hall  seats` and the organizer's text is gone. The
	 * filter has to run before kses for this to hold, which is why it hangs
	 * off `rest_pre_insert_{$post_type}` and not a later hook.
	 */
	public function test_prose_angle_brackets_survive_core_rest(): void {
		$this->set_current_user_without_unfiltered_html();

		$venue_id = $this->create_via_core_rest( 'gatherpress_venue', 'Hall < 100 > seats' );

		$stored = (string) get_post_field( 'post_title', $venue_id, 'raw' );

		$this->assertSame( 'Hall < 100 > seats', html_entity_decode( $stored ) );
	}

	/**
	 * An update that doesn't send a title leaves the stored one alone.
	 *
	 * The REST posts controller only sets `post_title` on the prepared post
	 * when the request carries one, so the filter has to tolerate it being
	 * unset rather than writing an empty string over the existing title.
	 */
	public function test_update_without_a_title_does_not_blank_it(): void {
		$this->set_current_user_without_unfiltered_html();

		$venue_id = $this->create_via_core_rest( 'gatherpress_venue', 'Hall < 100 > seats' );
		$before   = (string) get_post_field( 'post_title', $venue_id, 'raw' );

		$request = new WP_REST_Request( 'POST', "/wp/v2/gatherpress_venues/{$venue_id}" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'content' => 'Just a description change.' ) ) );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( $before, (string) get_post_field( 'post_title', $venue_id, 'raw' ) );
	}

	/**
	 * Re-saving an already-encoded title doesn't encode it a second time.
	 *
	 * Events go through both this filter (core's route) and the
	 * `wcorg_sanitize_plain_text()` call in `persist_event()`, so the helper
	 * being a no-op on its own output is what keeps the two from compounding.
	 */
	public function test_resaving_an_encoded_title_is_idempotent(): void {
		$this->set_current_user_without_unfiltered_html();

		$venue_id = $this->create_via_core_rest( 'gatherpress_venue', 'Hall < 100 > seats' );
		$before   = (string) get_post_field( 'post_title', $venue_id, 'raw' );

		// Round-trip the stored bytes back through the same route, the way the
		// block editor does when an organizer saves without touching the title.
		$request = new WP_REST_Request( 'POST', "/wp/v2/gatherpress_venues/{$venue_id}" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'title' => $before ) ) );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( $before, (string) get_post_field( 'post_title', $venue_id, 'raw' ) );
	}

	/**
	 * The filter is scoped, not global: other post types keep core's behaviour.
	 *
	 * A global title filter would be a much larger change to the site than
	 * this PR intends, so pin that a plain `post` is untouched.
	 */
	public function test_other_post_types_are_untouched(): void {
		$this->set_current_user_without_unfiltered_html();

		$this->assertNotContains( 'post', PLAIN_TEXT_TITLE_POST_TYPES );

		$post_id = $this->create_via_core_rest( 'post', 'Hall < 100 > seats' );

		// Core's own behaviour, unchanged: kses drops the whole span.
		$this->assertSame( 'Hall  seats', (string) get_post_field( 'post_title', $post_id, 'raw' ) );
	}
}
