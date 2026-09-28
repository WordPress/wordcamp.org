<?php

namespace WordCamp\Groups\Frontend\Tests;

use GatherPress\Core\Event\Event as GatherPress_Event;
use GatherPress\Core\Event\Query as GatherPress_Query;
use WP_REST_Request;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * The settings tab's control over which slice of the event list it receives.
 *
 * GatherPress scopes events queries to upcoming dates by attaching
 * `Event\Query::adjust_sorting_for_upcoming_events()` to `posts_clauses`, and
 * the core REST collection the Events tab reads inherits it. That left the tab
 * reporting "No past events." however many there were, and hiding every draft
 * whose date had passed (#1977).
 *
 * @group groups
 */
class Test_Groups_Event_List_Type extends \WordCamp\Groups\Tests\Groups_TestCase {

	/**
	 * Restores GatherPress's post types and rebuilds the REST route map.
	 *
	 * `mu-plugins/tests/test-groups-my-events.php` re-registers
	 * `gatherpress_event` as `array( 'public' => true )`, which drops
	 * `show_in_rest` for the rest of the run, and that suite runs before this
	 * one. In a full-suite run `/wp/v2/gatherpress_events` is then simply
	 * absent and these tests 404 instead of testing anything. Same fix as
	 * `test-post-titles.php`: re-run GatherPress's own registration, which
	 * restores exactly the production args, then rebuild the route map
	 * because the REST server caches it on first use.
	 */
	public function set_up() {
		parent::set_up();

		\GatherPress\Core\Venue\Setup::get_instance()->register_post_type();
		\GatherPress\Core\Event\Setup::get_instance()->register_post_type();

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Rebuilding the cached route map, as test-post-titles.php does.
		$GLOBALS['wp_rest_server'] = new \WP_REST_Server();

		do_action( 'rest_api_init', $GLOBALS['wp_rest_server'] );
	}

	/**
	 * Create a published event at a fixed offset from now.
	 *
	 * @param string $title  Event title.
	 * @param string $offset A `strtotime()` offset, e.g. '-30 days'.
	 * @param string $status Post status.
	 *
	 * @return int The event post ID.
	 */
	private function create_event( string $title, string $offset, string $status = 'publish' ): int {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => $status,
				'post_title'  => $title,
			)
		);

		( new GatherPress_Event( $event_id ) )->save_datetimes(
			array(
				'post_id'        => $event_id,
				'datetime_start' => gmdate( 'Y-m-d H:i:s', strtotime( $offset ) ),
				'datetime_end'   => gmdate( 'Y-m-d H:i:s', strtotime( $offset . ' +2 hours' ) ),
				'timezone'       => 'UTC',
			)
		);

		return $event_id;
	}

	/**
	 * Read the events collection the way the tab does.
	 *
	 * Leaves GatherPress's upcoming clause attached, which is the state the tab
	 * actually meets: GatherPress adds it for its own queries and does not take
	 * it off again.
	 *
	 * @param array $params Query parameters.
	 *
	 * @return int[] The returned event IDs.
	 */
	private function collection( array $params ): array {
		add_filter(
			'posts_clauses',
			array( GatherPress_Query::get_instance(), 'adjust_sorting_for_upcoming_events' ),
			10,
			2
		);

		$request  = new WP_REST_Request( 'GET', '/wp/v2/gatherpress_events' );
		$defaults = array(
			'per_page' => 100,
			'_fields'  => 'id',
		);

		$request->set_query_params( array_merge( $defaults, $params ) );

		$data = rest_do_request( $request )->get_data();

		return wp_list_pluck( (array) $data, 'id' );
	}

	/**
	 * Without asking, the collection still hides everything that has happened.
	 * This is the behaviour the tab was stuck with.
	 */
	public function test_collection_hides_past_events_by_default() {
		$past = $this->create_event( 'Past Meetup', '-30 days' );

		$this->assertNotContains( $past, $this->collection( array() ) );
	}

	/**
	 * Asking for the past slice returns what has happened, and only that.
	 */
	public function test_past_list_type_returns_past_events() {
		$past     = $this->create_event( 'Past Meetup', '-30 days' );
		$upcoming = $this->create_event( 'Upcoming Meetup', '+30 days' );

		$ids = $this->collection( array( 'wporg_groups_event_list' => 'past' ) );

		$this->assertContains( $past, $ids );
		$this->assertNotContains( $upcoming, $ids );
	}

	/**
	 * The upcoming slice is the complement of the past one.
	 */
	public function test_upcoming_list_type_returns_upcoming_events() {
		$past     = $this->create_event( 'Past Meetup', '-30 days' );
		$upcoming = $this->create_event( 'Upcoming Meetup', '+30 days' );

		$ids = $this->collection( array( 'wporg_groups_event_list' => 'upcoming' ) );

		$this->assertContains( $upcoming, $ids );
		$this->assertNotContains( $past, $ids );
	}

	/**
	 * A past-dated draft is a draft the organizer still has to finish, so the
	 * Drafts section has to show it whatever its date says.
	 */
	public function test_all_list_type_returns_a_past_dated_draft() {
		$draft = $this->create_event( 'Stale Draft', '-30 days', 'draft' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$ids = $this->collection(
			array(
				'status'                  => array( 'draft' ),
				'wporg_groups_event_list' => 'all',
			)
		);

		$this->assertContains( $draft, $ids );
	}

	/**
	 * An unrecognised value is ignored rather than passed through to WP_Query.
	 */
	public function test_unknown_list_type_is_ignored() {
		$past = $this->create_event( 'Past Meetup', '-30 days' );

		$this->assertNotContains( $past, $this->collection( array( 'wporg_groups_event_list' => 'whenever' ) ) );
	}
}
