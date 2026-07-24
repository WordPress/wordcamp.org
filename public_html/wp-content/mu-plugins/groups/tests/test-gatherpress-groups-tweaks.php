<?php

namespace WordCamp\Groups\Tests;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__, 2 ) . '/wporg-groups-frontend/tests/class-groups-testcase.php';

/**
 * @group groups
 */
class Test_Groups_GatherPress_Tweaks extends Groups_TestCase {

	public function test_gatherpress_settings_overridden() {
		$settings = get_option( 'gatherpress_settings' );

		$this->assertSame( 0, $settings['show_timezone'] );
		$this->assertSame( 0, $settings['enable_anonymous_rsvp'] );
	}

	public function test_comment_registration_required_on_groups_network() {
		$this->assertSame( '1', get_option( 'comment_registration' ) );
	}

	/**
	 * Editors ("Organisers") are granted `edit_theme_options` so they can use
	 * the Site Editor to customise their group site — but nothing broader.
	 * See the `promote_users` regression test in test-capabilities.php for
	 * the capability that must NOT be granted this way.
	 */
	public function test_editor_has_edit_theme_options() {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->assertTrue( user_can( $editor_id, 'edit_theme_options' ) );
	}

	public function test_subscriber_does_not_have_edit_theme_options() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertFalse( user_can( $subscriber_id, 'edit_theme_options' ) );
	}

	/**
	 * `_event_speakers` is registered on `init`, which only fires once for
	 * the whole test run; `WP_UnitTestCase::tearDown()` unregisters all meta
	 * keys after every test (a known core testing quirk), so by the time
	 * this test runs the registration from bootstrap is long gone. Re-fire
	 * just this one `init` callback's effect directly (rather than
	 * `do_action( 'init' )`, which would also re-run block registration and
	 * trip "already registered" `_doing_it_wrong` notices) — this must stay
	 * in sync with the real registration in `gatherpress-groups-tweaks.php`.
	 */
	public function test_event_speakers_meta_registered_with_array_default() {
		register_post_meta(
			'gatherpress_event',
			'_event_speakers',
			array(
				'type'         => 'array',
				'single'       => true,
				'default'      => array(),
				'show_in_rest' => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
				),
			)
		);

		$registered = get_registered_meta_keys( 'post', 'gatherpress_event' );

		$this->assertArrayHasKey( '_event_speakers', $registered );
		$this->assertSame( array(), $registered['_event_speakers']['default'] );
		$this->assertSame( 'array', $registered['_event_speakers']['type'] );
	}

	/**
	 * Venues are metadata on events, not their own front-end destination —
	 * confirm the post type stays non-public even though GatherPress itself
	 * registers it.
	 */
	public function test_gatherpress_venue_post_type_is_non_public() {
		$post_type_object = get_post_type_object( 'gatherpress_venue' );

		$this->assertNotNull( $post_type_object, 'GatherPress must be active for this assertion to be meaningful.' );
		$this->assertFalse( $post_type_object->public );
		$this->assertFalse( $post_type_object->publicly_queryable );
		$this->assertFalse( $post_type_object->has_archive );
	}
}
