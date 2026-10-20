<?php

defined( 'WPINC' ) || die();

/**
 * Tests for the invoice personal data exporter.
 *
 * @group camptix-invoices
 */
class Test_CampTix_Invoice_Data_Exporter extends WP_UnitTestCase {
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

		// These tests don't need the PDF, and it needs CampTix's options.
		remove_action( 'save_post', 'ctx_generate_invoice_document' );
	}

	/**
	 * Create an invoice for an email address.
	 *
	 * @param string $email The invoice email.
	 * @param string $name  The invoice name.
	 *
	 * @return int The invoice ID.
	 */
	protected function create_invoice( $email, $name = 'Example Ltd' ) {
		$invoice_id = self::factory()->post->create(
			array(
				'post_type'   => 'tix_invoice',
				'post_status' => 'publish',
			)
		);

		update_post_meta(
			$invoice_id,
			'invoice_metas',
			array(
				'email'      => $email,
				'name'       => $name,
				'address'    => '1 Main Street',
				'vat-number' => 'GB123456789',
			)
		);
		update_post_meta( $invoice_id, 'invoice_number', "1-$invoice_id" );

		return $invoice_id;
	}

	/**
	 * Get the item IDs the exporter returns for an email address.
	 *
	 * @param string $email The requested email.
	 *
	 * @return string[]
	 */
	protected function exported_item_ids( $email ) {
		$result = ctx_invoice_data_exporter( $email, 1 );

		$this->assertTrue( $result['done'] );

		return wp_list_pluck( $result['data'], 'item_id' );
	}

	/**
	 * Only invoices whose email is the requested address are exported, regardless of case.
	 */
	public function test_exports_only_invoices_for_the_requested_email() {
		$own       = $this->create_invoice( 'smith@example.org' );
		$own_upper = $this->create_invoice( 'Smith@Example.org' );

		$this->create_invoice( 'jsmith@example.org' );
		$this->create_invoice( 'other@example.net', 'smith@example.org Ltd' );
		$this->create_invoice( 'jones@example.net' );

		$this->assertSame(
			array( "camptix-invoice-$own", "camptix-invoice-$own_upper" ),
			$this->exported_item_ids( 'smith@example.org' )
		);
	}

	/**
	 * An address with no invoice of its own gets nothing.
	 */
	public function test_exports_nothing_without_a_matching_invoice() {
		$this->create_invoice( 'jsmith@example.org' );

		$this->assertSame( array(), $this->exported_item_ids( 'smith@example.org' ) );
	}
}
