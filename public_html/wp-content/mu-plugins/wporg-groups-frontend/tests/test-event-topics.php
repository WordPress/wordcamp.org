<?php

namespace WordCamp\Groups\Tests;

use WP_REST_Request;

use function WordCamp\Groups\Frontend\Event_Topics\get_event_topics;
use function WordCamp\Groups\Frontend\Event_Topics\get_suggestions;
use function WordCamp\Groups\Frontend\Event_Topics\sanitize_names;
use function WordCamp\Groups\Frontend\Event_Topics\set_event_topics;
use function WordCamp\Groups\Frontend\REST\create_event;
use function WordCamp\Groups\Frontend\REST\event_form_data_permissions_check;
use function WordCamp\Groups\Frontend\REST\get_event_form_data;
use function WordCamp\Groups\Frontend\REST\save_draft;
use function WordCamp\Groups\Frontend\REST\update_event;

use const WordCamp\Groups\Frontend\Event_Topics\MAX_LENGTH;
use const WordCamp\Groups\Frontend\Event_Topics\MAX_TOPICS;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * @group groups
 */
class Test_Groups_Event_Topics extends Groups_TestCase {

	const TAXONOMY = 'gatherpress_topic';

	/**
	 * An organizer, who may create and publish events on this group.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Creates the organizer each test acts as.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
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
	 * Builds a POST /event request run through the route's own schema, so
	 * the `topics` array is parsed the way a real request's would be.
	 */
	private function event_request( array $params, string $route = '/wporg-groups/v1/event' ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_body_params( $params );
		$request->set_attributes( array( 'args' => \WordCamp\Groups\Frontend\REST\event_args_schema() ) );
		$request->sanitize_params();

		return $request;
	}

	/**
	 * Create a published event as an organizer.
	 *
	 * @param string[] $topics Topic names.
	 */
	private function create_event_with_topics( array $topics ): int {
		wp_set_current_user( $this->editor_id );

		$response = create_event(
			$this->event_request( $this->base_event_params() + array( 'topics' => $topics ) )
		);

		return (int) $response->get_data()['id'];
	}

	/**
	 * Names are trimmed, blanks dropped, repeats folded case-insensitively
	 * with the first spelling kept, and the list capped.
	 */
	public function test_sanitize_names_cleans_and_caps() {
		$this->assertSame(
			array( 'WordPress', 'PHP', 'Accessibility' ),
			sanitize_names( array( '  WordPress ', '', 'wordpress', 'PHP', '<b>Access</b>ibility', '   ' ) )
		);

		$many = array( 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven' );
		$this->assertCount( MAX_TOPICS, sanitize_names( $many ) );
		$this->assertSame( array_slice( $many, 0, MAX_TOPICS ), sanitize_names( $many ) );

		$this->assertSame( MAX_LENGTH, mb_strlen( sanitize_names( array( str_repeat( 'é', 80 ) ) )[0] ) );

		$this->assertSame( array(), sanitize_names( 'WordPress' ) );
		$this->assertSame( array( 'Blocks' ), sanitize_names( array( array( 'nested' ), 'Blocks' ) ) );
	}

	/**
	 * Creating an event stores its topics, making terms for new ones.
	 */
	public function test_create_event_stores_the_topics() {
		$event_id = $this->create_event_with_topics( array( 'WordPress', 'Blocks' ) );

		$this->assertSame( array( 'Blocks', 'WordPress' ), get_event_topics( $event_id ) );
		$this->assertInstanceOf( \WP_Term::class, get_term_by( 'name', 'Blocks', self::TAXONOMY ) );
	}

	/**
	 * An existing topic is reused whatever case it is typed in, so the
	 * vocabulary converges instead of splitting into near-duplicates.
	 */
	public function test_existing_topic_is_reused_case_insensitively() {
		$term = self::factory()->term->create(
			array(
				'taxonomy' => self::TAXONOMY,
				'name'     => 'WordPress',
			)
		);

		$event_id  = $this->create_event_with_topics( array( 'wordpress' ) );
		$all_terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
			)
		);

