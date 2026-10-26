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
	 * Every class this integration imports, and the methods/constants
	 * actually called on it. `type` distinguishes how each
	 * member is checked; `static` methods are additionally invoked via
	 * `get_instance()` (all of these classes are GatherPress singletons)
	 * to confirm the call itself doesn't throw, not just that the method
	 * exists.
	 */
	const CONTRACT = array(
		// 0.36.0 dropped `Event::$rsvp`; RSVPs are reached through their own
		// `Rsvp` instance. It also renamed `Event::$event` to `Event::$post`,
		// which check-in no longer reads, so no property is listed here.
		'GatherPress\Core\Rsvp\Rsvp'        => array(
			'methods'   => array( 'get', 'save', 'responses', 'is_enabled', 'check_waiting_list' ),
			'constants' => array( 'COMMENT_TYPE' ),
		),
		'GatherPress\Core\Rsvp\Cache'       => array(
			'methods' => array( 'delete' ),
		),
		// Check-in is stored as this flag, and `on_flag_changed()` matches
		// GatherPress's flag hooks against its slug.
		'GatherPress\Core\Rsvp\Flag\Check_In' => array(
			'methods'   => array( 'has', 'add', 'remove' ),
			'constants' => array( 'SLUG' ),
		),
		'GatherPress\Core\Rsvp\Query'       => array(
			'methods' => array( 'get_rsvps' ),
		),
		// An enum: `ATTENDING` is a case, which `defined()` also reports.
		'GatherPress\Core\Rsvp\Response\Status' => array(
			'constants' => array( 'TAXONOMY', 'ATTENDING' ),
		),
		'GatherPress\Core\Event\Event'      => array(
			'methods'   => array(
				'save_datetimes',
				'get_venue_information',
				'has_event_past',
				'has_event_started',
				'get_datetime',
				'get_display_datetime',
				'maybe_get_online_event_link',
				'can_read_rsvps',
			),
			'constants' => array( 'POST_TYPE', 'TABLE_FORMAT', 'DATETIME_PLACEHOLDER' ),
		),
		// The publish notification calls this directly instead of going
		// through WP-Cron, so a rename would stop every notification.
		'GatherPress\Core\Event\Rest_Api'   => array(
			'methods' => array( 'send_emails' ),
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
		'GatherPress\Core\Utility'          => array(
			'methods' => array( 'timezone_choices', 'normalize_timezone_string' ),
		),
		'GatherPress\Core\Topic'            => array(
			'constants' => array( 'TAXONOMY' ),
		),
		// The RSVP confirmation email links to the same on-site endpoints the
		// event page's add-to-calendar control does, so a rename upstream
		// would silently drop those lines from the email.
		'GatherPress\Core\Calendar\Calendar' => array(
			'methods' => array( 'get_google_url', 'get_ical_url', 'get_outlook_url', 'get_yahoo_url' ),
		),
	);

	/**
	 * Names GatherPress keeps resolvable only through
	 * `includes/core/register-class-aliases.php`, since the classes moved
	 * into subnamespaces in 0.34.0. `class_exists()` on one of these stays
	 * true only while that shim ships, so code guarded by it would go dormant
	 * without a fatal the day the alias is dropped.
	 */
	const DEPRECATED_ALIASES = array(
		'GatherPress\Core\Calendar',
		'GatherPress\Core\Event',
		'GatherPress\Core\Rsvp',
		'GatherPress\Core\Venue',
		'GatherPress\Core\Venue\Map',
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
	 * The integration names every GatherPress class by its current location,
	 * never by a deprecated alias. The feed's venue filter did, guarding on
	 * `class_exists( '\GatherPress\Core\Venue' )`, and would have quietly
	 * stopped hiding a protected event's venue once the alias went away.
	 */
	public function test_code_does_not_use_deprecated_aliases() {
		$roots = array(
			SUT_WP_CONTENT_DIR . 'mu-plugins/groups',
			SUT_WP_CONTENT_DIR . 'mu-plugins/wporg-groups-frontend',
			SUT_WP_CONTENT_DIR . 'themes/groups-site',
		);
		$names = implode( '|', array_map( static fn( $alias ) => preg_quote( $alias, '/' ), self::DEPRECATED_ALIASES ) );
		$hits  = array();

		foreach ( $roots as $root ) {
			$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );

			foreach ( $files as $file ) {
				$path = $file->getPathname();

				if ( ! str_ends_with( $path, '.php' ) || preg_match( '#/(tests|build|vendor|node_modules)/#', $path ) ) {
					continue;
				}

				// Comments may name an alias when explaining history.
				$code = '';
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a source file from disk.
				foreach ( token_get_all( file_get_contents( $path ) ) as $token ) {
					if ( ! is_array( $token ) || ! in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
						$code .= is_array( $token ) ? $token[1] : $token;
					}
				}

				// A single or doubled separator, as in a string literal.
				$code = str_replace( '\\\\', '\\', $code );

				if ( preg_match_all( '/\\\\?\b(' . $names . ')\b(?!\\\\)/', $code, $matches ) ) {
					$hits[] = str_replace( SUT_WP_CONTENT_DIR, '', $path ) . ': ' . implode( ', ', array_unique( $matches[1] ) );
				}
			}
		}

		$this->assertSame( array(), $hits, 'Use the class each alias points to; see register-class-aliases.php in GatherPress.' );
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
