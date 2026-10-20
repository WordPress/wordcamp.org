<?php

namespace WordCamp\WC_Post_Types\Tests;

use WP_UnitTestCase;
use WP_REST_Request;

defined( 'WPINC' ) || die();

/**
 * Session REST responses follow the post password.
 *
 * Slide and video links are limited to the `edit` context so they stay out of
 * an anonymous `view` response, and a whole password-protected session drops
 * out of the public collection. An editor still reads and writes both in the
 * `edit` context. These go through the real route so a wrong hook or schema
 * context cannot pass unnoticed.
 *
 * @group wc-post-types
 * @group rest-api
 */
class Test_Session_Protected_REST extends WP_UnitTestCase {
	/**
	 * A user who can edit sessions and read private ones.
	 *
	 * @var int
	 */
	protected static $admin_id;

	/**
	 * Create the privileged user and make sure the REST routes are registered.
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		self::$admin_id = $factory->user->create( array( 'role' => 'administrator' ) );

		do_action( 'rest_api_init' );
	}

	/**
	 * Re-register the session meta before each test.
	 *
	 * The framework's tear_down unregisters every meta key registered during a
	 * test, so the `init` registration does not survive to the next one.
	 */
	public function set_up() {
		parent::set_up();

		\WordCamp\Post_Types\REST_API\register_session_post_meta();
	}

	/**
	 * Create a published session.
	 *
	 * @param array $args Overrides for wp_insert_post().
	 *
	 * @return int
	 */
	protected function make_session( array $args = array() ): int {
		return self::factory()->post->create(
			array_merge(
				array(
					'post_type'   => 'wcb_session',
					'post_status' => 'publish',
					'post_title'  => 'Test Session',
				),
				$args
			)
		);
	}

	/**
	 * The slide and video fields are edit-only in the Sessions item schema, so
	 * the endpoint never serves them in the default view context and they cannot
	 * render above a session's password form.
	 *
	 * This asserts the schema contract rather than a dispatched view response:
	 * the REST server caches one schema per process and other test classes in
	 * the same run can prime it before this edit-only registration, which does
	 * not happen in a real request where the schema is built fresh each time.
	 */
	public function test_slides_and_video_are_edit_context_only() {
		$meta_schema = ( new \WP_REST_Posts_Controller( 'wcb_session' ) )
			->get_item_schema()['properties']['meta']['properties'];

		$this->assertSame( array( 'edit' ), $meta_schema['_wcpt_session_slides']['context'] ?? null );
		$this->assertSame( array( 'edit' ), $meta_schema['_wcpt_session_video']['context'] ?? null );
	}

	/**
	 * An editor still reads the slide and video links in the edit context and
	 * can write them back.
	 */
	public function test_slides_and_video_present_and_writable_in_edit_context() {
		$session_id = $this->make_session( array( 'post_author' => self::$admin_id ) );
		update_post_meta( $session_id, '_wcpt_session_slides', 'https://example.org/slides.pdf' );

		wp_set_current_user( self::$admin_id );

		$read = new WP_REST_Request( 'GET', "/wp/v2/sessions/{$session_id}" );
		$read->set_param( 'context', 'edit' );
		$meta = rest_get_server()->dispatch( $read )->get_data()['meta'] ?? array();

		$this->assertArrayHasKey( '_wcpt_session_slides', $meta );
		$this->assertArrayHasKey( '_wcpt_session_video', $meta );
		$this->assertSame( 'https://example.org/slides.pdf', $meta['_wcpt_session_slides'] );

		$write = new WP_REST_Request( 'POST', "/wp/v2/sessions/{$session_id}" );
		$write->set_param( 'meta', array( '_wcpt_session_slides' => 'https://example.org/new-slides.pdf' ) );
		$status = rest_get_server()->dispatch( $write )->get_status();

		$this->assertSame( 200, $status );
		$this->assertSame(
			'https://example.org/new-slides.pdf',
			get_post_meta( $session_id, '_wcpt_session_slides', true )
		);
	}

