<?php

namespace WordCamp\Groups\Tests;

use WP_REST_Request;
use WordPressdotorg\GatherPress_Recurring_Events\Context;
use WordPressdotorg\GatherPress_Recurring_Events\Database as Recurring_Events_Database;
use WordPressdotorg\GatherPress_Recurring_Events\Occurrences;
use WordPressdotorg\GatherPress_Recurring_Events\Rule;

use function WordCamp\Groups\Frontend\REST\create_event;
use function WordCamp\Groups\Frontend\REST\event_args_schema;
use function WordCamp\Groups\Frontend\REST\get_event_form_data;
use function WordCamp\Groups\Frontend\REST\update_event;
use function WordCamp\Groups\Frontend\REST\save_draft;
use function WordCamp\Groups\Frontend\REST\publish_draft;
use function WordCamp\Groups\Frontend\REST\list_drafts;
use function WordCamp\Groups\Frontend\REST\publish_existing_event_permissions_check;
use function WordCamp\Groups\Frontend\REST\current_user_can_use_attachment;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * @group groups
 */
class Test_Groups_REST extends Groups_TestCase {

	/**
	 * Builds a POST /event request with the given params.
	 */
	private function event_request( array $params ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/wporg-groups/v1/event' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/**
	 * A minimal valid set of event params, for tests to override from.
	 */
	private function base_event_params(): array {
		return array(
			'title'      => 'Test Event',
			'date'       => current_datetime()->modify( '+1 week' )->format( 'Y-m-d' ),
			'time_start' => '18:00',
			'time_end'   => '20:00',
		);
	}

	/**
	 * Dispatches a JSON body through the full REST pipeline, the same way
	 * the frontend's `apiFetch()` does.
	 *
	 * Unlike `event_request()` (which builds a `WP_REST_Request` via
	 * `set_param()`), this goes through `rest_do_request()` /
	 * `WP_REST_Server::dispatch()`, which is what actually runs
	 * `sanitize_params()` / schema validation against the registered route
	 * args. A request built with `set_param()` alone skips that step
	 * entirely, so it can't catch a schema-validation bug like the one
	 * these tests exist to cover.
	 */
	private function dispatch_json_request( string $method, string $route, array $body ) {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );

		return rest_do_request( $request );
	}

