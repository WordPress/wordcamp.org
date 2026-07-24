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

	public function test_frontend_plugin_bootstrap_is_guarded() {
		$source = file_get_contents(
			dirname( __DIR__ ) . '/wporg-groups-frontend.php'
		);

		$this->assertStringContainsString(
			'class_exists( \'\GatherPress\Core\Event\Event\' )',
			$source,
			'wporg-groups-frontend.php should still guard its bootstrap() on GatherPress being loaded.'
		);
	}

	public function test_groups_tweaks_is_guarded() {
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
