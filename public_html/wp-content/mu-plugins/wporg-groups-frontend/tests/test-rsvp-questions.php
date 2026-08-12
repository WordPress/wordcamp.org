<?php

namespace WordCamp\Groups\Tests;

use WP_REST_Request;

use function WordCamp\Groups\Frontend\REST\create_event;
use function WordCamp\Groups\Frontend\REST\save_rsvp;
use function WordCamp\Groups\Frontend\REST\update_event;
use function WordCamp\Groups\Frontend\RSVP_Questions\get_answers;
use function WordCamp\Groups\Frontend\RSVP_Questions\get_labelled_answers;
use function WordCamp\Groups\Frontend\RSVP_Questions\get_questions;
use function WordCamp\Groups\Frontend\RSVP_Questions\sanitize_questions;
use function WordCamp\Groups\Frontend\RSVP_Questions\save_questions;

use const WordCamp\Groups\Frontend\RSVP_Questions\MAX_QUESTIONS;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * Custom per-event registration questions, and the answers attendees give at
 * RSVP time.
 *
 * @group groups
 */
class Test_Groups_RSVP_Questions extends Groups_TestCase {

	/**
	 * Create a published event, owned by an editor.
	 */
	private function create_event( array $questions = array() ): int {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
			)
		);

		// Far enough out that `has_event_past()` is false.
		( new \GatherPress\Core\Event\Event( $event_id ) )->save_datetimes(
			array(
				'post_id'        => $event_id,
				'datetime_start' => gmdate( 'Y-m-d H:i:s', strtotime( '+30 days' ) ),
				'datetime_end'   => gmdate( 'Y-m-d H:i:s', strtotime( '+30 days +2 hours' ) ),
				'timezone'       => 'UTC',
			)
		);

		if ( $questions ) {
			save_questions( $event_id, $questions );
		}

		return $event_id;
	}

	/**
	 * Build a POST /event/{id}/rsvp request.
	 */
	private function rsvp_request( int $event_id, string $status, array $answers = array() ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', "/wporg-groups/v1/event/{$event_id}/rsvp" );
		$request->set_param( 'id', $event_id );
		$request->set_param( 'status', $status );
		$request->set_param( 'answers', $answers );

		return $request;
	}

	/**
	 * Unlabelled rows are dropped, `required` is normalised to a boolean, and
	 * new questions (empty id) are given one.
	 */
	public function test_sanitize_questions_drops_blanks_and_assigns_ids() {
		$questions = sanitize_questions(
			array(
				array(
					'id'       => '',
					'label'    => 'Company name',
					'required' => 1,
				),
				array(
					'id'    => '',
					'label' => '   ',
				),
				array(
					'id'    => '',
					'label' => 'Dietary requirements',
				),
			)
		);

		$this->assertCount( 2, $questions );
		$this->assertSame( 'Company name', $questions[0]['label'] );
		$this->assertTrue( $questions[0]['required'] );
		$this->assertSame( 'Dietary requirements', $questions[1]['label'] );
		$this->assertFalse( $questions[1]['required'] );
		$this->assertNotEmpty( $questions[0]['id'] );
		$this->assertNotSame( $questions[0]['id'], $questions[1]['id'] );
	}

	/**
	 * The question count is capped, so this stays a couple of extra questions
	 * rather than a form builder.
	 */
	public function test_question_count_is_capped() {
		$raw = array();
		for ( $i = 0; $i < MAX_QUESTIONS + 3; $i++ ) {
			$raw[] = array(
				'id'    => '',
				'label' => "Question {$i}",
			);
		}

		$this->assertCount( MAX_QUESTIONS, sanitize_questions( $raw ) );
	}

	/**
	 * Ids assigned on the first save survive later edits, so answers already
	 * given stay attached to the question that was asked.
	 */
	public function test_question_ids_are_stable_across_saves() {
		$event_id = $this->create_event(
			array(
				array(
					'id'    => '',
					'label' => 'T-shirt size',
				),
			)
		);

		$original = get_questions( $event_id );

		save_questions(
			$event_id,
			array(
				array(
					'id'       => $original[0]['id'],
					'label'    => 'T-shirt size (EU)',
					'required' => true,
				),
			)
		);

		$updated = get_questions( $event_id );

		$this->assertSame( $original[0]['id'], $updated[0]['id'] );
		$this->assertSame( 'T-shirt size (EU)', $updated[0]['label'] );
	}

	/**
	 * A deleted question's id is never handed back out — otherwise the answers
	 * previous attendees gave would resurface under a new question's label.
	 */
	public function test_deleted_question_ids_are_not_reused() {
		$event_id = $this->create_event(
			array(
				array(
					'id'    => '',
					'label' => 'Company name',
				),
			)
		);

		$deleted_id = get_questions( $event_id )[0]['id'];

		save_questions(
			$event_id,
			array(
				array(
					'id'    => '',
					'label' => 'Dietary requirements',
				),
			)
		);

		$this->assertNotSame( $deleted_id, get_questions( $event_id )[0]['id'] );
	}

	/**
	 * Questions round-trip through the event create endpoint.
	 */
	public function test_questions_saved_with_new_event() {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$request = new WP_REST_Request( 'POST', '/wporg-groups/v1/event' );
		$request->set_param( 'title', 'Test Event' );
		$request->set_param( 'date', gmdate( 'Y-m-d', strtotime( '+30 days' ) ) );
		$request->set_param( 'time_start', '18:00' );
		$request->set_param( 'time_end', '20:00' );
		$request->set_param(
			'rsvp_questions',
			array(
				array(
					'id'       => '',
					'label'    => 'Dietary requirements',
					'required' => true,
				),
			)
		);

		$response  = create_event( $request );
		$questions = get_questions( $response->get_data()['id'] );

		$this->assertCount( 1, $questions );
		$this->assertSame( 'Dietary requirements', $questions[0]['label'] );
		$this->assertTrue( $questions[0]['required'] );
	}

	/**
	 * An update that doesn't mention `rsvp_questions` leaves the existing ones
	 * alone, rather than reading the absent parameter as "delete them all".
	 */
	public function test_update_without_questions_param_keeps_them() {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$event_id = $this->create_event(
			array(
				array(
					'id'    => '',
					'label' => 'Company name',
				),
			)
		);

		$request = new WP_REST_Request( 'POST', "/wporg-groups/v1/event/{$event_id}" );
		$request->set_param( 'id', $event_id );
		$request->set_param( 'title', 'Renamed Event' );
		$request->set_param( 'date', gmdate( 'Y-m-d', strtotime( '+30 days' ) ) );
		$request->set_param( 'time_start', '18:00' );
		$request->set_param( 'time_end', '20:00' );

		update_event( $request );

		$this->assertCount( 1, get_questions( $event_id ) );
	}

	/**
	 * Answers are stored against the RSVP when an attendee submits them.
	 */
	public function test_rsvp_stores_answers() {
		$event_id = $this->create_event(
			array(
				array(
					'id'    => 'company',
					'label' => 'Company name',
				),
			)
		);

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$response = save_rsvp( $this->rsvp_request( $event_id, 'attending', array( 'company' => 'Automattic' ) ) );

		$this->assertNotWPError( $response );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( 'attending', $data['status'] );

		$rsvp = ( new \GatherPress\Core\Rsvp\Rsvp( $event_id ) )->get( $user_id );
		$this->assertSame( array( 'company' => 'Automattic' ), get_answers( (int) $rsvp['comment_id'] ) );
	}

	/**
	 * A required question with no answer blocks the RSVP outright — a recorded
	 * RSVP without the organizer's required detail is exactly the wrong-data
	 * problem this feature exists to avoid.
	 */
	public function test_missing_required_answer_blocks_rsvp() {
		$event_id = $this->create_event(
			array(
				array(
					'id'       => 'diet',
					'label'    => 'Dietary requirements',
					'required' => true,
				),
			)
		);

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$response = save_rsvp( $this->rsvp_request( $event_id, 'attending' ) );

		$this->assertWPError( $response );
		$this->assertSame( 'wporg_groups_missing_answers', $response->get_error_code() );

		$rsvp = ( new \GatherPress\Core\Rsvp\Rsvp( $event_id ) )->get( $user_id );
		$this->assertSame( 'no_status', $rsvp['status'] ?? 'no_status' );
	}

	/**
	 * Answers to questions the event doesn't ask are discarded.
	 */
	public function test_unknown_answers_are_discarded() {
		$event_id = $this->create_event(
			array(
				array(
					'id'    => 'company',
					'label' => 'Company name',
				),
			)
		);

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		save_rsvp(
			$this->rsvp_request(
				$event_id,
				'attending',
				array(
					'company' => 'Automattic',
					'sneaky'  => 'not asked',
				)
			)
		);

		$rsvp = ( new \GatherPress\Core\Rsvp\Rsvp( $event_id ) )->get( $user_id );

		$this->assertSame( array( 'company' => 'Automattic' ), get_answers( (int) $rsvp['comment_id'] ) );
	}

	/**
	 * Cancelling an RSVP takes the answers with it.
	 */
	public function test_cancelling_rsvp_clears_answers() {
		$event_id = $this->create_event(
			array(
				array(
					'id'    => 'company',
					'label' => 'Company name',
				),
			)
		);

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		save_rsvp( $this->rsvp_request( $event_id, 'attending', array( 'company' => 'Automattic' ) ) );

		$rsvp       = ( new \GatherPress\Core\Rsvp\Rsvp( $event_id ) )->get( $user_id );
		$comment_id = (int) $rsvp['comment_id'];
		$this->assertNotEmpty( get_answers( $comment_id ) );

		save_rsvp( $this->rsvp_request( $event_id, 'not_attending' ) );

		$this->assertSame( array(), get_answers( $comment_id ) );
	}

	/**
	 * Displayed answers are paired with the current question labels, and
	 * answers to since-deleted questions are left out.
	 */
	public function test_labelled_answers_follow_the_current_questions() {
		$event_id = $this->create_event(
			array(
				array(
					'id'    => 'company',
					'label' => 'Company name',
				),
				array(
					'id'    => 'diet',
					'label' => 'Dietary requirements',
				),
			)
		);

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		save_rsvp(
			$this->rsvp_request(
				$event_id,
				'attending',
				array(
					'company' => 'Automattic',
					'diet'    => 'Vegetarian',
				)
			)
		);

		$rsvp       = ( new \GatherPress\Core\Rsvp\Rsvp( $event_id ) )->get( $user_id );
		$comment_id = (int) $rsvp['comment_id'];

		// The organizer drops the dietary question afterwards.
		save_questions(
			$event_id,
			array(
				array(
					'id'    => 'company',
					'label' => 'Company or organization',
				),
			)
		);

		$labelled = get_labelled_answers( $comment_id, get_questions( $event_id ) );

		$this->assertSame(
			array(
				array(
					'label'  => 'Company or organization',
					'answer' => 'Automattic',
				),
			),
			$labelled
		);
	}

	/**
	 * An answer of "0" is a real answer — `empty()` would call it missing,
	 * while the browser treats the same string as filled in.
	 */
	public function test_zero_is_a_valid_answer() {
		$event_id = $this->create_event(
			array(
				array(
					'id'       => 'guests',
					'label'    => 'How many guests?',
					'required' => true,
				),
			)
		);

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$response = save_rsvp( $this->rsvp_request( $event_id, 'attending', array( 'guests' => '0' ) ) );

		$this->assertNotWPError( $response );
		$this->assertTrue( $response->get_data()['success'] );

		$rsvp       = ( new \GatherPress\Core\Rsvp\Rsvp( $event_id ) )->get( $user_id );
		$comment_id = (int) $rsvp['comment_id'];

		$this->assertSame( array( 'guests' => '0' ), get_answers( $comment_id ) );
		$this->assertSame(
			array(
				array(
					'label'  => 'How many guests?',
					'answer' => '0',
				),
			),
			get_labelled_answers( $comment_id, get_questions( $event_id ) )
		);
	}

	/**
	 * A request that doesn't mention a question leaves its stored answer
	 * alone — a stale form can't blank out answers it never rendered.
	 */
	public function test_omitted_questions_keep_their_stored_answers() {
		$event_id = $this->create_event(
			array(
				array(
					'id'    => 'company',
					'label' => 'Company name',
				),
			)
		);

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		save_rsvp( $this->rsvp_request( $event_id, 'attending', array( 'company' => 'Automattic' ) ) );

		// A second, stale tab re-sends "attending" with nothing filled in.
		save_rsvp( $this->rsvp_request( $event_id, 'attending', array() ) );

		$rsvp = ( new \GatherPress\Core\Rsvp\Rsvp( $event_id ) )->get( $user_id );

		$this->assertSame( array( 'company' => 'Automattic' ), get_answers( (int) $rsvp['comment_id'] ) );
	}

	/**
	 * Submitting a question with a blank value does clear it, which is how an
	 * attendee removes an answer they'd rather not share.
	 */
	public function test_submitted_blank_clears_the_answer() {
		$event_id = $this->create_event(
			array(
				array(
					'id'    => 'company',
					'label' => 'Company name',
				),
			)
		);

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		save_rsvp( $this->rsvp_request( $event_id, 'attending', array( 'company' => 'Automattic' ) ) );
		save_rsvp( $this->rsvp_request( $event_id, 'attending', array( 'company' => '' ) ) );

		$rsvp = ( new \GatherPress\Core\Rsvp\Rsvp( $event_id ) )->get( $user_id );

		$this->assertSame( array(), get_answers( (int) $rsvp['comment_id'] ) );
	}

	/**
	 * A full event downgrades the RSVP to the waiting list. The answers have
	 * to survive that, because the later promotion to attending never
	 * restores them.
	 */
	public function test_waiting_list_rsvp_keeps_its_answers() {
		$event_id = $this->create_event(
			array(
				array(
					'id'    => 'company',
					'label' => 'Company name',
				),
			)
		);

		// One seat, already taken, so the next RSVP lands on the waiting list.
		update_post_meta( $event_id, 'gatherpress_max_attendance_limit', 1 );

		$first = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $first );
		save_rsvp( $this->rsvp_request( $event_id, 'attending', array( 'company' => 'First' ) ) );

		$second = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $second );
		$response = save_rsvp( $this->rsvp_request( $event_id, 'attending', array( 'company' => 'Second' ) ) );

		$this->assertNotWPError( $response );
		$this->assertSame( 'waiting_list', $response->get_data()['status'] );

		$rsvp = ( new \GatherPress\Core\Rsvp\Rsvp( $event_id ) )->get( $second );

		$this->assertSame( array( 'company' => 'Second' ), get_answers( (int) $rsvp['comment_id'] ) );
	}

	/**
	 * An event with RSVP switched off reports the failure instead of
	 * returning a 200 that the client can only read as "OK".
	 */
	public function test_rsvp_disabled_event_returns_an_error() {
		$event_id = $this->create_event();

		// The groups tweaks plugin short-circuits `gatherpress_settings` via
		// `pre_option_*`, so the stored option is never read — the mode has to
		// be injected through the same filter. `disabled` makes
		// `Rsvp::is_enabled()` false regardless of the per-event meta.
		$disable_rsvp = static function ( $value ) {
			$value = is_array( $value ) ? $value : array();

			$value['rsvp_mode'] = 'disabled';

			return $value;
		};
		add_filter( 'pre_option_gatherpress_settings', $disable_rsvp, 20 );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$response = save_rsvp( $this->rsvp_request( $event_id, 'attending' ) );

		remove_filter( 'pre_option_gatherpress_settings', $disable_rsvp, 20 );

		$this->assertWPError( $response );
		$this->assertSame( 'wporg_groups_rsvp_unavailable', $response->get_error_code() );
	}

	/**
	 * GatherPress's own RSVP route can't be used to attend while skipping the
	 * required questions our endpoint enforces.
	 */
	public function test_gatherpress_route_is_guarded() {
		$event_id = $this->create_event(
			array(
				array(
					'id'       => 'diet',
					'label'    => 'Dietary requirements',
					'required' => true,
				),
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$request = new WP_REST_Request( 'POST', '/gatherpress/v1/event/rsvp' );
		$request->set_param( 'post_id', $event_id );
		$request->set_param( 'status', 'attending' );

		$result = \WordCamp\Groups\Frontend\RSVP_Questions\rsvp_answers_satisfied( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'wporg_groups_missing_answers', $result->get_error_code() );

		// Cancelling is never blocked.
		$cancel = new WP_REST_Request( 'POST', '/gatherpress/v1/event/rsvp' );
		$cancel->set_param( 'post_id', $event_id );
		$cancel->set_param( 'status', 'not_attending' );

		$this->assertTrue( \WordCamp\Groups\Frontend\RSVP_Questions\rsvp_answers_satisfied( $cancel ) );
	}

	/**
	 * The guard is wired onto the real route, not just callable in isolation.
	 */
	public function test_gatherpress_route_guard_is_registered() {
		$endpoints = apply_filters(
			'rest_endpoints',
			array(
				'/gatherpress/v1/event/rsvp' => array(
					array( 'permission_callback' => '__return_true' ),
				),
			)
		);

		$callback = $endpoints['/gatherpress/v1/event/rsvp'][0]['permission_callback'];

		$this->assertNotSame( '__return_true', $callback );

		$event_id = $this->create_event(
			array(
				array(
					'id'       => 'diet',
					'label'    => 'Dietary requirements',
					'required' => true,
				),
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$request = new WP_REST_Request( 'POST', '/gatherpress/v1/event/rsvp' );
		$request->set_param( 'post_id', $event_id );
		$request->set_param( 'status', 'attending' );

		$this->assertWPError( call_user_func( $callback, $request ) );
	}

	/**
	 * Render the `wporg/event-rsvp` block in the context of an event.
	 */
	private function render_rsvp_block( int $event_id ): string {
		global $post;

		$post = get_post( $event_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );

		$output = (string) do_blocks( '<!-- wp:wporg/event-rsvp /-->' );

		wp_reset_postdata();

		return $output;
	}

	/**
	 * The RSVP modal renders an input per question, so attendees answer them
	 * on the event page rather than on an external form.
	 */
	public function test_questions_render_in_the_rsvp_modal() {
		$event_id = $this->create_event(
			array(
				array(
					'id'    => 'company',
					'label' => 'Company name',
				),
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$output = $this->render_rsvp_block( $event_id );

		$this->assertStringContainsString( 'wporg-event-rsvp__question-input', $output );
		$this->assertStringContainsString( 'Company name', $output );
	}

	/**
	 * Answers show up in the attendee list for organizers, and for nobody else.
	 */
	public function test_answers_render_only_for_organizers() {
		$event_id = $this->create_event(
			array(
				array(
					'id'    => 'diet',
					'label' => 'Dietary requirements',
				),
			)
		);

		$attendee_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $attendee_id );
		save_rsvp( $this->rsvp_request( $event_id, 'attending', array( 'diet' => 'Vegetarian' ) ) );

		// A fellow attendee sees the attendee, but not what they answered.
		$other_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $other_id );
		$this->assertStringNotContainsString( 'Vegetarian', $this->render_rsvp_block( $event_id ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertStringContainsString( 'Vegetarian', $this->render_rsvp_block( $event_id ) );
	}

	/**
	 * Answers are for the people running the event: visible to whoever can
	 * edit it, not to other attendees.
	 */
	public function test_only_event_editors_can_view_answers() {
		$event_id = $this->create_event();

		$editor_id     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $subscriber_id );
		$this->assertFalse( \WordCamp\Groups\Frontend\RSVP_Questions\current_user_can_view_answers( $event_id ) );

		wp_set_current_user( $editor_id );
		$this->assertTrue( \WordCamp\Groups\Frontend\RSVP_Questions\current_user_can_view_answers( $event_id ) );
	}
}
