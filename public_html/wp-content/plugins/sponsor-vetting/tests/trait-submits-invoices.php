<?php
/**
 * Submits a sponsor invoice the way the invoice screen does.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting\Tests;

defined( 'WPINC' ) || die();

/**
 * For tests that need a camp's sponsor invoiced.
 */
trait Submits_Invoices {
	/**
	 * The sponsor details the invoice screen requires before it lets an invoice be submitted.
	 *
	 * @return string[] Meta values, keyed by meta key.
	 */
	protected function get_invoiceable_sponsor_meta(): array {
		return array(
			'_wcpt_sponsor_company_name'    => 'Acme Inc.',
			'_wcpt_sponsor_first_name'      => 'Pat',
			'_wcpt_sponsor_last_name'       => 'Lee',
			'_wcpt_sponsor_email_address'   => 'pat@acme.example',
			'_wcpt_sponsor_phone_number'    => '555-0100',
			'_wcpt_sponsor_street_address1' => '1 Main St',
			'_wcpt_sponsor_city'            => 'Seattle',
			'_wcpt_sponsor_state'           => 'WA',
			'_wcpt_sponsor_zip_code'        => '98101',
			'_wcpt_sponsor_country'         => 'US',
		);
	}

	/**
	 * Drafts an invoice for a sponsor on the current site, then moves it to a status.
	 *
	 * @param int    $sponsor_id The sponsor.
	 * @param string $status     The invoice's status after the draft.
	 * @return int The invoice's ID.
	 */
	protected function invoice_sponsor( int $sponsor_id, string $status ): int {
		$invoice_id = self::factory()->post->create(
			array(
				'post_type'   => 'wcb_sponsor_invoice',
				'post_status' => 'draft',
				'meta_input'  => array( '_wcbsi_sponsor_id' => $sponsor_id ),
			)
		);

		// The invoice screen checks the sponsor posted with the save before it lets the status change.
		wp_update_post(
			array(
				'ID'                => $invoice_id,
				'post_status'       => $status,
				'_wcbsi_sponsor_id' => $sponsor_id,
			)
		);

		return $invoice_id;
	}
}
