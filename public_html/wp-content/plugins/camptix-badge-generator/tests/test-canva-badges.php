<?php

use CampTix\Badge_Generator\QR_Code;
use CampTix\Badge_Generator\XLSX_Writer;
use function CampTix\Badge_Generator\Canva\{ get_header_row, get_attendee_row, get_export_options };

defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/includes/class-xlsx-writer.php';
require_once dirname( __DIR__ ) . '/includes/indesign-badges.php';
require_once dirname( __DIR__ ) . '/includes/canva-badges.php';

/**
 * Tests for the Canva Bulk Create spreadsheet.
 *
 * @group camptix-badge-generator
 */
class Test_Canva_Badges extends WP_UnitTestCase {
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
	 * @var string
	 */
	protected $folder;

	/**
	 * Make a scratch folder.
	 */
	public function set_up() {
		parent::set_up();

		$this->folder = get_temp_dir() . 'cbg-test-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->folder );
	}

	/**
	 * Remove the scratch folder.
	 */
	public function tear_down() {
		array_map( 'unlink', glob( "$this->folder/*" ) ?: array() );
		rmdir( $this->folder );

		parent::tear_down();
	}

	/**
	 * Read a part of a saved workbook.
	 *
	 * @param string $filename
	 * @param string $part
	 *
	 * @return string|false
	 */
	protected function read_part( $filename, $part ) {
		$zip = new ZipArchive();
		$zip->open( $filename );
		$contents = $zip->getFromName( $part );
		$zip->close();

		return $contents;
	}

	/**
	 * @covers \CampTix\Badge_Generator\XLSX_Writer::get_column_letters
	 */
	public function test_column_letters() {
		$this->assertSame( 'A', XLSX_Writer::get_column_letters( 0 ) );
		$this->assertSame( 'Z', XLSX_Writer::get_column_letters( 25 ) );
		$this->assertSame( 'AA', XLSX_Writer::get_column_letters( 26 ) );
		$this->assertSame( 'BA', XLSX_Writer::get_column_letters( 52 ) );
	}

	/**
	 * @covers \CampTix\Badge_Generator\XLSX_Writer::save
	 */
	public function test_workbook_has_text_and_images_in_their_cells() {
		$qr_path = "$this->folder/qr.png";
		file_put_contents( $qr_path, QR_Code::get_png( 'https://w.org/@bor0' ) );
		file_put_contents( "$this->folder/not-an-image", 'nope' );

		$writer = new XLSX_Writer();
		$writer->add_row( array( 'Name', 'QR Code' ), true );
		$writer->add_row( array( 'Ana & <Bo> "Ž"' . "\x01", array( 'image' => $qr_path ) ) );
		$writer->add_row( array( 'Name', array( 'image' => "$this->folder/not-an-image" ) ) );
		$writer->set_column_width( 1, 15 );

		$filename = "$this->folder/test.xlsx";
		$writer->save( $filename );

		$sheet   = $this->read_part( $filename, 'xl/worksheets/sheet1.xml' );
		$strings = $this->read_part( $filename, 'xl/sharedStrings.xml' );
		$drawing = $this->read_part( $filename, 'xl/drawings/drawing1.xml' );

		foreach ( array( $sheet, $strings, $drawing, $this->read_part( $filename, '[Content_Types].xml' ) ) as $xml ) {
			$this->assertNotFalse( simplexml_load_string( $xml ), 'Every part is well-formed XML.' );
		}

		// "Name" is stored once and shared.
		$this->assertSame( 1, substr_count( $strings, '>Name</t>' ) );
		$this->assertStringContainsString( 'Ana &amp; &lt;Bo&gt; &quot;Ž&quot;</t>', $strings );
		$this->assertStringContainsString( '<c r="A1" t="s" s="1"><v>0</v></c>', $sheet );
		$this->assertStringContainsString( '<c r="A3" t="s"><v>0</v></c>', $sheet );
		$this->assertStringContainsString( '<col min="2" max="2" width="15" customWidth="1"/>', $sheet );

		// Only the real image is anchored, in column B of row 2.
		$this->assertSame( 1, substr_count( $drawing, '<xdr:twoCellAnchor>' ) );
		$this->assertStringContainsString( '<xdr:from><xdr:col>1</xdr:col><xdr:colOff>19050</xdr:colOff><xdr:row>1</xdr:row>', $drawing );
		$this->assertSame( file_get_contents( $qr_path ), $this->read_part( $filename, 'xl/media/image1.png' ) );
		$this->assertFalse( $this->read_part( $filename, 'xl/media/image2.png' ) );
	}

	/**
	 * A workbook without images leaves out the drawing parts.
	 *
	 * @covers \CampTix\Badge_Generator\XLSX_Writer::save
	 */
	public function test_workbook_without_images() {
		$writer = new XLSX_Writer();
		$writer->add_row( array( 'Name' ) );

		$filename = "$this->folder/test.xlsx";
		$writer->save( $filename );

		$this->assertFalse( $this->read_part( $filename, 'xl/drawings/drawing1.xml' ) );
		$this->assertStringNotContainsString( '<drawing', $this->read_part( $filename, 'xl/worksheets/sheet1.xml' ) );
		$this->assertStringNotContainsString( 'drawing', $this->read_part( $filename, '[Content_Types].xml' ) );
	}

