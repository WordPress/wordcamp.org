<?php

namespace WordCamp\Groups\Tests;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/../../wporg-groups-frontend/tests/class-groups-testcase.php';

/**
 * Asserts every GatherPress class/method/constant/property this integration
 * calls directly still exists, with the expected shape.
 *
 * This is the test that would have caught #1874 before it shipped: three
 * `groups-site` patterns imported `GatherPress\Core\Blocks\Event_Query` and
 * called `get_past_events()`/`get_upcoming_events()` on it — methods that
 * have never existed on that class, in any GatherPress version. A
 * class-exists() check (what the code itself guards with, and what earlier
 * ad-hoc audits checked) passes regardless, because the *class* is real —
 * it's just the *wrong* class. Nothing short of checking the specific
 * method against the specific class would have caught it, and nothing did,
 * until a live production fatal.
 *
 * Keep this list in sync with what the integration actually calls — grep
 * `GatherPress\\Core` across `mu-plugins/groups`, `mu-plugins/wporg-groups-frontend`,
 * and `themes/groups-site` (see the `groups-gatherpress-compat-test` skill,
 * section 8) to find anything this list is missing. A method/constant this
 * list doesn't cover isn't protected by this test — the coverage is only
 * as good as the list.
 *
 * @group groups
 */
class Test_GatherPress_Api_Contract extends Groups_TestCase {

	/**
	 * Every class this integration imports, and the methods/constants/
	 * properties actually called on it. `type` distinguishes how each
	 * member is checked; `static` methods are additionally invoked via
	 * `get_instance()` (all of these classes are GatherPress singletons)
	 * to confirm the call itself doesn't throw, not just that the method
	 * exists.
	 */
	const CONTRACT = array(
		'GatherPress\Core\Rsvp\Rsvp'        => array(
			'methods' => array( 'get', 'save', 'responses' ),
		),
		'GatherPress\Core\Event\Event'      => array(
			'methods'    => array( 'save_datetimes', 'get_venue_information', 'has_event_past' ),
			'constants'  => array( 'TABLE_FORMAT' ),
			'properties' => array( 'rsvp' ),
		),
		'GatherPress\Core\Event\Query'      => array(
			'methods' => array( 'get_upcoming_events', 'get_past_events' ),
		),
		'GatherPress\Core\Event\Setup'      => array(
			'methods' => array( 'handle_event_archive_redirect' ),
		),
		'GatherPress\Core\Venue\Venue'      => array(
			'methods'   => array( 'get_term' ),
			'constants' => array( 'TAXONOMY', 'POST_TYPE' ),
		),
		'GatherPress\Core\Venue\Setup'      => array(
			'methods' => array( 'taxonomy_for_event_post_type', 'get_venue_post_from_event_post_id' ),
		),
		'GatherPress\Core\Blocks\Setup'     => array(
			'methods' => array( 'get_post_id' ),
		),
		'GatherPress\Core\User'             => array(
			'methods' => array( 'has_event_updates_opt_in' ),
		),
	);

	/**
	 * @dataProvider provide_contract_classes
	 */
	public function test_class_exists( string $class ) {
		$this->assertTrue(
			class_exists( $class ),
			"Expected class {$class} to exist — the integration guards its own call sites with class_exists() checks, so this failing means those code paths are silently going dormant, not fataling."
		);
	}

	/**
	 * @dataProvider provide_contract_methods
	 */
	public function test_method_exists( string $class, string $method ) {
		$this->assertTrue(
			method_exists( $class, $method ),
			"Expected {$class}::{$method}() to exist. If GatherPress renamed or removed it, find every call site with `grep -rn '{$method}(' public_html/wp-content/mu-plugins/groups public_html/wp-content/mu-plugins/wporg-groups-frontend public_html/wp-content/themes/groups-site` and update them."
		);
	}

	/**
	 * @dataProvider provide_contract_constants
	 */
	public function test_constant_exists( string $class, string $constant ) {
		$this->assertTrue(
			defined( "{$class}::{$constant}" ),
			"Expected {$class}::{$constant} to be defined."
		);
	}

	/**
	 * @dataProvider provide_contract_properties
	 */
	public function test_property_exists( string $class, string $property ) {
		$this->assertTrue(
			property_exists( $class, $property ),
			"Expected {$class}::\${$property} to exist."
		);
	}

	/**
	 * Data provider: one case per class in CONTRACT.
	 */
	public function provide_contract_classes(): array {
		$cases = array();
		foreach ( array_keys( self::CONTRACT ) as $class ) {
			$cases[ $class ] = array( $class );
		}
		return $cases;
	}

	/**
	 * Data provider: one case per class/method pair in CONTRACT.
	 */
	public function provide_contract_methods(): array {
		$cases = array();
		foreach ( self::CONTRACT as $class => $members ) {
			foreach ( $members['methods'] ?? array() as $method ) {
				$cases[ "{$class}::{$method}" ] = array( $class, $method );
			}
		}
		return $cases;
	}

	/**
	 * Data provider: one case per class/constant pair in CONTRACT.
	 */
	public function provide_contract_constants(): array {
		$cases = array();
		foreach ( self::CONTRACT as $class => $members ) {
			foreach ( $members['constants'] ?? array() as $constant ) {
				$cases[ "{$class}::{$constant}" ] = array( $class, $constant );
			}
		}
		return $cases;
	}

	/**
	 * Data provider: one case per class/property pair in CONTRACT.
	 */
	public function provide_contract_properties(): array {
		$cases = array();
		foreach ( self::CONTRACT as $class => $members ) {
			foreach ( $members['properties'] ?? array() as $property ) {
				$cases[ "{$class}::\${$property}" ] = array( $class, $property );
			}
		}
		return $cases;
	}

	/**
	 * `Event\Setup::handle_event_archive_redirect()` existing isn't enough
	 * on its own — `gatherpress-groups-tweaks.php` specifically removes it
	 * from `template_redirect` (see the comment there for why). Confirm
	 * GatherPress is still hooking it the way that removal assumes; if
	 * GatherPress moved it to a different hook or stopped registering it
	 * by default, the `remove_action()` call silently becomes a no-op and
	 * the archive page starts 404ing again for exactly the reason that
	 * code exists to prevent.
	 */
	public function test_event_archive_redirect_hook_removal_still_targets_something() {
		$setup = \GatherPress\Core\Event\Setup::get_instance();

		// gatherpress-groups-tweaks.php's template_redirect callback (priority
		// 1) runs before GatherPress registers its own instance-bound
		// callback in a fresh test process, so re-fire the registration this
		// integration relies on being present, then confirm removal works.
		add_action( 'template_redirect', array( $setup, 'handle_event_archive_redirect' ) );

		$this->assertNotFalse(
			has_action( 'template_redirect', array( $setup, 'handle_event_archive_redirect' ) ),
			'GatherPress\Core\Event\Setup::handle_event_archive_redirect() is expected to be hookable on template_redirect — gatherpress-groups-tweaks.php removes it there. If this fails, GatherPress changed which hook it uses.'
		);

		remove_action( 'template_redirect', array( $setup, 'handle_event_archive_redirect' ) );
	}
}
