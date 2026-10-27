<?php

namespace WordCamp\Groups\Tests;

use WP_REST_Request;

use function WordCamp\Groups\Frontend\Defaults\get_default_event_data;
use function WordCamp\Groups\Frontend\Event_Language\get_default;
use function WordCamp\Groups\Frontend\Event_Language\get_event_language;
use function WordCamp\Groups\Frontend\Event_Language\get_name;
use function WordCamp\Groups\Frontend\Event_Language\get_options;
use function WordCamp\Groups\Frontend\Event_Language\sanitize_code;
use function WordCamp\Groups\Frontend\Event_Language\set_event_language;
use function WordCamp\Groups\Frontend\REST\create_event;
use function WordCamp\Groups\Frontend\REST\get_event_form_data;
use function WordCamp\Groups\Frontend\REST\save_draft;
use function WordCamp\Groups\Frontend\REST\update_event;

use const WordCamp\Groups\Frontend\Event_Language\META_KEY;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * @group groups
 */
class Test_Groups_Event_Language extends Groups_TestCase {

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
	 * Builds a POST /event request with the given params, run through the
	 * route's own schema so `sanitize_callback` applies the way it does in a
	 * real request.
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
	 */
	private function create_event_with_language( string $language ): int {
		wp_set_current_user( $this->editor_id );

		$response = create_event(
			$this->event_request( $this->base_event_params() + array( 'language' => $language ) )
		);

		return (int) $response->get_data()['id'];
	}

	/**
	 * The option list is the plain language subtags, not CLDR's regional
	 * variants: the request was that Spanish speakers across Spain, Mexico and
	 * Argentina share one language.
	 */
	public function test_options_exclude_regional_variants() {
		$options = get_options();

		$this->assertArrayHasKey( 'es', $options );
		$this->assertArrayHasKey( 'fil', $options );
		$this->assertArrayNotHasKey( 'es-ES', $options );
		$this->assertArrayNotHasKey( 'pt-BR', $options );
		$this->assertArrayNotHasKey( 'az-alt-short', $options );
	}

	/**
	 * The list is offered in name order, not in whatever order CLDR returns,
	 * and an accent does not sink a language to the bottom of it.
	 */
	public function test_options_are_sorted_by_name() {
		$order = array_flip( array_keys( get_options() ) );

		$this->assertLessThan( $order['es'], $order['en'], 'English should come before Spanish.' );
		$this->assertLessThan( $order['fr'], $order['en'], 'English should come before French.' );

		// "Võro" folds to "Voro", which belongs between "Volapük" and "Votic"
		// rather than after every unaccented name.
		$voro  = array_search( 'Võro', get_options(), true );
		$votic = array_search( 'Votic', get_options(), true );
		$this->assertLessThan( $order[ $votic ], $order[ $voro ] );
	}

	/**
	 * Anything that is not a language this site can name becomes "unset",
	 * rather than being stored as a code nothing could ever label.
	 */
	public function test_sanitize_drops_unknown_codes() {
		$this->assertSame( 'es', sanitize_code( 'es' ) );
		$this->assertSame( 'es', sanitize_code( 'ES' ) );
		$this->assertSame( 'es', sanitize_code( '  es  ' ) );
		$this->assertSame( '', sanitize_code( 'es-ES' ) );
		$this->assertSame( '', sanitize_code( 'klingon' ) );
		$this->assertSame( '', sanitize_code( '<script>' ) );
		$this->assertSame( '', sanitize_code( '' ) );
	}

	/**
	 * An unknown code has no name, so the event page and the filter both have
	 * something to branch on.
	 */
	public function test_get_name_resolves_only_known_codes() {
		$this->assertSame( 'Spanish', get_name( 'es' ) );
		$this->assertSame( 'Spanish', get_name( 'ES' ) );
		$this->assertSame( '', get_name( 'klingon' ) );
	}

	/**
	 * Clearing the language removes the meta rather than storing an empty
	 * string, so `EXISTS` queries do not have to filter blanks back out.
	 */
	public function test_setting_an_empty_language_deletes_the_meta() {
		$event_id = self::factory()->post->create( array( 'post_type' => 'gatherpress_event' ) );

		set_event_language( $event_id, 'es' );
		$this->assertSame( 'es', get_event_language( $event_id ) );
		$this->assertSame( array( 'es' ), get_post_meta( $event_id, META_KEY, false ) );

		set_event_language( $event_id, '' );
		$this->assertSame( '', get_event_language( $event_id ) );
		$this->assertSame( array(), get_post_meta( $event_id, META_KEY, false ) );
	}

	/**
	 * A code that was valid when it was written but is not recognized now
	 * reads back as unset rather than as itself.
	 */
	public function test_reading_back_an_unrecognized_stored_code() {
		$event_id = self::factory()->post->create( array( 'post_type' => 'gatherpress_event' ) );

		update_post_meta( $event_id, META_KEY, 'not-a-language' );

		$this->assertSame( '', get_event_language( $event_id ) );
	}

