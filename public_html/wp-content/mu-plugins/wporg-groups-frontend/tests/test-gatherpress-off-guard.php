<?php

namespace WordCamp\Groups\Tests;

use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * Pins the known bootstrap guard clauses that let both plugins no-op
 * instead of fataling when GatherPress is deactivated.
 *
 * This can't be a true "deactivate GatherPress and hit the site" test
 * within a single PHPUnit process — once GatherPress's classes are loaded,
 * PHP can't unload them. That dynamic check is part of the
 * `groups-gatherpress-compat-test` skill's GatherPress-deactivated pass
 * instead. This test only guards against someone accidentally deleting the
 * `class_exists()` checks these bootstraps depend on.
 *
 * @group groups
 */
class Test_Groups_GatherPress_Off_Guard extends WP_UnitTestCase {

	/**
	 * The main plugin file's bootstrap() must stay guarded on GatherPress
	 * being loaded.
	 */
	public function test_frontend_plugin_bootstrap_is_guarded() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local plugin file, not a remote URL.
		$source = file_get_contents(
			dirname( __DIR__ ) . '/wporg-groups-frontend.php'
		);

		$this->assertStringContainsString(
			'class_exists( \'\GatherPress\Core\Event\Event\' )',
			$source,
			'wporg-groups-frontend.php should still guard its bootstrap() on GatherPress being loaded.'
		);
	}

	/**
	 * The sponsors store is the one piece that must run *without* GatherPress:
	 * it's edited on the groups network root site, which doesn't run the
	 * plugin. If someone moves `Sponsors\bootstrap()` below the guard, the
	 * Sponsors admin screen silently disappears.
	 */
	public function test_sponsors_bootstrap_runs_before_the_gatherpress_guard() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local plugin file, not a remote URL.
		$source = file_get_contents(
			dirname( __DIR__ ) . '/wporg-groups-frontend.php'
		);

		$sponsors = strpos( $source, 'Sponsors\bootstrap();' );
		$guard    = strpos( $source, 'class_exists( \'\GatherPress\Core\Event\Event\' )' );

		$this->assertNotFalse( $sponsors, 'wporg-groups-frontend.php should still bootstrap the sponsors store.' );
		$this->assertLessThan(
			$guard,
			$sponsors,
			'Sponsors\bootstrap() should run before the GatherPress guard, since the network root site has no GatherPress.'
		);
	}

	/**
	 * `gatherpress-groups-tweaks.php` must stay guarded on its
	 * GatherPress-dependent code path.
	 */
	public function test_groups_tweaks_is_guarded() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local plugin file, not a remote URL.
		$source = file_get_contents(
			dirname( __DIR__, 2 ) . '/groups/gatherpress-groups-tweaks.php'
		);

		$this->assertStringContainsString(
			'class_exists( \'\GatherPress\Core\Event\Setup\' )',
			$source,
			'gatherpress-groups-tweaks.php should still guard its GatherPress-dependent code path.'
		);
	}
}
