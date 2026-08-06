<?php

namespace WordCamp\Groups\Frontend\Tests;

use WP_UnitTestCase, WP_REST_Server, WP_REST_Request;

use const WordCamp\Groups\Frontend\REST\NAMESPACE_V1;

use function WordCamp\Groups\Frontend\REST\register_routes;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/wporg-groups-frontend/inc/capabilities.php';
require_once dirname( __DIR__ ) . '/wporg-groups-frontend/inc/group-location.php';
require_once dirname( __DIR__ ) . '/wporg-groups-frontend/inc/rest.php';

/**
 * Tests for the `wporg-groups/v1/group-info` routes.
 *
 * These exist because core's `/wp/v2/settings` gates `blogname` and
 * `blogdescription` behind `manage_options`, which Organisers don't have.
 * The routes hand Organisers write access to those options and the site's
 * location metadata, so the interesting cases are the ones where a write
 * should be refused or existing data should be preserved.
 *
 * @group mu-plugins
 * @group groups-frontend
 */
class Test_Groups_Group_Info_REST extends WP_UnitTestCase {
	const ROUTE = '/' . NAMESPACE_V1 . '/group-info';

	/**
	 * Set up a REST server with this plugin's routes registered.
	 *
	 * The routes go on `rest_api_init` rather than being registered
	 * directly, because core emits a `_doing_it_wrong()` for any route
	 * registered outside that action.
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rest_server;

		$wp_rest_server = new WP_REST_Server();
		add_action( 'rest_api_init', 'WordCamp\Groups\Frontend\REST\register_routes' );
		do_action( 'rest_api_init', $wp_rest_server );

		update_option( 'blogname', 'Warsaw WordPress Group' );
		update_option( 'blogdescription', 'We meet monthly.' );
		delete_site_meta( get_current_blog_id(), 'wporg_group_location_type' );
		delete_site_meta( get_current_blog_id(), 'wporg_group_location_city' );
		delete_site_meta( get_current_blog_id(), 'wporg_group_location_country' );
	}

	/**
	 * Dispatch a request against the group-info route.
	 *
	 * @param string $method HTTP method.
	 * @param array  $data   Body parameters.
	 */
	protected function dispatch( string $method, array $data = array() ) {
		$request = new WP_REST_Request( $method, self::ROUTE );

		foreach ( $data as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * An Organiser (editor) can read the group's name and description.
	 */
	public function test_editor_can_read_group_info() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = $this->dispatch( 'GET' );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'Warsaw WordPress Group', $data['title'] );
		$this->assertSame( 'We meet monthly.', $data['description'] );
		$this->assertNull( $data['location'] );
		$this->assertNotEmpty( $data['countries'] );
		$this->assertContains( 'TR', wp_list_pluck( $data['countries'], 'code' ) );
	}

	/**
	 * An Organiser (editor) can write both fields.
	 */
	public function test_editor_can_update_group_info() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = $this->dispatch(
			'POST',
			array(
				'title'       => 'Kraków WordPress Group',
				'description' => 'Second Tuesday, every month.',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Kraków WordPress Group', get_option( 'blogname' ) );
		$this->assertSame( 'Second Tuesday, every month.', get_option( 'blogdescription' ) );
	}

	/**
	 * A Member (subscriber) can neither read nor write.
	 */
	public function test_subscriber_is_denied() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( 403, $this->dispatch( 'GET' )->get_status() );
		$this->assertSame( 403, $this->dispatch( 'POST', array( 'title' => 'Hijacked' ) )->get_status() );
		$this->assertSame( 'Warsaw WordPress Group', get_option( 'blogname' ) );
	}

