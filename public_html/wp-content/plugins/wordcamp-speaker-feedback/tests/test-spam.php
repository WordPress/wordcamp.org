<?php

namespace WordCamp\SpeakerFeedback\Tests;

use WP_UnitTestCase;
use function WordCamp\SpeakerFeedback\Spam\get_consolidated_meta_string;

defined( 'WPINC' ) || die();

/**
 * Class Test_SpeakerFeedback_CommentMeta
 *
 * @group wordcamp-speaker-feedback
 */
class Test_SpeakerFeedback_Spam extends WP_UnitTestCase {
	/**
	 * @var array
	 */
	protected static $valid_meta;

	/**
	 * Set up shared fixtures for these tests.
	 */
	public static function wpSetUpBeforeClass() {
		self::$valid_meta = array(
			'rating' => 1,
			'q1'     => 'asdf 1',
			'q2'     => 'asdf 2',
			'q3'     => 'asdf 3',
		);
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\Spam\get_consolidated_meta_string()
	 */
	public function test_get_consolidated_meta_string() {
		$expected = "asdf 1\n\nasdf 2\n\nasdf 3";

		$actual = get_consolidated_meta_string( self::$valid_meta );

		$this->assertEquals( $expected, $actual );
	}
}
