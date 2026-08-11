<?php
/**
 * Asserts every GatherPress class/method/constant/property this extension
 * calls directly still exists, with the expected shape.
 *
 * @package WordPressdotorg\GatherPress_Recurring_Events\Tests
 */

namespace WordPressdotorg\GatherPress_Recurring_Events\Tests;

use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * This is the class of test that would have caught the GATHERPRESS_CACHE_GROUP
 * bug faster if the mistake had instead been calling a real-but-wrong
 * GatherPress method or constant: a class_exists() guard (what the plugin
 * itself uses to decide whether to bootstrap at all) passes as long as the
 * class is real, even when the specific method/constant/property this
 * integration actually calls has been renamed or removed. Nothing short of
 * checking the exact member against the exact class catches that — see the
 * `groups-gatherpress-compat-test` skill, section 8/9, which documents this
 * exact pattern (and the wrong-class bug, #1874, it was written to prevent)
 * for the sibling Groups integration.
 *
 * Keep this list in sync with what the extension actually calls — grep
 * `GatherPress\\Core` across `plugins/gatherpress-recurring-events/includes`
 * to find anything this list is missing. A method/constant/property this
 * list doesn't cover isn't protected by this test — the coverage is only as
 * good as the list.
 *
 * @group gatherpress-recurring-events
 */
final class Test_GatherPress_Api_Contract extends WP_UnitTestCase {

	/**
	 * Every class this extension imports, and the methods/constants/
	 * properties actually called on it (including `save_datetimes()`,
	 * which only this test suite's own fixtures call, to seed a real
	 * GatherPress event date the same way GatherPress itself would).
	 */
	const CONTRACT = array(
		'GatherPress\Core\Event\Event'          => array(
			'methods'    => array( 'has_event_past', 'maybe_get_online_event_link', 'save_datetimes' ),
			'properties' => array( 'rsvp' ),
		),
		'GatherPress\Core\Rsvp\Rsvp'            => array(
			'methods' => array( 'save', 'responses' ),
		),
		'GatherPress\Core\Rsvp\Cache'           => array(
			'methods' => array( 'get', 'set', 'delete' ),
		),
		'GatherPress\Core\Rsvp\Response\Status' => array(
			'methods' => array( 'values' ),
		),
		'GatherPress\Core\Calendar\Calendar'    => array(
			'methods' => array( 'get_ical_event_string' ),
		),
		'GatherPress\Core\Utility'              => array(
			'methods' => array( 'ensure_user_authentication' ),
		),
	);

	/**
	 * @dataProvider provide_contract_classes
	 */
	public function test_class_exists( string $class ) {
		$this->assertTrue(
			class_exists( $class ),
			"Expected class {$class} to exist — plugin.php guards its own bootstrap with a class_exists() check, so this failing means the extension is silently going dormant, not fataling."
		);
	}

	/**
	 * @dataProvider provide_contract_methods
	 */
	public function test_method_exists( string $class, string $method ) {
		$this->assertTrue(
			method_exists( $class, $method ),
			"Expected {$class}::{$method}() to exist. If GatherPress renamed or removed it, find every call site with `grep -rn '{$method}(' public_html/wp-content/plugins/gatherpress-recurring-events/includes` and update them."
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

	/** Data provider: one case per class in CONTRACT. */
	public function provide_contract_classes(): array {
		$cases = array();
		foreach ( array_keys( self::CONTRACT ) as $class ) {
			$cases[ $class ] = array( $class );
		}
		return $cases;
	}

	/** Data provider: one case per class/method pair in CONTRACT. */
	public function provide_contract_methods(): array {
		$cases = array();
		foreach ( self::CONTRACT as $class => $members ) {
			foreach ( $members['methods'] ?? array() as $method ) {
				$cases[ "{$class}::{$method}" ] = array( $class, $method );
			}
		}
		return $cases;
	}

	/** Data provider: one case per class/constant pair in CONTRACT. */
	public function provide_contract_constants(): array {
		$cases = array();
		foreach ( self::CONTRACT as $class => $members ) {
			foreach ( $members['constants'] ?? array() as $constant ) {
				$cases[ "{$class}::{$constant}" ] = array( $class, $constant );
			}
		}
		return $cases;
	}

	/** Data provider: one case per class/property pair in CONTRACT. */
	public function provide_contract_properties(): array {
		$cases = array();
		foreach ( self::CONTRACT as $class => $members ) {
			foreach ( $members['properties'] ?? array() as $property ) {
				$cases[ "{$class}::\${$property}" ] = array( $class, $property );
			}
		}
		return $cases;
	}
}
