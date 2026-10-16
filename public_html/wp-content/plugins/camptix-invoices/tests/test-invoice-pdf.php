<?php

defined( 'WPINC' ) || die();

// The harness doesn't load the mu-plugins, which is where Composer's autoloader (and DomPDF) normally comes from.
require_once dirname( WP_PLUGIN_DIR ) . '/mu-plugins/vendor/autoload.php';
require_once WP_PLUGIN_DIR . '/wordcamp-docs/classes/class-wordcamp-docs-pdf-generator.php';

/**
 * Tests for rendering invoices and other documents to PDF with DomPDF.
 *
 * @group camptix-invoices
 */
class Test_CampTix_Invoice_PDF extends WP_UnitTestCase {
	/**
	 * A font cache folder for the test, so nothing touches the real one.
	 *
	 * @var string
	 */
	protected $font_dir;

	/**
	 * Point the font cache at an empty folder.
	 */
	public function set_up() {
		parent::set_up();

		$this->font_dir = get_temp_dir() . uniqid( 'wcdocs-fonts-' ) . '/';
		add_filter( 'wordcamp_docs_font_cache_folder', array( $this, 'font_cache_folder' ) );
	}

	/**
	 * Remove the test's font cache folder.
	 */
	public function tear_down() {
		remove_filter( 'wordcamp_docs_font_cache_folder', array( $this, 'font_cache_folder' ) );

		foreach ( glob( $this->font_dir . '*' ) as $file ) {
			unlink( $file );
		}
		if ( is_dir( $this->font_dir ) ) {
			rmdir( $this->font_dir );
		}

		parent::tear_down();
	}

	/**
	 * Filter callback for the font cache folder.
	 *
	 * @return string
	 */
	public function font_cache_folder() {
		return $this->font_dir;
	}

	/**
	 * Code points as a string, for writing expected presentation forms readably.
	 *
	 * @param int ...$code_points Code points.
	 *
	 * @return string
	 */
	protected static function chars( ...$code_points ) {
		return implode( '', array_map( 'mb_chr', $code_points ) );
	}

	/**
	 * Arabic letters take the form for their position in the word, and the run is reversed into
	 * the left-to-right order DomPDF draws in.
	 *
	 * @covers WordCamp_Docs_Arabic_Shaper::shape
	 */
	public function test_shape_joins_letters_and_reverses_runs() {
		// Muhammad: initial meem, medial hah, medial meem, final dal.
		$muhammad = self::chars( 0xFEAA, 0xFEE4, 0xFEA4, 0xFEE3 );
		// Ahmad: alef with hamza doesn't join forward, so hah starts a new joined group.
		$ahmad = self::chars( 0xFEAA, 0xFEE4, 0xFEA3, 0xFE83 );

		$this->assertSame( $muhammad, WordCamp_Docs_Arabic_Shaper::shape( 'محمد' ) );
		$this->assertSame( $ahmad, WordCamp_Docs_Arabic_Shaper::shape( 'أحمد' ) );

		// Two words are one run, so the word order is reversed too.
		$this->assertSame( $ahmad . ' ' . $muhammad, WordCamp_Docs_Arabic_Shaper::shape( 'محمد أحمد' ) );
	}

	/**
	 * Lam and alef become one ligature, harakat stay on their letter without breaking the joins,
	 * and the Persian letters are shaped as well.
	 *
	 * @covers WordCamp_Docs_Arabic_Shaper::shape
	 */
	public function test_shape_handles_ligatures_marks_and_persian() {
		// Salam: initial seen, final lam-alef, then an isolated meem since alef doesn't join forward.
		$this->assertSame( self::chars( 0xFEE1, 0xFEFC, 0xFEB3 ), WordCamp_Docs_Arabic_Shaper::shape( 'سلام' ) );

		// Muhammad with damma, fatha and shadda.
		$this->assertSame(
			self::chars( 0xFEAA, 0xFEE4, 0x064E, 0x0651, 0xFEA4, 0x064E, 0xFEE3, 0x064F ),
			WordCamp_Docs_Arabic_Shaper::shape( 'مُحَمَّد' )
		);

		// Pedar (Persian): initial peh, final dal, isolated reh.
		$this->assertSame( self::chars( 0xFEAD, 0xFEAA, 0xFB58 ), WordCamp_Docs_Arabic_Shaper::shape( 'پدر' ) );
	}

