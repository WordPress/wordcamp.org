<?php

namespace WordCamp\Groups\Tests;

use GatherPress\Core\Event\Event;
use GatherPress\Core\Rsvp\Rsvp;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/../../wporg-groups-frontend/tests/class-groups-testcase.php';

/**
 * The online-event line in the single-event details card.
 *
 * GatherPress renders the label in a `<span>` until the viewer is attending
 * an event that hasn't happened yet, and in an `<a>` to the meeting URL after
 * that. `single-event.html` supplies one string for both — "Online event",
 * wrapped in a tooltip saying the link is for attendees only — so the state
 * that does have something to click was labelled as a description, with a
 * tooltip that had stopped being true (#2057).
 *
 * `WordCamp\Groups\Site\name_the_online_event_link_action()` picks the words
 * to match the element. These tests pin all three states it distinguishes.
 *
 * @group groups
 */
class Test_Groups_Site_Online_Event_Link extends Groups_TestCase {

	const THEME_DIR = SUT_WP_CONTENT_DIR . 'themes/groups-site/';

	const MEETING_URL = 'https://meet.example.test/online-meetup';

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
	 * The screen this suite runs on, restored after each test.
	 *
	 * @var \WP_Screen|null
	 */
	private $previous_screen = null;

	/**
	 * Re-add the theme's filter for every test: `WP_UnitTestCase` restores its
	 * per-process hook snapshot after each one, so what the include above
	 * registered is gone from the second test on.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->previous_screen = $GLOBALS['current_screen'] ?? null;

		add_filter(
			'render_block_data',
			'WordCamp\\Groups\\Site\\name_the_online_event_link_action',
			10,
			3
		);
	}

	/**
	 * Put the screen back so a front-end screen doesn't leak into the rest of
	 * the suite.
	 */
	protected function tearDown(): void {
		if ( null === $this->previous_screen ) {
			unset( $GLOBALS['current_screen'] );
		} else {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the screen this test replaced.
			$GLOBALS['current_screen'] = $this->previous_screen;
		}

		parent::tearDown();
	}