	/**
	 * Creating an event through the front-end form stores its language.
	 */
	public function test_create_event_stores_the_language() {
		$event_id = $this->create_event_with_language( 'es' );

		$this->assertSame( 'es', get_event_language( $event_id ) );
	}

	/**
	 * An unrecognized language clears the field instead of failing the save:
	 * the rest of the form is the organizer's real work.
	 */
	public function test_create_event_drops_an_unrecognized_language() {
		$event_id = $this->create_event_with_language( 'es-419' );

		$this->assertSame( '', get_event_language( $event_id ) );
		$this->assertSame( 'Test Event', get_the_title( $event_id ) );
	}

	/**
	 * Editing an event can change its language, and can clear it.
	 */
	public function test_update_event_changes_and_clears_the_language() {
		$event_id = $this->create_event_with_language( 'es' );

		wp_set_current_user( $this->editor_id );

		update_event(
			$this->event_request( $this->base_event_params() + array(
				'id' => $event_id, 'language' => 'en',
			) )
		);
		$this->assertSame( 'en', get_event_language( $event_id ) );

		update_event(
			$this->event_request( $this->base_event_params() + array(
				'id' => $event_id, 'language' => '',
			) )
		);
		$this->assertSame( '', get_event_language( $event_id ) );
	}

	/**
	 * A draft keeps the language too, so publishing it does not lose the
	 * answer the organizer already gave.
	 */
	public function test_draft_keeps_the_language() {
		wp_set_current_user( $this->editor_id );

		$response = save_draft(
			$this->event_request(
				$this->base_event_params() + array( 'language' => 'es' ),
				'/wporg-groups/v1/draft'
			)
		);

		$this->assertSame( 'es', get_event_language( (int) $response->get_data()['id'] ) );
	}

	/**
	 * The form loads an existing event's language back.
	 */
	public function test_form_data_returns_the_stored_language() {
		$event_id = $this->create_event_with_language( 'es' );

		wp_set_current_user( $this->editor_id );

		$request = new WP_REST_Request( 'GET', '/wporg-groups/v1/event-form-data' );
		$request->set_param( 'event_id', $event_id );

		$this->assertSame( 'es', get_event_form_data( $request )->get_data()['fields']['language'] );
	}

	/**
	 * The form ships the language list with the rest of its payload, so the
	 * modal still opens on a single fetch.
	 */
	public function test_form_data_ships_the_language_list() {
		wp_set_current_user( $this->editor_id );

		$data = get_event_form_data( new WP_REST_Request( 'GET', '/wporg-groups/v1/event-form-data' ) )->get_data();

		$this->assertNotEmpty( $data['languages'] );
		$this->assertSame( array( 'code', 'name' ), array_keys( $data['languages'][0] ) );
		$spanish = array(
			'code' => 'es',
			'name' => 'Spanish',
		);

		$this->assertContains( $spanish, $data['languages'] );
	}

	/**
	 * A new event is prefilled from the group's last event rather than from
	 * the site locale, so a group that switched languages keeps the switch.
	 */
	public function test_new_event_defaults_to_the_previous_events_language() {
		$this->assertSame( get_default(), get_default_event_data()['language'] );

		$this->create_event_with_language( 'es' );

		$this->assertSame( 'es', get_default_event_data()['language'] );
	}

	/**
	 * An earlier event with no language recorded must not wipe the default
	 * back to nothing — there is no answer there to carry forward.
	 */
	public function test_an_untagged_previous_event_leaves_the_default_alone() {
		$this->create_event_with_language( '' );

		$this->assertSame( get_default(), get_default_event_data()['language'] );
	}

	/**
	 * The event page shows the language, and shows nothing at all when the
	 * organizer left it unset.
	 */
	public function test_block_renders_only_for_an_event_with_a_language() {
		$event_id = $this->create_event_with_language( 'es' );

		$markup = do_blocks( '<!-- wp:wporg/event-language /-->' );
		$this->assertSame( '', $markup, 'Outside an event there is no language to render.' );

		$this->go_to( home_url( "?p={$event_id}&post_type=gatherpress_event" ) );
		$this->assertStringContainsString( 'Spanish', do_blocks( '<!-- wp:wporg/event-language /-->' ) );

		set_event_language( $event_id, '' );
		$this->assertSame( '', do_blocks( '<!-- wp:wporg/event-language /-->' ) );
	}

	/**
	 * The roster, the speakers and the language all follow the event's
	 * password gate; the language must not be the one that leaks.
	 */
	public function test_block_respects_the_event_password_gate() {
		$event_id = $this->create_event_with_language( 'es' );

		wp_update_post(
			array(
				'ID'            => $event_id,
				'post_password' => 'secret',
			)
		);

		$this->go_to( home_url( "?p={$event_id}&post_type=gatherpress_event" ) );

		$this->assertSame( '', do_blocks( '<!-- wp:wporg/event-language /-->' ) );
	}
}
