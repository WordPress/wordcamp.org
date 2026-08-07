<?php

namespace WordCamp\Groups\Tests;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * @group groups
 *
 * Covers the `wporg/event-manage` block's server-side render (render.php),
 * specifically the "Message all members" / "Message attendees" buttons
 * added alongside the RSVP-segmented messaging feature (#1822).
 */
class Test_Event_Manage_Block extends Groups_TestCase {

	/**
	 * Creates a published `gatherpress_event` post owned by the given user
	 * and points the main query at its single view, matching the real
	 * front-end context the render.php callback runs in.
	 */
	private function go_to_event_owned_by( int $author_id ): int {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
				'post_author' => $author_id,
			)
		);

		// A plain `?p=` query bypasses CPT rewrite-rule resolution, which
		// isn't flushed for this fixture blog's freshly-registered
		// `gatherpress_event` post type — matches how WP core's own test
		// suite typically visits non-`post` singulars.
		$this->go_to( home_url( "?p={$event_id}&post_type=gatherpress_event" ) );

		return $event_id;
	}

	/**
	 * An Organiser (editor) viewing any event, including one they don't
	 * own, sees both messaging buttons — editors can edit any event via
	 * `edit_others_posts`.
	 */
	public function test_message_buttons_rendered_for_organiser() {
		$owner_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$event_id = $this->go_to_event_owned_by( $owner_id );

		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$output = do_blocks( '<!-- wp:wporg/event-manage /-->' );

		$this->assertStringContainsString( 'data-wporg-groups-modal="message-all"', $output );
		$this->assertStringContainsString( 'data-wporg-groups-modal="message-attendees"', $output );
		$this->assertStringContainsString( (string) $event_id, $output );
	}

	// phpcs:ignore Squiz.Commenting.FunctionComment.Missing
	public function test_optional_organiser_tools_heading_is_rendered() {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$output = do_blocks( '<!-- wp:wporg/event-manage {"mode":"create","showHeading":true} /-->' );

		$this->assertStringContainsString( '<h2 class="wporg-event-manage__heading">', $output );
		$this->assertStringContainsString( 'Organiser tools', $output );
	}

	/**
	 * An Event Organiser (author) viewing their own event sees both
	 * messaging buttons.
	 */
	public function test_message_buttons_rendered_for_event_organiser_on_own_event() {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->go_to_event_owned_by( $author_id );

		wp_set_current_user( $author_id );

		$output = do_blocks( '<!-- wp:wporg/event-manage /-->' );

		$this->assertStringContainsString( 'data-wporg-groups-modal="message-all"', $output );
		$this->assertStringContainsString( 'data-wporg-groups-modal="message-attendees"', $output );
	}

	/**
	 * IDOR check mirroring `test_author_cannot_edit_another_authors_event`
	 * in test-rest.php: an Event Organiser (author) viewing another
	 * author's event lacks `edit_post` on it, so `$show_edit` is false and
	 * neither messaging button — nor any other management UI — should
	 * render, even though this author otherwise passes
	 * `current_user_can_manage_events()`.
	 */
	public function test_message_buttons_absent_for_event_organiser_on_others_event() {
		$owner_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->go_to_event_owned_by( $owner_id );

		$other_author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $other_author_id );

		$output = do_blocks( '<!-- wp:wporg/event-manage {"showHeading":true} /-->' );

		$this->assertStringNotContainsString( 'data-wporg-groups-modal="message-all"', $output );
		$this->assertStringNotContainsString( 'data-wporg-groups-modal="message-attendees"', $output );
		$this->assertStringNotContainsString( 'Organiser tools', $output );
	}

	/**
	 * An ordinary Member (subscriber) — including one viewing the event
	 * they're RSVP'd to — never sees any event-management UI at all, per
	 * `current_user_can_manage_events()`'s early return.
	 */
	public function test_message_buttons_absent_for_member() {
		$owner_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->go_to_event_owned_by( $owner_id );

		$member_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $member_id );

		$output = do_blocks( '<!-- wp:wporg/event-manage /-->' );

		$this->assertSame( '', trim( (string) $output ) );
	}

	/**
	 * A logged-out visitor sees no management UI either.
	 */
	public function test_message_buttons_absent_for_anonymous_visitor() {
		$owner_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->go_to_event_owned_by( $owner_id );

		wp_set_current_user( 0 );

		$output = do_blocks( '<!-- wp:wporg/event-manage /-->' );

		$this->assertSame( '', trim( (string) $output ) );
	}
}
