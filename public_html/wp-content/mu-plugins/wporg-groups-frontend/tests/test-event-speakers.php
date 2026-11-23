<?php

namespace WordCamp\Groups\Tests;

use WP_REST_Request;

use function WordCamp\Groups\Frontend\REST\create_event;
use function WordCamp\Groups\Frontend\REST\draft_args_schema;
use function WordCamp\Groups\Frontend\REST\event_args_schema;
use function WordCamp\Groups\Frontend\REST\get_event_form_data;
use function WordCamp\Groups\Frontend\REST\save_draft;
use function WordCamp\Groups\Frontend\REST\update_event;

use const WordCamp\Groups\Frontend\Event_Speakers\MAX_SPEAKERS;
use const WordCamp\Groups\Frontend\Event_Speakers\META_KEY;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * Speakers are set through the event form and saved with the event (#2424).
 *
 * @group groups
 */
class Test_Groups_Event_Speakers extends Groups_TestCase {

	/**
	 * An organizer, who may create and publish events on this group.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Two members of the group, to name as speakers.
	 *
	 * @var int[]
	 */
	private $member_ids;

	/**
	 * Creates the organizer each test acts as, and the members.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->editor_id  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->member_ids = array(
			self::factory()->user->create(
				array(
					'role'         => 'subscriber',
					'display_name' => 'Ana Speaker',
				)
			),
			self::factory()->user->create(
				array(
					'role'         => 'subscriber',
					'display_name' => 'Bo Speaker',
				)
			),
		);

		wp_set_current_user( $this->editor_id );
	}

	/**
	 * Builds a request run through a route's own schema, so the `speakers`
	 * array is parsed the way a real request's would be.
	 *
	 * @param array  $params Body params.
	 * @param string $route  Route.
	 * @param array  $schema Argument schema.
	 */
	private function request( array $params, string $route = '/wporg-groups/v1/event', ?array $schema = null ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_body_params( $params );
		$request->set_attributes( array( 'args' => $schema ?? event_args_schema() ) );
		$request->sanitize_params();

		return $request;
	}

	/**
	 * A minimal valid set of event params, for tests to add to.
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
	 * Create a published event as the organizer.
	 *
	 * @param array $extra Params on top of the base ones.
	 */
	private function create_event( array $extra = array() ): int {
		return (int) create_event( $this->request( $this->base_event_params() + $extra ) )->get_data()['id'];
	}

	/**
	 * The event form's speakers for a request.
	 *
	 * @param array $params Query params.
	 */
	private function form_speaker_ids( array $params = array() ): array {
		$request = new WP_REST_Request( 'GET', '/wporg-groups/v1/event-form-data' );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return wp_list_pluck( get_event_form_data( $request )->get_data()['fields']['speakers'], 'id' );
	}

	/**
	 * Creating an event stores the speakers in order, members only, once each.
	 */
	public function test_create_event_stores_the_speakers() {
		list( $ana, $bo ) = $this->member_ids;

		$outsider = self::factory()->user->create();
		remove_user_from_blog( $outsider, get_current_blog_id() );

		$event_id = $this->create_event( array( 'speakers' => array( $bo, $outsider, $ana, $bo ) ) );

		$this->assertSame( array( $bo, $ana ), get_post_meta( $event_id, META_KEY, true ) );
	}

	/**
	 * The stored list is capped.
	 */
	public function test_speakers_are_capped() {
		$many = self::factory()->user->create_many( MAX_SPEAKERS + 2 );

		$event_id = $this->create_event( array( 'speakers' => $many ) );

		$this->assertSame( array_slice( $many, 0, MAX_SPEAKERS ), get_post_meta( $event_id, META_KEY, true ) );
	}

	/**
	 * A save that doesn't mention speakers leaves them alone; an empty list
	 * clears them.
	 */
	public function test_update_keeps_or_clears_the_speakers() {
		list( $ana, $bo ) = $this->member_ids;

		$event_id = $this->create_event( array( 'speakers' => array( $ana, $bo ) ) );

		update_event( $this->request( $this->base_event_params() + array( 'id' => $event_id ) ) );
		$this->assertSame( array( $ana, $bo ), get_post_meta( $event_id, META_KEY, true ) );

		update_event(
			$this->request(
				$this->base_event_params() + array(
					'id'       => $event_id,
					'speakers' => array( $bo ),
				)
			)
		);
		$this->assertSame( array( $bo ), get_post_meta( $event_id, META_KEY, true ) );

		update_event(
			$this->request(
				$this->base_event_params() + array(
					'id'       => $event_id,
					'speakers' => array(),
				)
			)
		);
		$this->assertSame( '', get_post_meta( $event_id, META_KEY, true ) );
	}

	/**
	 * The modal's autosave keeps the speakers on the draft.
	 */
	public function test_save_draft_stores_the_speakers() {
		list( $ana ) = $this->member_ids;

		$request  = $this->request(
			array(
				'title'    => 'Draft',
				'speakers' => array( $ana ),
			),
			'/wporg-groups/v1/draft',
			draft_args_schema()
		);
		$draft_id = (int) save_draft( $request )->get_data()['id'];

		$this->assertSame( array( $ana ), get_post_meta( $draft_id, META_KEY, true ) );
	}

	/**
	 * A new event's form has no speakers; an existing event's form and a copy
	 * of it show its speakers, minus any whose account is gone; an event
	 * started from a template doesn't take the template's speakers.
	 */
	public function test_form_data_speakers() {
		list( $ana, $bo ) = $this->member_ids;

		$event_id = $this->create_event( array( 'speakers' => array( $ana, $bo ) ) );
		update_post_meta( $event_id, META_KEY, array( $ana, PHP_INT_MAX, $bo ) );

		$this->assertSame( array(), $this->form_speaker_ids() );
		$this->assertSame( array( $ana, $bo ), $this->form_speaker_ids( array( 'event_id' => $event_id ) ) );
		$copy = array(
			'template_id' => $event_id,
			'copy'        => true,
		);
		$this->assertSame( array( $ana, $bo ), $this->form_speaker_ids( $copy ) );
		$this->assertSame( array(), $this->form_speaker_ids( array( 'template_id' => $event_id ) ) );
	}
}
