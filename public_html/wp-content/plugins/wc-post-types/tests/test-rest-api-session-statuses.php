<?php

namespace WordCamp\WC_Post_Types\Tests;

use WP_REST_Request;
use WP_UnitTestCase;
use function WordCamp\Post_Types\REST_API\prepare_session_query_args;

defined( 'WPINC' ) || die();

/**
 * Tests for the post statuses `prepare_session_query_args()` asks WP_Query for.
 *
 * The REST controller filters unreadable posts out of the response body, but
 * `found_posts` is taken from the query, so a status the caller can't read
 * still lands in `X-WP-Total` and the reported page count.
 *
 * @group wc-post-types
 * @group rest-api
 */
class Test_Session_Query_Statuses extends WP_UnitTestCase {
	/**
	 * Run the filter the way the REST controller does, and return the statuses.
	 *
	 * @return array
	 */
	private function get_query_statuses(): array {
		$request = new WP_REST_Request( 'GET', '/wp/v2/sessions' );
		$args    = prepare_session_query_args( array(), $request );

		return (array) $args['post_status'];
	}

	/**
	 * Test that a logged out caller is only asked about published sessions.
	 */
	public function test_logged_out_gets_published_only(): void {
		wp_set_current_user( 0 );

		$this->assertSame( array( 'publish' ), $this->get_query_statuses() );
	}

	/**
	 * Test that a user without read_private_posts is only asked about published sessions.
	 */
	public function test_subscriber_gets_published_only(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( array( 'publish' ), $this->get_query_statuses() );
	}

	/**
	 * Test that a user with read_private_posts is asked about private sessions too.
	 */
	public function test_editor_gets_private_sessions(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertContains( 'private', $this->get_query_statuses() );
	}
}
