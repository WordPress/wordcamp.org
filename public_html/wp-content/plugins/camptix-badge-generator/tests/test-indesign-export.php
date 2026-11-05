<?php

use function CampTix\Badge_Generator\InDesign\{ generate_csv, get_header_row };

defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/includes/indesign-badges.php';

/**
 * Tests for the InDesign CSV export.
 *
 * @group camptix-badge-generator
 */
class Test_InDesign_Export extends WP_UnitTestCase {
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
	 * InDesign treats a column whose name starts with `@` as an image.
	 *
	 * @covers \CampTix\Badge_Generator\InDesign\generate_csv
	 * @covers \CampTix\Badge_Generator\InDesign\get_header_row
	 */
	public function test_image_columns_keep_their_at_sign() {
		self::factory()->post->create( array(
			'post_type'   => 'tix_question',
			'post_status' => 'publish',
			'post_title'  => '=HYPERLINK("https://example.org")',
		) );

		$csv_filename = wp_tempnam( 'cbg-indesign-test' );

		generate_csv( $csv_filename, 'badges', array(), get_temp_dir(), '', 'gravatar' );

		$handle = fopen( $csv_filename, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$header = fgetcsv( $handle, null, ',', '"', '\\' );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		unlink( $csv_filename );

		$this->assertSame( array( '@QRCode', '@Gravatar' ), array_slice( $header, -2 ) );
		$this->assertContains( "'=HYPERLINK(\"https://example.org\")", $header, 'Organizer-named columns are still escaped.' );

		$this->assertSame( '@Gravatar', array_slice( get_header_row( array(), array() ), -1 )[0], 'Without a QR code.' );
	}
}
