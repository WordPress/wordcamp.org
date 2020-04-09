<?php

namespace WordCamp\SpeakerFeedback\Tests;

use WP_UnitTestCase;
use const WordCamp\SpeakerFeedback\CommentMeta\{ META_VERSION };
use function WordCamp\SpeakerFeedback\CommentMeta\{ get_feedback_meta_field_schema, validate_feedback_meta };

defined( 'WPINC' ) || die();

/**
 * Class Test_SpeakerFeedback_CommentMeta
 *
 * @group wordcamp-speaker-feedback
 */
class Test_SpeakerFeedback_CommentMeta extends WP_UnitTestCase {
	/**
	 * @var array
	 */
	protected static $valid_meta;

	/**
	 * Set up shared fixtures for these tests.
	 */
	public static function wpSetUpBeforeClass() {
		self::$valid_meta = array(
			'version' => META_VERSION,
			'rating'  => 1,
			'q1'      => 'asdf 1',
			'q2'      => 'asdf 2',
			'q3'      => 'asdf 3',
		);
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\CommentMeta\validate_feedback_meta()
	 */
	public function test_validate_feedback_meta_all_valid() {
		$result = validate_feedback_meta( self::$valid_meta );

		$this->assertEqualSetsWithIndex( self::$valid_meta, $result );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\CommentMeta\validate_feedback_meta()
	 */
	public function test_validate_feedback_meta_all_valid_strip_unknown() {
		$valid_meta_extra        = self::$valid_meta;
		$valid_meta_extra['foo'] = 'bar';

		$result = validate_feedback_meta( $valid_meta_extra );

		$this->assertEqualSetsWithIndex( self::$valid_meta, $result );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\CommentMeta\validate_feedback_meta()
	 */
	public function test_validate_feedback_meta_missing_key() {
		$invalid_meta = self::$valid_meta;
		unset( $invalid_meta['rating'] );

		$result = validate_feedback_meta( $invalid_meta );

		$this->assertWPError( $result );
		$this->assertEquals( 'feedback_meta_missing_field', $result->get_error_code() );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\CommentMeta\validate_feedback_meta()
	 */
	public function test_validate_feedback_meta_booleanish() {
		$alternate_valid_meta            = self::$valid_meta;
		$alternate_valid_meta['helpful'] = 'true';

		$result = validate_feedback_meta( $alternate_valid_meta );

		$this->assertEqualSetsWithIndex( $alternate_valid_meta, $result );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\CommentMeta\validate_feedback_meta()
	 */
	public function test_validate_feedback_meta_not_boolean() {
		$invalid_meta            = self::$valid_meta;
		$invalid_meta['helpful'] = 'spork';

		$result = validate_feedback_meta( $invalid_meta );

		$this->assertWPError( $result );
		$this->assertArrayHasKey( 'helpful', $result->get_error_data() );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\CommentMeta\validate_feedback_meta()
	 */
	public function test_validate_feedback_meta_not_numeric() {
		$invalid_meta           = self::$valid_meta;
		$invalid_meta['rating'] = 'spork';

		$result = validate_feedback_meta( $invalid_meta );

		$this->assertWPError( $result );
		$this->assertArrayHasKey( 'rating', $result->get_error_data() );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\CommentMeta\validate_feedback_meta()
	 */
	public function test_validate_feedback_meta_too_low() {
		$invalid_meta           = self::$valid_meta;
		$invalid_meta['rating'] = 0;

		$result = validate_feedback_meta( $invalid_meta );

		$this->assertWPError( $result );
		$this->assertArrayHasKey( 'rating', $result->get_error_data() );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\CommentMeta\validate_feedback_meta()
	 */
	public function test_validate_feedback_meta_too_high() {
		$invalid_meta           = self::$valid_meta;
		$invalid_meta['rating'] = 256;

		$result = validate_feedback_meta( $invalid_meta );

		$this->assertWPError( $result );
		$this->assertArrayHasKey( 'rating', $result->get_error_data() );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\CommentMeta\validate_feedback_meta()
	 */
	public function test_validate_feedback_meta_too_long() {
		$schema = get_feedback_meta_field_schema( 'all', 'q1' );

		$too_long_answer = array_fill( 0, $schema['attributes']['maxlength'] + 1, 'a' );
		$too_long_answer = implode( '', $too_long_answer );

		$invalid_meta       = self::$valid_meta;
		$invalid_meta['q1'] = $too_long_answer;

		$result = validate_feedback_meta( $invalid_meta );

		$this->assertWPError( $result );
		$this->assertArrayHasKey( 'q1', $result->get_error_data() );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\CommentMeta\validate_feedback_meta()
	 */
	public function test_validate_feedback_meta_multibyte_not_too_long() {
		$schema = get_feedback_meta_field_schema( 'all', 'q1' );

		$almost_long_answer = array_fill( 0, $schema['attributes']['maxlength'], '💩' );
		$almost_long_answer = implode( '', $almost_long_answer );

		$alternate_valid_meta       = self::$valid_meta;
		$alternate_valid_meta['q1'] = $almost_long_answer;

		$result = validate_feedback_meta( $alternate_valid_meta );

		$this->assertEqualSetsWithIndex( $alternate_valid_meta, $result );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\CommentMeta\validate_feedback_meta()
	 */
	public function test_validate_feedback_meta_multiple_invalid_fields() {
		$invalid_meta            = self::$valid_meta;
		$invalid_meta['version'] = 't-rex';
		$invalid_meta['rating']  = 512;

		$result = validate_feedback_meta( $invalid_meta );

		$this->assertWPError( $result );
		$this->assertCount( 2, $result->get_error_data() );
		$this->assertArrayHasKey( 'version', $result->get_error_data() );
		$this->assertArrayHasKey( 'rating', $result->get_error_data() );
	}
}
