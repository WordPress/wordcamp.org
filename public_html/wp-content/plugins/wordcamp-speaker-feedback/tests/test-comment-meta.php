<?php

namespace WordCamp\SpeakerFeedback\Tests;

use WP_UnitTestCase;
use const WordCamp\SpeakerFeedback\CommentMeta\{ META_MAX_LENGTH, META_VERSION };
use function WordCamp\SpeakerFeedback\CommentMeta\{ validate_feedback_meta };

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
	protected $valid_meta;

	/**
	 * Test_SpeakerFeedback_CommentMeta constructor.
	 *
	 * Setup the only needs to happen once, rather than before each test.
	 *
	 * @param null   $name
	 * @param array  $data
	 * @param string $data_name
	 */
	public function __construct( $name = null, array $data = array(), $data_name = '' ) {
		parent::__construct( $name, $data, $data_name );

		$this->valid_meta = array(
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
		$result = validate_feedback_meta( $this->valid_meta );

		$this->assertEqualSetsWithIndex( $this->valid_meta, $result );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\CommentMeta\validate_feedback_meta()
	 */
	public function test_validate_feedback_meta_missing_key() {
		$invalid_meta = $this->valid_meta;
		unset( $invalid_meta['rating'] );

		$result = validate_feedback_meta( $invalid_meta );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEquals( 'feedback_missing_meta', $result->get_error_code() );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\CommentMeta\validate_feedback_meta()
	 */
	public function test_validate_feedback_meta_not_numeric() {
		$invalid_meta           = $this->valid_meta;
		$invalid_meta['rating'] = 'spork';

		$result = validate_feedback_meta( $invalid_meta );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEquals( 'feedback_meta_not_numeric', $result->get_error_code() );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\CommentMeta\validate_feedback_meta()
	 */
	public function test_validate_feedback_meta_too_long() {
		$too_long_answer = array_fill( 0, META_MAX_LENGTH + 1, 'a' );
		$too_long_answer = implode( '', $too_long_answer );

		$invalid_meta       = $this->valid_meta;
		$invalid_meta['q1'] = $too_long_answer;

		$result = validate_feedback_meta( $invalid_meta );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEquals( 'feedback_meta_too_long', $result->get_error_code() );
	}

	/**
	 * @covers \WordCamp\SpeakerFeedback\CommentMeta\validate_feedback_meta()
	 */
	public function test_validate_feedback_meta_multibyte_not_too_long() {
		$almost_long_answer = array_fill( 0, META_MAX_LENGTH, '💩' );
		$almost_long_answer = implode( '', $almost_long_answer );

		$alternate_valid_meta       = $this->valid_meta;
		$alternate_valid_meta['q1'] = $almost_long_answer;

		$result = validate_feedback_meta( $alternate_valid_meta );

		$this->assertEqualSetsWithIndex( $alternate_valid_meta, $result );
	}
}
