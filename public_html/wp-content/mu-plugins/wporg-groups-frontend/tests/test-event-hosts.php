<?php

namespace WordCamp\Groups\Tests;

use WP_REST_Request;

use function WordCamp\Groups\Frontend\Event_Hosts\get_event_hosts;
use function WordCamp\Groups\Frontend\Event_Hosts\sanitize_ids;
use function WordCamp\Groups\Frontend\Event_Hosts\set_event_hosts;
use function WordCamp\Groups\Frontend\REST\create_event;
use function WordCamp\Groups\Frontend\REST\get_event_form_data;
use function WordCamp\Groups\Frontend\REST\save_draft;
use function WordCamp\Groups\Frontend\REST\update_event;

use const WordCamp\Groups\Frontend\Event_Hosts\MAX_HOSTS;
use const WordCamp\Groups\Frontend\Event_Hosts\META_KEY;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * @group groups
 */
class Test_Groups_Event_Hosts extends Groups_TestCase {

	/**
	 * An organizer, who may create and publish events on this group.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Two members of the group, to name as hosts.
	 *
	 * @var int[]
	 */
	private $member_ids;

	/**
	 * Creates the organizer each test acts as, and the members.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->editor_id  = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Org Anizer',
			)
		);
		$this->member_ids = array(
			self::factory()->user->create(
				array(
					'role'         => 'subscriber',
					'display_name' => 'Ana Host',
				)
			),
			self::factory()->user->create(
				array(
					'role'         => 'subscriber',
					'display_name' => 'Bo Host',
				)
			),
		);
	}

	/**
	 * A user who exists on the network but has not joined this group.
	 */
	private function create_non_member(): int {
		$user_id = self::factory()->user->create();
		remove_user_from_blog( $user_id, get_current_blog_id() );

		return $user_id;
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
	 * the `hosts` array is parsed the way a real request's would be.
	 */
	private function event_request( array $params, string $route = '/wporg-groups/v1/event' ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_body_params( $params );
		$request->set_attributes( array( 'args' => \WordCamp\Groups\Frontend\REST\event_args_schema() ) );
		$request->sanitize_params();

		return $request;
	}

	/**
	 * Create a published event as the organizer.
	 *
	 * @param array $extra Params on top of the base ones.
	 */
	private function create_event( array $extra = array() ): int {
		wp_set_current_user( $this->editor_id );

		$response = create_event( $this->event_request( $this->base_event_params() + $extra ) );

		return (int) $response->get_data()['id'];
	}

	/**
	 * The IDs of an event's hosts.
	 *
	 * @param int $event_id Event post ID.
	 */
	private function host_ids( int $event_id ): array {
		return wp_list_pluck( get_event_hosts( $event_id ), 'ID' );
	}

	/**
	 * Only group members are kept, once each, in order, up to the cap.
	 */
	public function test_sanitize_ids_keeps_members_once_in_order() {
		list( $ana, $bo ) = $this->member_ids;

		$this->assertSame(
			array( $bo, $ana ),
			sanitize_ids( array( $bo, (string) $ana, $bo, $this->create_non_member(), 0, -3, 'x', array( $ana ) ) )
		);
		$this->assertSame( array(), sanitize_ids( $ana ) );

		$many = self::factory()->user->create_many( MAX_HOSTS + 2 );
		$this->assertSame( array_slice( $many, 0, MAX_HOSTS ), sanitize_ids( $many ) );
	}

	/**
	 * An event with no hosts stored is hosted by its author, so events from
	 * before hosts existed read the same as they always did.
	 */
	public function test_event_without_hosts_is_hosted_by_its_author() {
		$event_id = $this->create_event();

		$this->assertSame( array( $this->editor_id ), $this->host_ids( $event_id ) );
		$this->assertSame( '', get_post_meta( $event_id, META_KEY, true ) );
	}

	/**
	 * Creating an event stores the hosts in the order given, and leaves the
	 * author, who the permission checks read, alone.
	 */
	public function test_create_event_stores_the_hosts() {
		list( $ana, $bo ) = $this->member_ids;

		$event_id = $this->create_event( array( 'hosts' => array( $bo, $ana ) ) );

		$this->assertSame( array( $bo, $ana ), $this->host_ids( $event_id ) );
		$this->assertSame( $this->editor_id, (int) get_post_field( 'post_author', $event_id ) );
	}

	/**
	 * Someone outside the group can't be named host through the endpoint.
	 */
	public function test_non_member_host_is_dropped() {
		list( $ana ) = $this->member_ids;

		$event_id = $this->create_event( array( 'hosts' => array( $this->create_non_member(), $ana ) ) );

		$this->assertSame( array( $ana ), $this->host_ids( $event_id ) );
	}

	/**
	 * A save that doesn't mention hosts leaves them alone; an empty list
	 * clears them back to the author.
	 */
	public function test_update_keeps_or_clears_the_hosts() {
		list( $ana, $bo ) = $this->member_ids;

		$event_id = $this->create_event( array( 'hosts' => array( $ana, $bo ) ) );

		update_event( $this->event_request( $this->base_event_params() + array( 'id' => $event_id ) ) );
		$this->assertSame( array( $ana, $bo ), $this->host_ids( $event_id ) );

		update_event(
			$this->event_request( $this->base_event_params() + array(
				'id' => $event_id, 'hosts' => array( $bo ),
			) )
		);
		$this->assertSame( array( $bo ), $this->host_ids( $event_id ) );

		update_event(
			$this->event_request( $this->base_event_params() + array(
				'id' => $event_id, 'hosts' => array(),
			) )
		);
		$this->assertSame( array( $this->editor_id ), $this->host_ids( $event_id ) );
		$this->assertSame( '', get_post_meta( $event_id, META_KEY, true ) );
	}

	/**
	 * Drafts keep their hosts too.
	 */
	public function test_save_draft_stores_the_hosts() {
		list( $ana ) = $this->member_ids;

		wp_set_current_user( $this->editor_id );

		$request = new WP_REST_Request( 'POST', '/wporg-groups/v1/draft' );
		$request->set_body_params( array(
			'title' => 'Draft', 'hosts' => array( $ana ),
		) );
		$request->set_attributes( array( 'args' => \WordCamp\Groups\Frontend\REST\draft_args_schema() ) );
		$request->sanitize_params();

		$draft_id = (int) save_draft( $request )->get_data()['id'];

		$this->assertSame( array( $ana ), $this->host_ids( $draft_id ) );
	}

	/**
	 * A host whose account is gone is skipped.
	 */
	public function test_deleted_host_is_skipped() {
		list( , $bo ) = $this->member_ids;

		$event_id = $this->create_event();
		update_post_meta( $event_id, META_KEY, array( PHP_INT_MAX, $bo ) );

		$this->assertSame( array( $bo ), $this->host_ids( $event_id ) );

		update_post_meta( $event_id, META_KEY, array( PHP_INT_MAX ) );

		$this->assertSame( array( $this->editor_id ), $this->host_ids( $event_id ), 'With no host left, the author hosts.' );
	}

	/**
	 * A new event's form starts with its creator as host; an existing
	 * event's form shows its hosts; one started from a template is hosted by
	 * its creator, not the template's hosts.
	 */
	public function test_form_data_hosts() {
		list( $ana ) = $this->member_ids;

		$event_id = $this->create_event( array( 'hosts' => array( $ana ) ) );

		$new = get_event_form_data( new WP_REST_Request( 'GET', '/wporg-groups/v1/event-form-data' ) )->get_data();
		$this->assertSame(
			array(
				array(
					'id'   => $this->editor_id,
					'name' => 'Org Anizer',
					'slug' => get_userdata( $this->editor_id )->user_nicename,
				),
			),
			$new['fields']['hosts']
		);

		$request = new WP_REST_Request( 'GET', '/wporg-groups/v1/event-form-data' );
		$request->set_param( 'event_id', $event_id );
		$this->assertSame( array( $ana ), wp_list_pluck( get_event_form_data( $request )->get_data()['fields']['hosts'], 'id' ) );

		$request = new WP_REST_Request( 'GET', '/wporg-groups/v1/event-form-data' );
		$request->set_param( 'template_id', $event_id );
		$this->assertSame(
			array( $this->editor_id ),
			wp_list_pluck( get_event_form_data( $request )->get_data()['fields']['hosts'], 'id' )
		);
	}

	/**
	 * The event hero lists every host after "Hosted by", linked to their
	 * profile, with the names escaped.
	 */
	public function test_block_lists_every_host() {
		list( $ana, $bo ) = $this->member_ids;

		wp_update_user(
			array(
				'ID'           => $bo,
				'display_name' => 'Bo & Co',
			)
		);

		$event_id = $this->create_event( array( 'hosts' => array( $ana, $bo ) ) );
		wp_set_current_user( 0 );

		$this->go_to( home_url( "?p={$event_id}&post_type=gatherpress_event" ) );
		$output = do_blocks( '<!-- wp:wporg/event-hosts /-->' );

		$this->assertStringContainsString( 'Hosted by', $output );
		$this->assertStringContainsString( '>Ana Host</a> and <a', $output );
		$this->assertStringContainsString( 'Bo &amp; Co', $output );
		$this->assertStringContainsString( 'https://profiles.wordpress.org/' . get_userdata( $ana )->user_nicename . '/', $output );
		$this->assertStringNotContainsString( 'Org Anizer', $output );
		$this->assertSame( 2, substr_count( $output, 'wporg-event-hosts__avatar"' ) );
	}

	/**
	 * Behind the password gate the hero credits the author only, as it did
	 * before hosts existed; the picked hosts wait for the password.
	 */
	public function test_block_shows_only_the_author_behind_the_password() {
		list( $ana ) = $this->member_ids;

		$event_id = $this->create_event( array( 'hosts' => array( $ana ) ) );
		wp_update_post(
			array(
				'ID'            => $event_id,
				'post_password' => 'secret-pass',
			)
		);
		wp_set_current_user( 0 );

		$this->go_to( home_url( "?p={$event_id}&post_type=gatherpress_event" ) );
		$output = do_blocks( '<!-- wp:wporg/event-hosts /-->' );

		$this->assertStringContainsString( 'Org Anizer', $output );
		$this->assertStringNotContainsString( 'Ana Host', $output );
	}
}