	/**
	 * A logged-out request is refused.
	 */
	public function test_logged_out_is_denied() {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->dispatch( 'GET' )->get_status() );
	}

	/**
	 * An empty group name is rejected rather than saved.
	 *
	 * This is the tail of the data-loss path: a failed GET leaves the form
	 * blank, and without this guard the next Save writes those blanks over
	 * the real values, with nothing left to restore them from.
	 */
	public function test_empty_title_is_rejected() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = $this->dispatch( 'POST', array( 'title' => '' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wporg_groups_empty_group_name', $response->get_data()['code'] );
		$this->assertSame( 'Warsaw WordPress Group', get_option( 'blogname' ) );
	}

	/**
	 * A whitespace-only group name is rejected too.
	 */
	public function test_whitespace_only_title_is_rejected() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = $this->dispatch( 'POST', array( 'title' => "  \n\t " ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'Warsaw WordPress Group', get_option( 'blogname' ) );
	}

	/**
	 * A group name that survives the client but not `sanitize_text_field()`
	 * is rejected, not saved as an empty string.
	 *
	 * This is why the emptiness check runs after sanitization: the request
	 * arrives non-empty and only becomes empty on the way in.
	 */
	public function test_title_emptied_by_sanitization_is_rejected() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = $this->dispatch( 'POST', array( 'title' => '<script>alert(1)</script>' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'Warsaw WordPress Group', get_option( 'blogname' ) );
	}

	/**
	 * Markup in the group name is stripped, and what's stored is what's
	 * read back.
	 */
	public function test_title_is_sanitized_and_round_trips() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->dispatch( 'POST', array( 'title' => 'Gdynia <b>WordPress</b> Group' ) );

		$this->assertSame( 'Gdynia WordPress Group', get_option( 'blogname' ) );
		$this->assertSame( 'Gdynia WordPress Group', $this->dispatch( 'GET' )->get_data()['title'] );
	}

	/**
	 * An empty description is allowed: a group may legitimately have no
	 * tagline, and it can be typed back in from this same form.
	 */
	public function test_empty_description_is_allowed() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = $this->dispatch( 'POST', array( 'description' => '' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '', get_option( 'blogdescription' ) );
	}

	/**
	 * Sending one field leaves the other alone.
	 */
	public function test_omitted_field_is_not_clobbered() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->dispatch( 'POST', array( 'description' => 'Now quarterly.' ) );

		$this->assertSame( 'Warsaw WordPress Group', get_option( 'blogname' ) );
		$this->assertSame( 'Now quarterly.', get_option( 'blogdescription' ) );
	}

	/**
	 * An Organiser can save a physical group location.
	 */
	public function test_editor_can_save_physical_location() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = $this->dispatch(
			'POST',
			array(
				'location' => array(
					'type'        => 'physical',
					'city'        => 'İstanbul',
					'countryCode' => 'tr',
				),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'type'        => 'physical',
				'city'        => 'İstanbul',
				'countryCode' => 'TR',
			),
			$response->get_data()['location']
		);
		$this->assertSame( 'physical', get_site_meta( get_current_blog_id(), 'wporg_group_location_type', true ) );
		$this->assertSame( 'İstanbul', get_site_meta( get_current_blog_id(), 'wporg_group_location_city', true ) );
		$this->assertSame( 'TR', get_site_meta( get_current_blog_id(), 'wporg_group_location_country', true ) );
	}

	/**
	 * Online is a complete group location without platform details.
	 */
	public function test_editor_can_save_online_location() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		update_site_meta( get_current_blog_id(), 'wporg_group_location_city', 'Warsaw' );
		update_site_meta( get_current_blog_id(), 'wporg_group_location_country', 'PL' );

		$response = $this->dispatch(
			'POST',
			array( 'location' => array( 'type' => 'online' ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'type' => 'online' ), $response->get_data()['location'] );
		$this->assertSame( 'online', get_site_meta( get_current_blog_id(), 'wporg_group_location_type', true ) );
		$this->assertSame( '', get_site_meta( get_current_blog_id(), 'wporg_group_location_city', true ) );
		$this->assertSame( '', get_site_meta( get_current_blog_id(), 'wporg_group_location_country', true ) );
	}

	/**
	 * Null clears all location metadata and restores the unspecified state.
	 */
	public function test_editor_can_clear_location() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		update_site_meta( get_current_blog_id(), 'wporg_group_location_type', 'physical' );
		update_site_meta( get_current_blog_id(), 'wporg_group_location_city', 'Warsaw' );
		update_site_meta( get_current_blog_id(), 'wporg_group_location_country', 'PL' );

		$response = $this->dispatch( 'POST', array( 'location' => null ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( $response->get_data()['location'] );
		$this->assertSame( '', get_site_meta( get_current_blog_id(), 'wporg_group_location_type', true ) );
		$this->assertSame( '', get_site_meta( get_current_blog_id(), 'wporg_group_location_city', true ) );
		$this->assertSame( '', get_site_meta( get_current_blog_id(), 'wporg_group_location_country', true ) );
	}

	/**
	 * Physical locations require a city and a recognized country.
	 */
	public function test_incomplete_physical_location_is_rejected_without_partial_updates() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = $this->dispatch(
			'POST',
			array(
				'title'    => 'This should not be saved',
				'location' => array(
					'type'        => 'physical',
					'city'        => '',
					'countryCode' => 'PL',
				),
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wporg_groups_incomplete_location', $response->get_data()['code'] );
		$this->assertSame( 'Warsaw WordPress Group', get_option( 'blogname' ) );
		$this->assertNull( $this->dispatch( 'GET' )->get_data()['location'] );
	}

	/**
	 * Omitting location leaves existing location metadata untouched.
	 */
	public function test_omitted_location_is_not_clobbered() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		update_site_meta( get_current_blog_id(), 'wporg_group_location_type', 'online' );

		$this->dispatch( 'POST', array( 'description' => 'Now quarterly.' ) );

		$this->assertSame( array( 'type' => 'online' ), $this->dispatch( 'GET' )->get_data()['location'] );
	}
}
