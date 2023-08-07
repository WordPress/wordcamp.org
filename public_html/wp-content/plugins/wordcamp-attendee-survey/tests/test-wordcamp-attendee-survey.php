<?php

namespace WordCamp\AttendeeSurvey\Tests;

use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * Class Test_AttendeeSurvey
 *
 * @group wordcamp-attendee-survey
 */
class Test_AttendeeSurvey extends WP_UnitTestCase {
	/**
	 * Set up shared fixtures for these tests.
	 */
	public static function wpSetUpBeforeClass() {
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\Spam\get_consolidated_meta_string()
	 */
	public function test_get_consolidated_meta_string() {
		// $expected = "asdf 1\n\nasdf 2\n\nasdf 3";

		// $actual = get_consolidated_meta_string( self::$valid_meta );

		// $this->assertEquals( $expected, $actual );
	}
}