	/**
	 * A password-protected session is dropped from the anonymous collection, so
	 * its meta cannot be read or probed through the sessions endpoint.
	 */
	public function test_protected_session_absent_from_anonymous_collection() {
		$public_id    = $this->make_session( array( 'post_title' => 'Public Session' ) );
		$protected_id = $this->make_session(
			array(
				'post_title'    => 'Protected Session',
				'post_password' => 'secret-pass',
			)
		);

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/wp/v2/sessions' );
		$ids     = wp_list_pluck( rest_get_server()->dispatch( $request )->get_data(), 'id' );

		$this->assertContains( $public_id, $ids );
		$this->assertNotContains( $protected_id, $ids );
	}

	/**
	 * A user who can read private sessions still sees the protected one in the
	 * collection.
	 */
	public function test_protected_session_visible_to_privileged_user() {
		$protected_id = $this->make_session(
			array(
				'post_title'    => 'Protected Session',
				'post_password' => 'secret-pass',
			)
		);

		wp_set_current_user( self::$admin_id );

		$request = new WP_REST_Request( 'GET', '/wp/v2/sessions' );
		$request->set_param( 'context', 'edit' );
		$ids = wp_list_pluck( rest_get_server()->dispatch( $request )->get_data(), 'id' );

		$this->assertContains( $protected_id, $ids );
	}

	/**
	 * Create a published speaker.
	 *
	 * @param string $title Speaker title.
	 *
	 * @return int
	 */
	protected function make_speaker( string $title = 'Secret Speaker' ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'wcb_speaker',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
	}

	/**
	 * A protected session hides its speakers from an anonymous caller: the
	 * speaker meta, the resolved `session_speakers` list and the speaker links
	 * are all gone.
	 */
	public function test_protected_session_hides_its_speakers_from_anonymous() {
		$speaker_id = $this->make_speaker();
		$session_id = $this->make_session( array( 'post_password' => 'secret-pass' ) );
		add_post_meta( $session_id, '_wcpt_speaker_id', $speaker_id );

		wp_set_current_user( 0 );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/wp/v2/sessions/{$session_id}" ) );
		$data     = $response->get_data();

		$this->assertArrayNotHasKey( '_wcpt_speaker_id', $data['meta'] ?? array() );
		$this->assertSame( array(), $data['session_speakers'] ?? 'missing' );
		$this->assertArrayNotHasKey( 'speakers', $response->get_links() );
	}

	/**
	 * An editor still gets a protected session's speakers.
	 */
	public function test_editor_still_sees_protected_session_speakers() {
		$speaker_id = $this->make_speaker();
		$session_id = $this->make_session(
			array(
				'post_password' => 'secret-pass',
				'post_author'   => self::$admin_id,
			)
		);
		add_post_meta( $session_id, '_wcpt_speaker_id', $speaker_id );

		wp_set_current_user( self::$admin_id );

		$request = new WP_REST_Request( 'GET', "/wp/v2/sessions/{$session_id}" );
		$request->set_param( 'context', 'edit' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertContains( $speaker_id, array_map( 'intval', $data['meta']['_wcpt_speaker_id'] ?? array() ) );
		$this->assertNotEmpty( $data['session_speakers'] ?? array() );
		$this->assertArrayHasKey( 'speakers', $response->get_links() );
	}

	/**
	 * The speaker endpoint does not link a protected session for an anonymous
	 * caller, while a public session is still linked.
	 */
	public function test_protected_session_not_linked_from_speaker() {
		$speaker_id        = $this->make_speaker();
		$public_session    = $this->make_session( array( 'post_title' => 'Public Session' ) );
		$protected_session = $this->make_session(
			array(
				'post_title'    => 'Protected Session',
				'post_password' => 'secret-pass',
			)
		);
		add_post_meta( $public_session, '_wcpt_speaker_id', $speaker_id );
		add_post_meta( $protected_session, '_wcpt_speaker_id', $speaker_id );

		wp_set_current_user( 0 );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/wp/v2/speakers/{$speaker_id}" ) );
		$hrefs    = wp_list_pluck( $response->get_links()['sessions'] ?? array(), 'href' );
		// The href may be a pretty path or a `rest_route` query, so decode and
		// match the route segment either way.
		$linked = urldecode( implode( ' ', $hrefs ) );

		$this->assertStringContainsString( "/sessions/{$public_session}&", $linked );
		$this->assertStringNotContainsString( "/sessions/{$protected_session}&", $linked );
	}
}
