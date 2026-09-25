<?php

namespace WordCamp\WC_Post_Types\Tests;
use WP_UnitTestCase, WP_REST_Request;

defined( 'WPINC' ) || die();

/**
 * Front-end exposure of the Volunteer post type.
 *
 * `wcb_volunteer` was registered as non-public, which kept it out of the Query Loop block and left organisers
 * with no way to list volunteers on the site (see #1282). Making it public is only safe as long as the
 * contact details stay behind the `edit` context, so both halves are asserted here.
 *
 * @group wc-post-types
 * @group rest-api
 */
class Test_Volunteer_Public extends WP_UnitTestCase {
	/**
	 * Register the REST routes and fields once for the whole class.
	 */
	public static function wpSetUpBeforeClass(): void {
		do_action( 'rest_api_init' );
	}

	/**
	 * Create a published volunteer linked to a wp.org account.
	 *
	 * @return int The volunteer post ID.
	 */
	private function create_volunteer(): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'wcb_volunteer',
				'post_status' => 'publish',
				'post_title'  => 'Test Volunteer',
			)
		);
	}

	/**
	 * Dispatch a REST request for a single volunteer and return the response data.
	 *
	 * @param int    $post_id The volunteer post ID.
	 * @param string $context The REST context to request.
	 *
	 * @return array The response data.
	 */
	private function get_volunteer_response( int $post_id, string $context = 'view' ): array {
		$request = new WP_REST_Request( 'GET', '/wp/v2/volunteers/' . $post_id );
		$request->set_param( 'context', $context );

		return rest_get_server()->dispatch( $request )->get_data();
	}

	/**
	 * The Query Loop block only offers post types that `is_post_type_viewable()` accepts.
	 */
	public function test_volunteer_post_type_is_viewable(): void {
		$this->assertTrue( is_post_type_viewable( get_post_type_object( 'wcb_volunteer' ) ) );
	}

	/**
	 * Volunteers are served from the same style of route as the sibling participant post types.
	 */
	public function test_volunteer_rest_route_is_registered(): void {
		$this->assertArrayHasKey( '/wp/v2/volunteers', rest_get_server()->get_routes() );
	}

	/**
	 * The `wordcamp/avatar` block inside the Volunteers List variation reads `avatar_urls`.
	 */
	public function test_volunteer_response_includes_avatar_urls(): void {
		$data = $this->get_volunteer_response( $this->create_volunteer() );

		$this->assertArrayHasKey( 'avatar_urls', $data );

		foreach ( rest_get_avatar_sizes() as $size ) {
			$this->assertArrayHasKey( $size, $data['avatar_urls'] );
		}
	}

	/**
	 * Making the post type public must not turn the volunteer's contact details into public data.
	 */
	public function test_volunteer_contact_meta_is_not_exposed_to_anonymous_readers(): void {
		$post_id = $this->create_volunteer();

		update_post_meta( $post_id, '_wcb_volunteer_email', 'volunteer@example.org' );
		update_post_meta( $post_id, '_wcb_volunteer_first_time', 'yes' );

		wp_set_current_user( 0 );
		$data = $this->get_volunteer_response( $post_id );
		$meta = $data['meta'] ?? array();

		$this->assertArrayNotHasKey( '_wcb_volunteer_email', $meta );
		$this->assertArrayNotHasKey( '_wcb_volunteer_first_time', $meta );
		$this->assertStringNotContainsString( 'volunteer@example.org', wp_json_encode( $data ) );
	}
}
