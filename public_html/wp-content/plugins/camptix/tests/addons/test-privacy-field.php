<?php

namespace CampTix\Tests;

use WordCamp\CampTix_Tweaks\Privacy_Field;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/trait-wordcamp-root-blog.php';

// The field is loaded by `load_privacy_field()` in the suite's bootstrap.

/**
 * Tests for the "listed on the public Attendees page" answer shown on the Edit Ticket page.
 *
 * @group camptix
 */
class Test_Privacy_Field extends \WP_UnitTestCase {
	use \CampTix_Root_Blog_Fixture;

	/**
	 * Provision central when the harness points at a blog the test install lacks.
	 *
	 * @param \WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		if ( ! get_site( WORDCAMP_ROOT_BLOG_ID ) ) {
			self::create_wordcamp_root_blog( $factory );
		}
	}

	/**
	 * Remove the root blog this class provisioned.
	 */
	public static function wpTearDownAfterClass() {
		if ( self::$wordcamp_root_blog_id ) {
			self::delete_wordcamp_root_blog();

			self::$wordcamp_root_blog_id = null;
		}
	}

	/**
	 * Data provider for test_edit_ticket_shows_stored_answer().
	 *
	 * @return array
	 */
	public function data_edit_ticket_shows_stored_answer() {
		return array(
			// The answer as saved by the ticket form since #1479.
			'answered No'                => array( 'No', null, 'No' ),
			'answered Yes'               => array( 'Yes', null, 'Yes' ),

			// Older attendees only have the meta.
			'no answer, private in meta' => array( null, 'private', 'No' ),
			'no answer, no meta'         => array( null, null, 'Yes' ),
		);
	}

	/**
	 * Attendees who chose not to be listed must see No, or saving the form would list them.
	 *
	 * @covers \WordCamp\CampTix_Tweaks\Privacy_Field::populate_attendee_answer
	 * @dataProvider data_edit_ticket_shows_stored_answer
	 *
	 * @param string|null $answer   The answer in the ticket info, if any.
	 * @param string|null $meta     The `tix_privacy` meta, if any.
	 * @param string      $expected
	 */
	public function test_edit_ticket_shows_stored_answer( $answer, $meta, $expected ) {
		$attendee_id = self::factory()->post->create( array( 'post_type' => 'tix_attendee' ) );

		if ( null !== $meta ) {
			update_post_meta( $attendee_id, 'tix_privacy', $meta );
		}

		$ticket_info = array();

		if ( null !== $answer ) {
			$ticket_info[ Privacy_Field::SLUG ] = $answer;
		}

		$field = new Privacy_Field();
		$field->camptix_init();

		$ticket_info = $field->populate_attendee_answer( $ticket_info, get_post( $attendee_id ) );

		$this->assertSame( $expected, $ticket_info[ Privacy_Field::SLUG ] );
	}
}
