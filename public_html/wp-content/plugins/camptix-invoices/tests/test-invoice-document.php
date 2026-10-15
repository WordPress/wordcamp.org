<?php

defined( 'WPINC' ) || die();

/**
 * Tests for the stored invoice document filename.
 *
 * @group camptix-invoices
 */
class Test_CampTix_Invoice_Document extends WP_UnitTestCase {
	/**
	 * Register the invoice post type and its meta.
	 *
	 * That normally happens during CampTix's addon loading, which the harness doesn't run.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! registered_meta_key_exists( 'post', 'invoice_document', 'tix_invoice' ) ) {
			register_tix_invoice();
		}
	}

	/**
	 * Names in the shape the plugin generates are stored unchanged.
	 *
	 * @dataProvider data_generated_filenames
	 *
	 * @param string $filename A filename the plugin could have generated.
	 */
	public function test_sanitize_keeps_generated_filenames( $filename ) {
		$this->assertSame( $filename, ctx_sanitize_invoice_document( $filename ) );
	}

	/**
	 * Data provider for test_sanitize_keeps_generated_filenames().
	 *
	 * @return array[]
	 */
	public function data_generated_filenames() {
		return array(
			'blog and number'       => array( '12-42-AbCdEf123456.pdf' ),
			'blog, year and number' => array( '12-2026-42-AbCdEf123456.pdf' ),
			'empty invoice number'  => array( '-AbCdEf123456.pdf' ),
		);
	}

	/**
	 * Anything else is not stored.
	 *
	 * @dataProvider data_rejected_filenames
	 *
	 * @param string $value A value the plugin never generates.
	 */
	public function test_sanitize_rejects_other_filenames( $value ) {
		$this->assertSame( '', ctx_sanitize_invoice_document( $value ) );
	}

	/**
	 * Data provider for test_sanitize_rejects_other_filenames().
	 *
	 * @return array[]
	 */
	public function data_rejected_filenames() {
		return array(
			'php extension'         => array( 'invoice.php' ),
			'phtml extension'       => array( 'invoice.phtml' ),
			'double extension'      => array( 'invoice.pdf.php' ),
			'no extension'          => array( 'invoice' ),
			'space in the name'     => array( 'my invoice.pdf' ),
			'pdf without the shape' => array( 'invoice.pdf' ),
			'short random part'     => array( '12-42-AbC.pdf' ),
			'trailing newline'      => array( "12-42-AbCdEf123456.pdf\n" ),
			'empty'                 => array( '' ),
		);
	}

	/**
	 * A directory component is dropped, as before.
	 */
	public function test_sanitize_drops_directories() {
		$this->assertSame( '12-42-AbCdEf123456.pdf', ctx_sanitize_invoice_document( '../../12-42-AbCdEf123456.pdf' ) );
	}

	/**
	 * The sanitizer runs when the meta is written directly, which is how an import writes it.
	 */
	public function test_direct_meta_write_does_not_store_other_filenames() {
		$invoice_id = self::factory()->post->create( array( 'post_type' => 'tix_invoice' ) );

		add_post_meta( $invoice_id, 'invoice_document', 'invoice.php' );

		$this->assertSame( '', get_post_meta( $invoice_id, 'invoice_document', true ) );
	}
}