	/**
	 * @covers \CampTix\Badge_Generator\Canva\get_header_row
	 * @covers \CampTix\Badge_Generator\Canva\get_attendee_row
	 */
	public function test_rows_line_up_with_header() {
		update_option( 'cbg_use_gravatar_profiles', 0 );

		$ticket_id   = self::factory()->post->create( array(
			'post_type'  => 'tix_ticket',
			'post_title' => 'General Admission',
		) );
		$attendee_id = self::factory()->post->create( array( 'post_type' => 'tix_attendee' ) );

		update_post_meta( $attendee_id, 'tix_first_name', ' Jane ' );
		update_post_meta( $attendee_id, 'tix_last_name', 'Doe' );
		update_post_meta( $attendee_id, 'tix_username', 'janedoe' );
		update_post_meta( $attendee_id, 'tix_ticket_id', $ticket_id );

		$attendee = get_post( $attendee_id );

		// Templates built for camptix-to-canva rely on these names.
		$this->assertSame( array( 'First Name', 'Last Name' ), array_slice( get_header_row( 'gravatar' ), 0, 2 ) );
		$this->assertSame( array( 'Gravatar', 'QR Gravatar' ), array_slice( get_header_row( 'gravatar' ), -2 ) );
		$this->assertSame( 'QR WordPress.org', array_slice( get_header_row( 'wporg' ), -1 )[0] );
		$this->assertSame( 'Gravatar', array_slice( get_header_row( 'none' ), -1 )[0] );

		$row = get_attendee_row( $attendee, '/photo', '/qr', 'gravatar' );
		$this->assertCount( count( get_header_row( 'gravatar' ) ), $row );
		$this->assertSame( array( 'Jane', 'Doe', '@janedoe' ), array_slice( $row, 0, 3 ) );
		$this->assertSame( 'General Admission', $row[6] );
		$this->assertSame( array( 'image' => '/photo' ), $row[7] );
		$this->assertSame( array( 'image' => '/qr' ), $row[8] );

		$row = get_attendee_row( $attendee, '', '', 'none' );
		$this->assertCount( count( get_header_row( 'none' ) ), $row );
		$this->assertSame( '', $row[7], 'No photo.' );
	}

	/**
	 * Give the site an admin flag, like organizers set up under Tickets > Setup.
	 *
	 * @param array $options
	 *
	 * @return array
	 */
	public function add_speaker_flag( $options ) {
		$options['camptix-admin-flags-data-parsed'] = array( 'speaker' => 'Speaker' );

		return $options;
	}

	/**
	 * @covers \CampTix\Badge_Generator\Canva\get_export_options
	 */
	public function test_export_options() {
		/** @global CampTix_Plugin $camptix */
		global $camptix;

		add_filter( 'camptix_options', array( $this, 'add_speaker_flag' ) );
		$camptix->load_options();
		update_option( 'timezone_string', 'Europe/Skopje' );

		$general = self::factory()->post->create( array( 'post_type' => 'tix_ticket' ) );
		$student = self::factory()->post->create( array( 'post_type' => 'tix_ticket' ) );

		$defaults = get_export_options( array() );
		$this->assertSame( 'none', $defaults['qr_code_type'] );
		$this->assertSame( array(), $defaults['ticket_ids'], 'No ticket chosen.' );
		$this->assertSame( '', $defaults['registered_after'] );
		$this->assertSame( '', $defaults['admin_flag'] );

		$options = get_export_options( array(
			'cbg_qr_code_type'     => 'wporg',
			'cbg_ticket_ids'       => array( (string) $general, (string) $student ),
			'cbg_registered_after' => '2026-10-01T09:00',
			'cbg_admin_flag'       => 'speaker',
		) );

		$this->assertSame( 'wporg', $options['qr_code_type'] );
		$this->assertSame( 'all', $options['ticket_ids'], 'Every ticket chosen.' );
		$this->assertSame( '2026-10-01T09:00:00+02:00', $options['registered_after'], "In the site's timezone." );
		$this->assertSame( strtotime( '2026-10-01 07:00:00 UTC' ), strtotime( $options['registered_after'] ) );
		$this->assertSame( 'speaker', $options['admin_flag'] );

		$options = get_export_options( array(
			'cbg_qr_code_type'     => 'evil',
			'cbg_ticket_ids'       => array( (string) $student, '999999', 'abc' ),
			'cbg_registered_after' => 'not a date',
			'cbg_admin_flag'       => 'not-a-flag',
		) );

		$this->assertSame( 'none', $options['qr_code_type'] );
		$this->assertSame( array( $student ), $options['ticket_ids'], 'Unknown tickets are dropped.' );
		$this->assertSame( '', $options['registered_after'] );
		$this->assertSame( '', $options['admin_flag'] );

		// A hand-crafted request can send arrays where the form sends text. That mustn't raise warnings.
		set_error_handler( function ( $errno, $errstr ) {
			$this->fail( $errstr );
		} );

		try {
			$options = get_export_options( array(
				'cbg_qr_code_type'     => array( 'wporg' ),
				'cbg_ticket_ids'       => array( array( $student ), (string) $general ),
				'cbg_registered_after' => array( '2026-10-01T09:00' ),
				'cbg_admin_flag'       => array( 'speaker' ),
			) );
		} finally {
			restore_error_handler();
		}

		$this->assertSame( 'none', $options['qr_code_type'] );
		$this->assertSame( array( $general ), $options['ticket_ids'], 'Nested ticket IDs are dropped.' );
		$this->assertSame( '', $options['registered_after'] );
		$this->assertSame( '', $options['admin_flag'] );

		remove_filter( 'camptix_options', array( $this, 'add_speaker_flag' ) );
		$camptix->load_options();
	}
}