	/**
	 * Only Arabic runs change. Markup, other scripts and numbers next to Arabic are left alone.
	 *
	 * @covers WordCamp_Docs_Arabic_Shaper::shape
	 */
	public function test_shape_leaves_everything_else_alone() {
		$html = '<p class="name">Jane <strong>Doe</strong> 张伟 김민준</p>';
		$this->assertSame( $html, WordCamp_Docs_Arabic_Shaper::shape( $html ) );

		$this->assertSame(
			'<p class="name">' . self::chars( 0xFE94, 0xFED3, 0xFEAE, 0xFECF ) . ' 12</p>',
			WordCamp_Docs_Arabic_Shaper::shape( '<p class="name">غرفة 12</p>' )
		);
	}

	/**
	 * A PDF is produced from HTML, and the working folder is removed afterwards.
	 *
	 * @covers WordCamp_Docs_PDF_Generator::generate_pdf_from_string
	 */
	public function test_generates_a_pdf() {
		$generator = new WordCamp_Docs_PDF_Generator();
		$path      = $generator->generate_pdf_from_string( '<html><body><p>Paid in full.</p></body></html>', 'invoice.pdf' );

		$this->assertStringStartsWith( '%PDF-', file_get_contents( $path ) );
		$this->assertSame( '', $generator->last_error );

		$generator->delete_tmp_folder();
		$this->assertDirectoryDoesNotExist( dirname( $path ) );
	}

	/**
	 * Markup that reaches a template can't make the server fetch a URL other than the web
	 * fonts, or read a local file outside the working folder.
	 *
	 * @covers WordCamp_Docs_PDF_Generator::generate_pdf_from_file
	 */
	public function test_only_fetches_fonts_and_working_folder_files() {
		global $_dompdf_warnings;
		$_dompdf_warnings = array();

		$generator = new WordCamp_Docs_PDF_Generator();
		$generator->generate_pdf_from_string(
			'<html><body><img src="http://169.254.169.254/latest/meta-data.png"><img src="' . ABSPATH . 'wp-includes/images/w-logo-blue.png"></body></html>',
			'invoice.pdf'
		);
		$generator->delete_tmp_folder();

		$warnings = implode( "\n", (array) $_dompdf_warnings );
		$this->assertStringContainsString( 'Remote host is not in allowed list: 169.254.169.254', $warnings );
		$this->assertStringContainsString( 'Options::chroot', $warnings );
	}

	/**
	 * The invoice logo is copied into the working folder, so DomPDF can read it.
	 *
	 * @covers WordCamp_Docs_PDF_Generator::generate_pdf_from_file
	 */
	public function test_reads_assets_copied_into_the_working_folder() {
		global $_dompdf_warnings;

		if ( ! extension_loaded( 'gd' ) ) {
			$this->markTestSkipped( 'DomPDF needs GD to draw PNG images.' );
		}

		$_dompdf_warnings = array();

		$logo      = CTX_INV_DIR . '/admin/images/wp-community-support.png';
		$generator = new WordCamp_Docs_PDF_Generator();
		$generator->generate_pdf_from_string(
			'<html><body><img src="' . esc_url( $generator->get_tmp_folder( basename( $logo ) ) ) . '"></body></html>',
			'invoice.pdf',
			array( 'assets' => array( $logo ) )
		);
		$generator->delete_tmp_folder();

		$this->assertSame( array(), (array) $_dompdf_warnings );
	}

