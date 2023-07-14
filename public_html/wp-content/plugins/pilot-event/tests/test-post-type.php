<?php

namespace WordCamp\PilotEvents\Tests;
use WordCamp_Post_Types_Plugin;
use WP_UnitTestCase, WP_UnitTest_Factory;

defined( 'WPINC' ) || die();

/**
 *
 */
class Test_PilotEvents_PostType extends WP_UnitTestCase {

	/**
	 * This is the first test! We passed!
	 */
	public function test_passes() : void {
		$this->assertSame( true, true );
	}
}