	/**
	 * An editor (Organizer) can create and publish an event in one request.
	 */
	public function test_create_event_as_editor() {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$response = create_event( $this->event_request( $this->base_event_params() ) );

		$this->assertNotWPError( $response );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'id', $data );
		$this->assertSame( 'gatherpress_event', get_post_type( $data['id'] ) );
		$this->assertSame( 'publish', get_post_status( $data['id'] ) );
	}

	/**
	 * The frontend form exposes recurrence controls when the extension is active.
	 */
	public function test_event_form_exposes_recurrence_fields(): void {
		$response   = get_event_form_data( new WP_REST_Request( 'GET', '/wporg-groups/v1/event-form-data' ) );
		$recurrence = $response->get_data()['fields']['recurrence'];

		$this->assertTrue( $recurrence['available'] );
		$this->assertFalse( $recurrence['locked'] );
		$this->assertSame( '', $recurrence['frequency'] );
		$this->assertSame( 12, $recurrence['count'] );
		$this->assertTrue( rest_validate_value_from_schema( $recurrence, event_args_schema()['recurrence'], 'recurrence' ) );
	}

	/**
	 * Creating a recurring event through the frontend projects its occurrences.
	 */
	public function test_create_recurring_event_from_frontend(): void {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$date    = gmdate( 'Y-m-d', strtotime( '+14 days' ) );
		$weekday = strtoupper( substr( gmdate( 'D', strtotime( $date ) ), 0, 2 ) );
		$params  = array_merge(
			$this->base_event_params(),
			array(
				'date'       => $date,
				'recurrence' => array(
					'frequency' => 'weekly',
					'interval'  => 1,
					'weekdays'  => array( $weekday ),
					'end_type'  => 'count',
					'count'     => 3,
				),
			)
		);

		Recurring_Events_Database::maybe_install();
		$response = create_event( $this->event_request( $params ) );

		$this->assertNotWPError( $response );
		$event_id = $response->get_data()['id'];
		$this->assertSame( 'weekly', get_post_meta( $event_id, Rule::META_PREFIX . 'frequency', true ) );
		$this->assertSame( 3, (int) get_post_meta( $event_id, Rule::META_PREFIX . 'count', true ) );
		$this->assertCount( 3, Occurrences::all( $event_id ) );
	}

	/**
	 * Occurrence navigation retains earlier upcoming dates.
	 */
	public function test_occurrence_navigation_retains_earlier_upcoming_dates(): void {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$date    = gmdate( 'Y-m-d', strtotime( '+14 days' ) );
		$weekday = strtoupper( substr( gmdate( 'D', strtotime( $date ) ), 0, 2 ) );
		$params  = array_merge(
			$this->base_event_params(),
			array(
				'date'       => $date,
				'recurrence' => array(
					'frequency' => 'weekly',
					'interval'  => 1,
					'weekdays'  => array( $weekday ),
					'end_type'  => 'count',
					'count'     => 8,
				),
			)
		);

		Recurring_Events_Database::maybe_install();
		$response    = create_event( $this->event_request( $params ) );
		$event_id    = $response->get_data()['id'];
		$occurrences = Occurrences::all( $event_id );
		$compact     = Occurrences::around( $event_id, 6 );

		$this->assertSame(
			array_slice( wp_list_pluck( $occurrences, 'recurrence_id' ), 0, 6 ),
			wp_list_pluck( $compact, 'recurrence_id' )
		);

		Context::set( $occurrences[6] );
		$selector = Context::selector( $event_id );

		$this->assertStringContainsString( $occurrences[6]->recurrence_id, $selector );
		$this->assertStringNotContainsString( 'gpre-view-all', $selector );
		$this->assertStringNotContainsString( 'View all dates', $selector );

		Context::set( null );
	}

	/**
	 * The occurrence nonce endpoint restores cookie authentication.
	 */
	public function test_occurrence_nonce_authenticates_current_user(): void {
		$user_id      = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$authenticate = static fn() => $user_id;

		wp_set_current_user( 0 );
		add_filter( 'determine_current_user', $authenticate, 99 );
		$response = rest_do_request( '/gpre/v1/event/20260817T100000/nonce' );
		remove_filter( 'determine_current_user', $authenticate, 99 );

		wp_set_current_user( $user_id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotFalse( wp_verify_nonce( $response->get_data()['nonce'], 'wp_rest' ) );
	}

	/**
	 * Draft autosaves retain recurrence data without locking the schedule.
	 */
	public function test_recurring_event_draft_round_trip(): void {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$request = new WP_REST_Request( 'POST', '/wporg-groups/v1/draft' );
		$request->set_param( 'title', 'Recurring draft' );
		$request->set_param( 'date', gmdate( 'Y-m-d', strtotime( '+14 days' ) ) );
		$request->set_param( 'time_start', '18:00' );
		$request->set_param( 'time_end', '20:00' );
		$request->set_param(
			'recurrence',
			array(
				'frequency'  => 'monthly',
				'interval'   => 1,
				'end_type'   => 'count',
				'count'      => 4,
			)
		);

		$draft_id = save_draft( $request )->get_data()['id'];
		$load     = new WP_REST_Request( 'GET', '/wporg-groups/v1/event-form-data' );
		$load->set_param( 'event_id', $draft_id );
		$fields = get_event_form_data( $load )->get_data()['fields'];

		$this->assertSame( 'monthly', $fields['recurrence']['frequency'] );
		$this->assertSame( 4, $fields['recurrence']['count'] );
		$this->assertFalse( $fields['recurrence']['locked'] );
	}

	/**
	 * A new event cannot be created with a past date.
	 */
	public function test_create_event_rejects_past_date() {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$params         = $this->base_event_params();
		$params['date'] = current_datetime()->modify( '-1 day' )->format( 'Y-m-d' );

		$response = create_event( $this->event_request( $params ) );

		$this->assertWPError( $response );
		$this->assertSame( 'wporg_groups_past_event_date', $response->get_error_code() );
	}

	/**
	 * An event whose end time equals its start time is rejected.
	 */
	public function test_zero_length_event_rejected() {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$params               = $this->base_event_params();
		$params['time_start'] = '18:00';
		$params['time_end']   = '18:00';

		$response = create_event( $this->event_request( $params ) );

		$this->assertWPError( $response );
		$this->assertSame( 'wporg_groups_bad_time_range', $response->get_error_code() );
	}

	/**
	 * 22:00 to 01:00 crosses midnight — the end date should roll to the
	 * next calendar day rather than being treated as a same-day, negative
	 * (and thus rejected) time range.
	 */
	public function test_overnight_event_rolls_end_date_to_next_day() {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$event_date           = current_datetime()->modify( '+2 weeks' );
		$params               = $this->base_event_params();
		$params['date']       = $event_date->format( 'Y-m-d' );
		$params['time_start'] = '22:00';
		$params['time_end']   = '01:00';

		$response = create_event( $this->event_request( $params ) );
		$this->assertNotWPError( $response );

		$event_id = $response->get_data()['id'];
		$end      = get_post_meta( $event_id, 'gatherpress_datetime_end', true );

		$this->assertSame( $event_date->modify( '+1 day' )->format( 'Y-m-d' ) . ' 01:00:00', $end );
	}

	/**
	 * IDOR check: an author cannot edit another author's event.
	 */
	public function test_author_cannot_edit_another_authors_event() {
		$author_a = self::factory()->user->create( array( 'role' => 'author' ) );
		$author_b = self::factory()->user->create( array( 'role' => 'author' ) );

		wp_set_current_user( $author_a );
		$create_response = create_event( $this->event_request( $this->base_event_params() ) );
		$event_id        = $create_response->get_data()['id'];

		wp_set_current_user( $author_b );
		$request = $this->event_request( array( 'title' => 'HIJACKED' ) + $this->base_event_params() );
		$request->set_param( 'id', $event_id );

		$permission = publish_existing_event_permissions_check( $request );

		$this->assertFalse( $permission );
		$this->assertSame( 'Test Event', get_the_title( $event_id ), 'The original title must be untouched.' );
	}

	/**
	 * An author can edit an event they created.
	 */
	public function test_author_can_edit_own_event() {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author_id );

		$create_response = create_event( $this->event_request( $this->base_event_params() ) );
		$event_id        = $create_response->get_data()['id'];

		$request = $this->event_request( array( 'title' => 'Updated Title' ) + $this->base_event_params() );
		$request->set_param( 'id', $event_id );

		$this->assertTrue( publish_existing_event_permissions_check( $request ) );

		$response = update_event( $request );
		$this->assertNotWPError( $response );
		$this->assertSame( 'Updated Title', get_the_title( $event_id ) );
	}

	/**
	 * A published event with a past date remains editable.
	 */
	public function test_published_past_event_remains_editable() {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author_id );

		$create_response = create_event( $this->event_request( $this->base_event_params() ) );
		$this->assertNotWPError( $create_response );

		$event_id       = $create_response->get_data()['id'];
		$params         = array( 'title' => 'Updated Past Event' ) + $this->base_event_params();
		$params['date'] = current_datetime()->modify( '-1 day' )->format( 'Y-m-d' );
		$request        = $this->event_request( $params );
		$request->set_param( 'id', $event_id );

		$response = update_event( $request );

		$this->assertNotWPError( $response );
		$this->assertSame( 'Updated Past Event', get_the_title( $event_id ) );
	}

	/**
	 * Regression test for a production incident: editing an existing,
	 * non-recurring, published event failed with
	 * "Invalid parameter(s): recurrence".
	 *
	 * The frontend's save payload always included a `recurrence` key, even
	 * when its local state was still `null` (e.g. before the initial
	 * `GET /event-form-data` fetch had populated it). `apiFetch()` then
	 * serialized that as literal JSON `null`, but `recurrence`'s REST arg
	 * is typed strictly as `object` (`recurring_event_args_schema()`), and
	 * `object` schemas don't accept `null` — so the whole request was
	 * rejected before `update_event()` ever ran, even though every other
	 * field was valid and recurrence wasn't being changed at all. Fixed by
	 * having the frontend omit `recurrence` entirely on edit (recurrence
	 * is `required => false`, and is locked/uneditable after publish
	 * anyway) instead of ever sending it as `null`.
	 *
	 * None of the other `create_event()`/`update_event()` tests in this
	 * file would have caught this: they build requests with
	 * `WP_REST_Request::set_param()`, which skips `sanitize_params()` /
	 * schema validation entirely. This test dispatches through
	 * `rest_do_request()` instead, exercising the same validation path a
	 * real save from the browser hits.
	 */
	public function test_update_event_omitting_recurrence_succeeds(): void {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$create = create_event( $this->event_request( $this->base_event_params() ) );
		$this->assertNotWPError( $create );
		$event_id = $create->get_data()['id'];

		$response = $this->dispatch_json_request(
			'POST',
			"/wporg-groups/v1/event/{$event_id}",
			array( 'title' => 'Edited Without Recurrence' ) + $this->base_event_params()
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Edited Without Recurrence', get_the_title( $event_id ) );
	}

	/**
	 * Pins the exact failure mode from the incident above: a literal JSON
	 * `null` for `recurrence` is rejected by schema validation, for the
	 * whole request, with `rest_invalid_param` / `recurrence` — never
	 * `wporg_groups_invalid_recurrence` (the app-level validator in
	 * `mu-plugins/groups/gatherpress-recurring-events.php`, which never
	 * even runs here). Frontend code must omit the key rather than send
	 * `null`.
	 */
	public function test_update_event_rejects_null_recurrence(): void {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$create = create_event( $this->event_request( $this->base_event_params() ) );
		$this->assertNotWPError( $create );
		$event_id = $create->get_data()['id'];

		$response = $this->dispatch_json_request(
			'POST',
			"/wporg-groups/v1/event/{$event_id}",
			array( 'recurrence' => null ) + $this->base_event_params()
		);

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'rest_invalid_param', $data['code'] );
		$this->assertArrayHasKey( 'recurrence', $data['data']['params'] );
	}

	/**
	 * Creating an event is the other half of this "crucial functionality":
	 * confirms the full REST pipeline (schema validation included) accepts
	 * a real create payload with `recurrence` present, the way the
	 * frontend's create flow sends it.
	 */
	public function test_create_event_via_rest_dispatch(): void {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$response = $this->dispatch_json_request(
			'POST',
			'/wporg-groups/v1/event',
			array(
				'recurrence' => array(
					'available' => true,
					'locked'    => false,
					'frequency' => '',
				),
			) + $this->base_event_params()
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$event_id = $response->get_data()['id'];
		$this->assertSame( 'gatherpress_event', get_post_type( $event_id ) );
		$this->assertSame( 'publish', get_post_status( $event_id ) );
	}

	/**
	 * A new venue's address is written to gatherpress_address meta, not
	 * post_content, so GatherPress's own geocode handler can pick it up.
	 */
	public function test_venue_address_written_to_meta_not_post_content() {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$params                      = $this->base_event_params();
		$params['new_venue_name']    = 'Test Venue ' . uniqid();
		$params['new_venue_address'] = '123 Example St, Testville';

		$response = create_event( $this->event_request( $params ) );
		$this->assertNotWPError( $response );

		$event_id = $response->get_data()['id'];

		$venue_posts = get_posts(
			array(
				'post_type'      => 'gatherpress_venue',
				'title'          => $params['new_venue_name'],
				'posts_per_page' => 1,
			)
		);
		$this->assertNotEmpty( $venue_posts, 'A venue post should have been created.' );

		$venue_post_id = $venue_posts[0]->ID;
		$this->assertSame( $params['new_venue_address'], get_post_meta( $venue_post_id, 'gatherpress_address', true ) );
		$this->assertStringNotContainsString( $params['new_venue_address'], (string) get_post_field( 'post_content', $venue_post_id ) );

		$venue = new \GatherPress\Core\Venue\Venue( $venue_post_id );
		$term  = $venue->get_term();
		$this->assertNotNull( $term, 'assign_venue_to_event() should resolve a shadow taxonomy term for the venue.' );
		$this->assertTrue( has_term( $term->term_id, $venue->get_taxonomy(), $event_id ) );
	}

	/**
	 * A user can't set another user's private attachment as a featured image.
	 */
	public function test_featured_image_rejects_unreadable_attachment() {
		$owner_id     = self::factory()->user->create( array( 'role' => 'author' ) );
		$other_author = self::factory()->user->create( array( 'role' => 'author' ) );

		wp_set_current_user( $owner_id );
		$private_attachment_id = self::factory()->attachment->create_object(
			array(
				'file'        => 'private-image.jpg',
				'post_status' => 'private',
			)
		);

		wp_set_current_user( $other_author );
		$this->assertFalse( current_user_can_use_attachment( $private_attachment_id ) );
	}

	/**
	 * Save → list → update → publish should transition post_status correctly.
	 */
	public function test_draft_save_list_update_publish_flow() {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );
		$event_date = current_datetime()->modify( '+1 month' )->format( 'Y-m-d' );

		// Save a partial draft (title only).
		$save_request = new WP_REST_Request( 'POST', '/wporg-groups/v1/draft' );
		$save_request->set_param( 'title', 'Draft Test' );
		$save_response = save_draft( $save_request );
		$draft_id      = $save_response->get_data()['id'];

		$this->assertSame( 'draft', get_post_status( $draft_id ) );

		// List drafts.
		$list_response = list_drafts();
		$listed_ids    = wp_list_pluck( $list_response->get_data(), 'id' );
		$this->assertContains( $draft_id, $listed_ids );

		// Update with full details.
		$update_request = new WP_REST_Request( 'POST', '/wporg-groups/v1/draft/' . $draft_id );
		$update_request->set_param( 'id', $draft_id );
		$update_request->set_param( 'title', 'Draft Test (updated)' );
		$update_request->set_param( 'date', $event_date );
		$update_request->set_param( 'time_start', '19:00' );
		$update_request->set_param( 'time_end', '21:00' );
		save_draft( $update_request );

		$this->assertSame( 'draft', get_post_status( $draft_id ) );
		$this->assertSame( 'Draft Test (updated)', get_the_title( $draft_id ) );

		// Publish.
		$publish_request = new WP_REST_Request( 'POST', '/wporg-groups/v1/draft/' . $draft_id . '/publish' );
		$publish_request->set_param( 'id', $draft_id );
		$publish_request->set_param( 'title', 'Draft Now Published' );
		$publish_request->set_param( 'date', $event_date );
		$publish_request->set_param( 'time_start', '19:00' );
		$publish_request->set_param( 'time_end', '21:00' );
		$publish_response = publish_draft( $publish_request );

		$this->assertNotWPError( $publish_response );
		$this->assertSame( 'publish', get_post_status( $draft_id ) );
	}
}