		$this->assertSame( array( $term ), wp_get_object_terms( $event_id, self::TAXONOMY, array( 'fields' => 'ids' ) ) );
		$this->assertCount( 1, $all_terms );
	}

	/**
	 * The cap holds on the server, whatever the form sent.
	 */
	public function test_create_event_caps_the_topics() {
		$event_id = $this->create_event_with_topics( array( 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven' ) );

		$this->assertCount( MAX_TOPICS, get_event_topics( $event_id ) );
		$this->assertFalse( get_term_by( 'name', 'Six', self::TAXONOMY ), 'A topic over the cap must not be created.' );
	}

	/**
	 * Editing can change and clear the topics, and a request that leaves the
	 * field out leaves them alone.
	 */
	public function test_update_event_changes_clears_and_leaves_alone() {
		$event_id = $this->create_event_with_topics( array( 'WordPress' ) );

		wp_set_current_user( $this->editor_id );

		update_event( $this->event_request( $this->base_event_params() + array( 'id' => $event_id ) ) );
		$this->assertSame( array( 'WordPress' ), get_event_topics( $event_id ), 'An absent field must not clear the topics.' );

		update_event(
			$this->event_request( $this->base_event_params() + array(
				'id'     => $event_id,
				'topics' => array( 'Blocks' ),
			) )
		);
		$this->assertSame( array( 'Blocks' ), get_event_topics( $event_id ) );

		update_event(
			$this->event_request( $this->base_event_params() + array(
				'id'     => $event_id,
				'topics' => array(),
			) )
		);
		$this->assertSame( array(), get_event_topics( $event_id ) );
	}

	/**
	 * A draft keeps its topics, so publishing it does not lose them.
	 */
	public function test_draft_keeps_the_topics() {
		wp_set_current_user( $this->editor_id );

		$response = save_draft(
			$this->event_request(
				$this->base_event_params() + array( 'topics' => array( 'WordPress' ) ),
				'/wporg-groups/v1/draft'
			)
		);

		$this->assertSame( array( 'WordPress' ), get_event_topics( (int) $response->get_data()['id'] ) );
	}

	/**
	 * The form loads an event's topics back, and a new event starts empty.
	 */
	public function test_form_data_returns_the_stored_topics() {
		$event_id = $this->create_event_with_topics( array( 'WordPress' ) );

		wp_set_current_user( $this->editor_id );

		$request = new WP_REST_Request( 'GET', '/wporg-groups/v1/event-form-data' );
		$request->set_param( 'event_id', $event_id );
		$this->assertSame( array( 'WordPress' ), get_event_form_data( $request )->get_data()['fields']['topics'] );

		$new = get_event_form_data( new WP_REST_Request( 'GET', '/wporg-groups/v1/event-form-data' ) )->get_data();
		$this->assertSame( array(), $new['fields']['topics'], 'Topics are not carried from the last event into a blank one.' );
	}

	/**
	 * Starting from a past event carries its topics over.
	 */
	public function test_template_carries_the_topics() {
		$template_id = $this->create_event_with_topics( array( 'WordPress', 'Blocks' ) );

		wp_set_current_user( $this->editor_id );

		$request = new WP_REST_Request( 'GET', '/wporg-groups/v1/event-form-data' );
		$request->set_param( 'template_id', $template_id );

		$this->assertSame( array( 'Blocks', 'WordPress' ), get_event_form_data( $request )->get_data()['fields']['topics'] );
	}

	/**
	 * The form suggests the topics on published events, not ones that only
	 * live on a draft.
	 */
	public function test_form_data_suggests_topics_in_use() {
		$this->create_event_with_topics( array( 'WordPress' ) );

		$draft = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'draft',
			)
		);
		set_event_topics( $draft, array( 'Abandoned idea' ) );

		$this->assertSame( array( 'WordPress' ), get_suggestions() );

		wp_set_current_user( $this->editor_id );
		$data = get_event_form_data( new WP_REST_Request( 'GET', '/wporg-groups/v1/event-form-data' ) )->get_data();
		$this->assertSame( array( 'WordPress' ), $data['topics'] );
	}

	/**
	 * Only someone who can edit an event can load it into the form, which is
	 * the gate every write path of the topics sits behind too.
	 */
	public function test_member_cannot_reach_the_form() {
		$event_id  = $this->create_event_with_topics( array( 'WordPress' ) );
		$member_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $member_id );

		$request = new WP_REST_Request( 'GET', '/wporg-groups/v1/event-form-data' );
		$request->set_param( 'event_id', $event_id );
		$this->assertFalse( event_form_data_permissions_check( $request ) );

		$route = rest_get_server()->get_routes()['/wporg-groups/v1/event/(?P<id>\d+)'] ?? null;
		$this->assertNotNull( $route, 'The update route should be registered.' );

		$update = new WP_REST_Request( 'POST', "/wporg-groups/v1/event/{$event_id}" );
		$update->set_body_params( $this->base_event_params() + array( 'topics' => array( 'Spam' ) ) );
		$response = rest_get_server()->dispatch( $update );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( array( 'WordPress' ), get_event_topics( $event_id ) );
		$this->assertFalse( get_term_by( 'name', 'Spam', self::TAXONOMY ) );
	}

	/**
	 * The event page lists each topic, linked to the filtered archive, and
	 * shows nothing when there are none.
	 */
	public function test_block_links_each_topic_to_the_filtered_archive() {
		$event_id = $this->create_event_with_topics( array( 'Block Themes' ) );

		$this->go_to( home_url( "?p={$event_id}&post_type=gatherpress_event" ) );
		$markup = do_blocks( '<!-- wp:wporg/event-topics /-->' );

		$this->assertStringContainsString( '>Block Themes</a>', $markup );
		$this->assertStringContainsString( 'event_topic=block-themes', $markup );

		set_event_topics( $event_id, array() );
		$this->assertSame( '', do_blocks( '<!-- wp:wporg/event-topics /-->' ) );
	}

	/**
	 * Topics follow the event's password gate like the rest of the card.
	 */
	public function test_block_respects_the_event_password_gate() {
		$event_id = $this->create_event_with_topics( array( 'WordPress' ) );

		wp_update_post(
			array(
				'ID'            => $event_id,
				'post_password' => 'secret',
			)
		);

		$this->go_to( home_url( "?p={$event_id}&post_type=gatherpress_event" ) );

		$this->assertSame( '', do_blocks( '<!-- wp:wporg/event-topics /-->' ) );
	}
}