	/**
	 * When DomPDF throws, no PDF is written and the reason is kept for the caller to log.
	 *
	 * @covers WordCamp_Docs_PDF_Generator::generate_pdf_from_file
	 */
	public function test_a_rendering_error_leaves_no_pdf() {
		$generator = new WordCamp_Docs_PDF_Generator();
		$path      = $generator->generate_pdf_from_file( $generator->get_tmp_folder( 'missing.html' ), 'invoice.pdf' );

		$this->assertFileDoesNotExist( $path );
		$this->assertNotSame( '', $generator->last_error );

		$generator->delete_tmp_folder();
	}

	/**
	 * Once every web font is in the cache, warming it does nothing.
	 *
	 * @covers WordCamp_Docs_PDF_Generator::warm_font_cache
	 */
	public function test_warm_font_cache_skips_a_warm_cache() {
		$installed = array_fill_keys( WordCamp_Docs_Template::FONT_FAMILIES, array() );
		file_put_contents( WordCamp_Docs_PDF_Generator::get_font_cache_folder() . 'installed-fonts.json', wp_json_encode( $installed ) );

		$this->assertTrue( WordCamp_Docs_PDF_Generator::warm_font_cache() );
		$this->assertSame( array( 'installed-fonts.json' ), array_map( 'basename', glob( $this->font_dir . '*' ) ) );
	}

	/**
	 * When the font cache folder can't be written to, the cache moves to the temp folder rather
	 * than leaving DomPDF unable to render anything.
	 *
	 * @covers WordCamp_Docs_PDF_Generator::get_font_cache_folder
	 */
	public function test_font_cache_falls_back_to_temp_folder_when_not_writable() {
		// A folder inside a file can't be created, not even by root.
		$this->font_dir = __FILE__ . '/fonts/';

		$font_dir = WordCamp_Docs_PDF_Generator::get_font_cache_folder();

		$this->assertSame( get_temp_dir() . 'wordcamp-docs-fonts/', $font_dir );
		$this->assertTrue( wp_is_writable( $font_dir ) );
	}

	/**
	 * A missing PDF is a plain 404, without PHP warnings from trying to read it anyway.
	 *
	 * @covers WordCamp_Docs_PDF_Generator::serve_pdf_to_browser
	 */
	public function test_serving_a_missing_pdf_only_says_not_found() {
		$generator = new WordCamp_Docs_PDF_Generator();

		ob_start();
		$generator->serve_pdf_to_browser( 'missing.pdf' );
		$this->assertSame( 'File not found.', ob_get_clean() );

		$generator->delete_tmp_folder();
	}

	/**
	 * The From and To addresses are laid out in a table, which DomPDF sizes to fit. As floats
	 * inside an overflow: hidden box, DomPDF collapsed them and hid both addresses.
	 */
	public function test_invoice_template_lays_out_the_addresses_in_a_table() {
		$camptix_opts   = array(
			'currency'           => 'USD',
			'event_name'         => 'WordCamp Test',
			'invoice-company'    => 'WordPress Community Support PBC',
			'invoice-thankyou'   => '',
			'invoice-vat-number' => '',
		);
		$invoice_number = '1';
		$invoice_date   = '13/10/2026';
		$invoice_metas  = array(
			'name'       => 'Jane Doe',
			'address'    => 'Street 1',
			'vat-number' => '',
		);
		$invoice_order  = array(
			'items' => array(),
			'total' => 0,
		);
		$logo           = '';

		require_once CTX_INV_DIR . '/includes/class-camptix-addon-invoices.php';

		ob_start();
		include CTX_INV_DIR . '/includes/views/invoice-template.php';
		$html = ob_get_clean();

		$this->assertMatchesRegularExpression( '#<table class="camptix-inovice-header">\s*<tr>\s*<td class="camptix-invoice-from-box#', $html );
		$this->assertStringNotContainsString( 'float:', $html );
	}
}
