<?php

namespace WordCamp\Groups\Tests;

use function WordCamp\Groups\Front_End_Locale\use_visitor_locale;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__, 2 ) . '/wporg-groups-frontend/tests/class-groups-testcase.php';

/**
 * Serving the group front end in the visitor's own language.
 *
 * WordPress reads a profile language in wp-admin and nowhere else, so a
 * translator who set theirs to Spanish saw Spanish in wp-admin and English on
 * every public group page, which is what #2038 reported. These pin the three
 * things that decision turns on: who is asking, where they are asking, and
 * whether they ever chose a language.
 *
 * @group groups
 */
class Test_Groups_Front_End_Locale extends Groups_TestCase {

	const SITE_LOCALE = 'en_US';

	/**
	 * The screen this suite runs on, restored after each test.
	 *
	 * @var \WP_Screen|null
	 */
	private $previous_screen = null;

	/**
	 * This bootstrap defines `WP_ADMIN`, so without a front-end screen every
	 * test here would take the wp-admin branch and none would be testing the
	 * thing that was broken.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->previous_screen = $GLOBALS['current_screen'] ?? null;

		set_current_screen( 'front' );
	}

	/**
	 * Put the screen back so a front-end screen doesn't leak into the rest of
	 * the suite.
	 */
	protected function tearDown(): void {
		if ( null === $this->previous_screen ) {
			unset( $GLOBALS['current_screen'] );
		} else {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the screen this test replaced.
			$GLOBALS['current_screen'] = $this->previous_screen;
		}

		parent::tearDown();
	}

	/**
	 * A member who chose a language gets it on a public group page. This is
	 * the whole of #2038.
	 */
	public function test_a_visitor_with_a_language_gets_it_on_the_front_end() {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'locale', 'es_ES' );
		wp_set_current_user( $user_id );

		$this->assertSame( 'es_ES', use_visitor_locale( self::SITE_LOCALE ) );
	}

	/**
	 * Most people never touch the setting. `get_user_locale()` reports the
	 * site locale for them, so the filter has to be a no-op rather than
	 * something that pins them to a language they didn't pick.
	 */
	public function test_a_visitor_without_a_language_keeps_the_site_locale() {
		wp_set_current_user( self::factory()->user->create() );

		$this->assertSame( self::SITE_LOCALE, use_visitor_locale( self::SITE_LOCALE ) );
	}

	/**
	 * Anonymous visitors have no language to follow, and deliberately aren't
	 * matched against `Accept-Language`: their locale would have to become
	 * part of the page cache key, which is a separate decision.
	 */
	public function test_an_anonymous_visitor_keeps_the_site_locale() {
		wp_set_current_user( 0 );

		$this->assertSame( self::SITE_LOCALE, use_visitor_locale( self::SITE_LOCALE ) );
	}

	/**
	 * The admin is core's own business, and it already resolves the user's
	 * locale in wp-admin. Stepping in would at best duplicate that and at
	 * worst disagree with it.
	 */
	public function test_wp_admin_is_left_to_core() {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'locale', 'es_ES' );
		wp_set_current_user( $user_id );

		set_current_screen( 'dashboard' );

		$this->assertTrue( is_admin(), 'This test needs an admin screen to mean anything.' );
		$this->assertSame( self::SITE_LOCALE, use_visitor_locale( self::SITE_LOCALE ) );
	}

	/**
	 * The filter is actually hooked. Every assertion above calls the function
	 * directly, which would keep passing if the `add_filter()` line were
	 * dropped and the front end quietly went back to English.
	 *
	 * The REST API is guarded the same way as wp-admin, in
	 * `should_follow_visitor()`. It isn't asserted here because proving it
	 * means defining `REST_REQUEST`, which can't be undefined again and would
	 * follow this process into every test that runs after it.
	 */
	public function test_the_filter_is_registered() {
		$this->assertNotFalse(
			has_filter( 'determine_locale', 'WordCamp\\Groups\\Front_End_Locale\\use_visitor_locale' ),
			'The front end will not follow the visitor without this hook.'
		);
	}
}
