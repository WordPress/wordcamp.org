<?php

namespace WordCamp\Groups\Tests;

use WP_REST_Request;

use function WordCamp\Groups\Frontend\REST\create_event;
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

	private function event_request( array $params ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/wporg-groups/v1/event' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	private function base_event_params(): array {
		return array(
			'title'      => 'Test Event',
			'date'       => '2026-08-15',
			'time_start' => '18:00',
			'time_end'   => '20:00',
		);
	}

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

		$params               = $this->base_event_params();
		$params['date']       = '2026-08-20';
		$params['time_start'] = '22:00';
		$params['time_end']   = '01:00';

		$response = create_event( $this->event_request( $params ) );
		$this->assertNotWPError( $response );

		$event_id = $response->get_data()['id'];
		$end      = get_post_meta( $event_id, 'gatherpress_datetime_end', true );

		$this->assertSame( '2026-08-21 01:00:00', $end );
	}

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

	public function test_venue_address_written_to_meta_not_post_content() {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$params                       = $this->base_event_params();
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

		$venue    = new \GatherPress\Core\Venue\Venue( $venue_post_id );
		$term     = $venue->get_term();
		$this->assertNotNull( $term, 'assign_venue_to_event() should resolve a shadow taxonomy term for the venue.' );
		$this->assertTrue( has_term( $term->term_id, $venue->get_taxonomy(), $event_id ) );
	}

	public function test_featured_image_rejects_unreadable_attachment() {
		$owner_id      = self::factory()->user->create( array( 'role' => 'author' ) );
		$other_author  = self::factory()->user->create( array( 'role' => 'author' ) );

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

	public function test_draft_save_list_update_publish_flow() {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		// Save a partial draft (title only).
		$save_request = new WP_REST_Request( 'POST', '/wporg-groups/v1/draft' );
		$save_request->set_param( 'title', 'Draft Test' );
		$save_response = save_draft( $save_request );
		$draft_id       = $save_response->get_data()['id'];

		$this->assertSame( 'draft', get_post_status( $draft_id ) );

		// List drafts.
		$list_response = list_drafts();
		$listed_ids     = wp_list_pluck( $list_response->get_data(), 'id' );
		$this->assertContains( $draft_id, $listed_ids );

		// Update with full details.
		$update_request = new WP_REST_Request( 'POST', '/wporg-groups/v1/draft/' . $draft_id );
		$update_request->set_param( 'id', $draft_id );
		$update_request->set_param( 'title', 'Draft Test (updated)' );
		$update_request->set_param( 'date', '2026-09-01' );
		$update_request->set_param( 'time_start', '19:00' );
		$update_request->set_param( 'time_end', '21:00' );
		save_draft( $update_request );

		$this->assertSame( 'draft', get_post_status( $draft_id ) );
		$this->assertSame( 'Draft Test (updated)', get_the_title( $draft_id ) );

		// Publish.
		$publish_request = new WP_REST_Request( 'POST', '/wporg-groups/v1/draft/' . $draft_id . '/publish' );
		$publish_request->set_param( 'id', $draft_id );
		$publish_request->set_param( 'title', 'Draft Now Published' );
		$publish_request->set_param( 'date', '2026-09-01' );
		$publish_request->set_param( 'time_start', '19:00' );
		$publish_request->set_param( 'time_end', '21:00' );
		$publish_response = publish_draft( $publish_request );

		$this->assertNotWPError( $publish_response );
		$this->assertSame( 'publish', get_post_status( $draft_id ) );
	}
}