	/**
	 * Create a published online event with a meeting link.
	 *
	 * @param bool $past Whether the event has already happened.
	 *
	 * @return int The event post ID.
	 */
	private function create_online_event( bool $past = false ): int {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
				'post_title'  => 'Online Meetup',
			)
		);

		$offset = $past ? '-30 days' : '+30 days';

		( new Event( $event_id ) )->save_datetimes(
			array(
				'post_id'        => $event_id,
				'datetime_start' => gmdate( 'Y-m-d H:i:s', strtotime( $offset ) ),
				'datetime_end'   => gmdate( 'Y-m-d H:i:s', strtotime( $offset . ' +2 hours' ) ),
				'timezone'       => 'UTC',
			)
		);

		update_post_meta( $event_id, 'gatherpress_online_event_link', self::MEETING_URL );

		// GatherPress hides the whole zone unless the event carries the
		// `online-event` sentinel term, which is what makes it online — the
		// meeting URL on its own isn't enough.
		$term = get_term_by( 'slug', 'online-event', '_gatherpress_venue' );

		$this->assertInstanceOf( \WP_Term::class, $term, 'GatherPress has not registered its online-event term.' );

		wp_set_object_terms( $event_id, array( (int) $term->term_id ), '_gatherpress_venue' );

		return $event_id;
	}

	/**
	 * Render the template's own online-event zone for an event, the way the
	 * single-event template does — reading the block out of the template
	 * rather than restating its markup, so a template edit can't leave these
	 * tests asserting against something the site no longer renders.
	 *
	 * @param int $event_id The event to render for.
	 *
	 * @return string The rendered zone.
	 */
	private function render_online_event_zone( int $event_id ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a template file from disk, as the other groups-site tests do.
		$markup = file_get_contents( self::THEME_DIR . 'templates/single-event.html' );

		$this->assertNotFalse( $markup, 'Could not read single-event.html.' );

		$zone = $this->find_block( parse_blocks( $markup ), 'gatherpress/online-event' );

		$this->assertNotNull( $zone, 'single-event.html no longer holds a gatherpress/online-event block.' );

		$this->go_to( get_permalink( $event_id ) );

		/*
		 * `Event::maybe_get_online_event_link()` withholds the meeting URL
		 * only when `is_admin()` is false: in the admin it hands the link to
		 * everybody. This bootstrap defines `WP_ADMIN`, and `go_to()` clears
		 * `$current_screen`, so without this every one of these tests would
		 * see the attending state and none of them would be testing anything.
		 * After `go_to()`, not before — it is `go_to()` that clears it.
		 */
		set_current_screen( 'front' );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Standing the event up as the post in the loop, the way the single-event template renders it; `wp_reset_postdata()` below puts it back.
		$GLOBALS['post'] = get_post( $event_id );
		setup_postdata( $GLOBALS['post'] );

		$output = do_blocks( serialize_block( $zone ) );

		wp_reset_postdata();

		return $output;
	}

	/**
	 * An attendee of an upcoming event gets the link, so the label offers the
	 * action rather than describing the format — and drops the tooltip, which
	 * only ever explained why there was nothing to click.
	 */
	public function test_attendee_of_an_upcoming_event_is_offered_the_action() {
		$event_id = $this->create_online_event();
		$user_id  = self::factory()->user->create();

		wp_set_current_user( $user_id );

		$response = ( new Rsvp( $event_id ) )->save( $user_id, 'attending' );

		$this->assertSame( 'attending', $response['status'] );

		$output = $this->render_online_event_zone( $event_id );

		$this->assertStringContainsString( 'Join event', $output );
		$this->assertStringContainsString( esc_url( self::MEETING_URL ), $output );
		$this->assertStringNotContainsString( 'Online event', $output );
		$this->assertStringNotContainsString(
			'gatherpress-tooltip',
			$output,
			'The attendees-only tooltip is still shown to an attendee who can already use the link.'
		);
	}

	/**
	 * Everyone else has nothing to click, so the line keeps describing the
	 * event's format and keeps the tooltip that says why.
	 */
	public function test_non_attendee_keeps_the_description_and_its_tooltip() {
		$event_id = $this->create_online_event();

		wp_set_current_user( self::factory()->user->create() );

		$output = $this->render_online_event_zone( $event_id );

		$this->assertStringContainsString( 'Online event', $output );
		$this->assertStringContainsString( 'gatherpress-tooltip', $output );
		$this->assertStringNotContainsString( 'Join event', $output );
		$this->assertStringNotContainsString(
			self::MEETING_URL,
			$output,
			'The meeting URL leaked to somebody who is not attending.'
		);
	}

	/**
	 * GatherPress withholds the link once the event is over, attendee or not.
	 * The label has to follow that, or it would offer to join a meeting that
	 * has finished.
	 */
	public function test_attendee_of_a_past_event_is_not_offered_the_action() {
		$event_id = $this->create_online_event( true );
		$user_id  = self::factory()->user->create();

		wp_set_current_user( $user_id );

		( new Rsvp( $event_id ) )->save( $user_id, 'attending' );

		$output = $this->render_online_event_zone( $event_id );

		$this->assertStringContainsString( 'Online event', $output );
		$this->assertStringNotContainsString( 'Join event', $output );
		$this->assertStringNotContainsString( self::MEETING_URL, $output );
	}

	/**
	 * Find the first block with a given name in a parsed block tree.
	 *
	 * @param array  $blocks Parsed blocks to walk.
	 * @param string $name   The block name to look for.
	 *
	 * @return array|null The block, or null when it isn't present.
	 */
	private function find_block( array $blocks, string $name ): ?array {
		foreach ( $blocks as $block ) {
			if ( $name === $block['blockName'] ) {
				return $block;
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$found = $this->find_block( $block['innerBlocks'], $name );

				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}
}
