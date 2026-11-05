<?php

use function CampTix\Badge_Generator\InDesign\{ get_header_row, get_attendee_csv_row };

defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/includes/indesign-badges.php';

/**
 * Tests for the InDesign CSV.
 *
 * @group camptix-badge-generator
 */
class Test_InDesign_Badges extends WP_UnitTestCase {
	use CampTix_Root_Blog_Fixture;

	/**
	 * Provision central when the harness points at a blog the test install lacks.
	 *
	 * @param WP_UnitTest_Factory $factory
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
	 * @covers \CampTix\Badge_Generator\InDesign\get_header_row
	 * @covers \CampTix\Badge_Generator\InDesign\get_attendee_csv_row
	 */
	public function test_columns_stay_unique_and_aligned() {
		update_option( 'cbg_use_gravatar_profiles', 0 );

		// Organizers often ask for the company, and a flag can be named after a column.
		$question_id = self::factory()->post->create( array(
			'post_type'   => 'tix_question',
			'post_status' => 'publish',
			'post_title'  => 'company',
		) );
		$questions   = array( get_post( $question_id ) );
		$admin_flags = array( 'company' => 'Company Guest' );

		update_option( 'cbg_field_questions', array( 'company' => $question_id ) );

		$attendee_id = self::factory()->post->create( array( 'post_type' => 'tix_attendee' ) );

		update_post_meta( $attendee_id, 'tix_first_name', 'Jane' );
		update_post_meta( $attendee_id, 'tix_last_name', 'Doe' );
		update_post_meta( $attendee_id, 'tix_questions', array( $question_id => 'Acme' ) );
		add_post_meta( $attendee_id, 'camptix-admin-flag', 'company' );

		$header = get_header_row( $admin_flags, $questions );
		$row    = get_attendee_csv_row( get_post( $attendee_id ), '/missing', '', '', $admin_flags, $questions );
		$row    = array_combine( $header, array_values( $row ) );

		$this->assertSame( $header, array_unique( $header ), 'InDesign needs unique field names.' );
		$this->assertSame( 'Acme', $row['Company (Badge)'] );
		$this->assertSame( 'yes', $row['Company Guest'], "The flag doesn't overwrite the company." );
		$this->assertSame( 'Acme', $row['company'], 'The question keeps its name.' );
	}
}
