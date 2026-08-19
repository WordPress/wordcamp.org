<?php

namespace WordCamp\Budgets_Dashboard\Tests;

use WP_UnitTestCase;
use function WordCamp\Budgets\Reimbursement_Requests\set_request_status;
use function WordCamp\Budgets\Sponsor_Invoices\set_invoice_status;

defined( 'WPINC' ) || die();

/**
 * Who may set which status on the budget CPTs.
 *
 * Each of the three budget CPTs normalises `post_status` in a `wp_insert_post_data` filter as a request is
 * saved. Statuses past pending approval/submission (approval, payment, etc.) are reserved for network
 * admins; a non-network-admin editing their own request/invoice keeps a status within the requester-settable
 * set. These tests exercise those filters directly against a real, stored draft.
 *
 * @group budgets-dashboard
 */
class Test_Status_Permissions extends WP_UnitTestCase {
	/**
	 * A subsite administrator: authors requests, but is not a network admin.
	 *
	 * @var int
	 */
	protected static $requester_id;

	/**
	 * A network administrator: may approve requests.
	 *
	 * @var int
	 */
	protected static $network_admin_id;

	/**
	 * Create the shared users.
	 *
	 * @param \WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$requester_id     = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$network_admin_id = $factory->user->create( array( 'role' => 'administrator' ) );

		grant_super_admin( self::$network_admin_id );
	}

	/**
	 * Exercise the `wp_insert_post_data` status filters in isolation.
	 *
	 * The filters read the current user's caps and the raw POST, so keep both predictable. The `save_post`
	 * handlers are detached so creating the stored draft fixtures doesn't run their nonce checks (which
	 * `wp_die()` outside a real request), and restored in `tear_down()` so other suites are unaffected.
	 */
	public function set_up() {
		parent::set_up();

		$_POST    = array();
		$_REQUEST = array();

		remove_action( 'save_post', 'WordCamp\Budgets\Reimbursement_Requests\save_request', 10 );
		remove_action( 'save_post', 'WordCamp\Budgets\Sponsor_Invoices\save_invoice', 10 );
		remove_action( 'save_post', array( $GLOBALS['wcp_payment_request'], 'save_payment' ), 10 );
	}

	/**
	 * Re-attach the `save_post` handlers detached in `set_up()`.
	 */
	public function tear_down() {
		add_action( 'save_post', 'WordCamp\Budgets\Reimbursement_Requests\save_request', 10, 2 );
		add_action( 'save_post', 'WordCamp\Budgets\Sponsor_Invoices\save_invoice', 10, 2 );
		add_action( 'save_post', array( $GLOBALS['wcp_payment_request'], 'save_payment' ), 10, 2 );

		parent::tear_down();
	}

	/**
	 * Create a stored draft post of the given budget CPT, authored by the requester.
	 *
	 * @param string $post_type
	 *
	 * @return int
	 */
	protected function create_draft( $post_type ) {
		return self::factory()->post->create( array(
			'post_type'   => $post_type,
			'post_status' => 'draft',
			'post_author' => self::$requester_id,
		) );
	}

	// -- Reimbursement requests -------------------------------------------------------------------------

	/**
	 * A non-network-admin submitting `wcb-approved` on their own draft is kept at pending approval.
	 */
	public function test_non_network_admin_cannot_set_approved_reimbursement() {
		wp_set_current_user( self::$requester_id );
		$draft = $this->create_draft( 'wcb_reimbursement' );

		$result = set_request_status(
			array( 'post_type' => 'wcb_reimbursement', 'post_status' => 'wcb-approved' ),
			array( 'ID' => $draft, 'post_status' => 'wcb-approved' )
		);

		$this->assertSame( 'wcb-pending-approval', $result['post_status'] );
	}

	/**
	 * The "Submit for Review" path still promotes a draft to pending approval.
	 */
	public function test_requester_submit_promotes_reimbursement_to_pending() {
		wp_set_current_user( self::$requester_id );
		$draft = $this->create_draft( 'wcb_reimbursement' );

		$result = set_request_status(
			array( 'post_type' => 'wcb_reimbursement', 'post_status' => 'draft' ),
			array( 'ID' => $draft, 'post_status' => 'draft', 'wcb-update' => 'Submit for Review' )
		);

		$this->assertSame( 'wcb-pending-approval', $result['post_status'] );
	}

	/**
	 * A network admin may approve a reimbursement.
	 */
	public function test_network_admin_can_approve_reimbursement() {
		wp_set_current_user( self::$network_admin_id );
		$draft = $this->create_draft( 'wcb_reimbursement' );

		$result = set_request_status(
			array( 'post_type' => 'wcb_reimbursement', 'post_status' => 'wcb-approved' ),
			array( 'ID' => $draft, 'post_status' => 'wcb-approved' )
		);

		$this->assertSame( 'wcb-approved', $result['post_status'] );
	}

	// -- Vendor payment requests ------------------------------------------------------------------------

	/**
	 * A non-network-admin submitting `wcb-approved` on their own draft payment is kept at pending approval.
	 */
	public function test_non_network_admin_cannot_set_approved_payment() {
		wp_set_current_user( self::$requester_id );
		$draft = $this->create_draft( 'wcp_payment_request' );

		$result = $GLOBALS['wcp_payment_request']->wp_insert_post_data(
			array(
				'post_type'     => 'wcp_payment_request',
				'post_status'   => 'wcb-approved',
				'post_date'     => '2020-01-01 00:00:00',
				'post_date_gmt' => '2020-01-01 00:00:00',
			),
			array( 'ID' => $draft, 'post_status' => 'wcb-approved' )
		);

		$this->assertSame( 'wcb-pending-approval', $result['post_status'] );
	}

	/**
	 * A network admin may approve a vendor payment.
	 */
	public function test_network_admin_can_approve_payment() {
		wp_set_current_user( self::$network_admin_id );
		$draft = $this->create_draft( 'wcp_payment_request' );

		$result = $GLOBALS['wcp_payment_request']->wp_insert_post_data(
			array(
				'post_type'     => 'wcp_payment_request',
				'post_status'   => 'wcb-approved',
				'post_date'     => '2020-01-01 00:00:00',
				'post_date_gmt' => '2020-01-01 00:00:00',
			),
			array( 'ID' => $draft, 'post_status' => 'wcb-approved' )
		);

		$this->assertSame( 'wcb-approved', $result['post_status'] );
	}

	// -- Sponsor invoices -------------------------------------------------------------------------------

	/**
	 * A non-network-admin submitting `wcbsi_approved` on their own draft invoice is kept at draft.
	 */
	public function test_non_network_admin_cannot_set_approved_invoice() {
		wp_set_current_user( self::$requester_id );
		$sponsor = $this->create_complete_sponsor();
		$draft   = $this->create_draft( 'wcb_sponsor_invoice' );

		$result = set_invoice_status(
			array( 'post_type' => 'wcb_sponsor_invoice', 'post_status' => 'wcbsi_approved' ),
			array_merge(
				array( 'ID' => $draft, 'post_status' => 'wcbsi_approved' ),
				$this->complete_invoice_fields( $sponsor )
			)
		);

		$this->assertSame( 'draft', $result['post_status'] );
	}

	/**
	 * A network admin may approve a sponsor invoice.
	 */
	public function test_network_admin_can_approve_invoice() {
		wp_set_current_user( self::$network_admin_id );
		$sponsor = $this->create_complete_sponsor();
		$draft   = $this->create_draft( 'wcb_sponsor_invoice' );

		$result = set_invoice_status(
			array( 'post_type' => 'wcb_sponsor_invoice', 'post_status' => 'wcbsi_approved' ),
			array_merge(
				array( 'ID' => $draft, 'post_status' => 'wcbsi_approved' ),
				$this->complete_invoice_fields( $sponsor )
			)
		);

		$this->assertSame( 'wcbsi_approved', $result['post_status'] );
	}

	/**
	 * Create a sponsor whose required contact fields are all filled, so an invoice referencing it is
	 * considered "complete" and the status rule -- not the missing-fields fallback -- is what applies.
	 *
	 * @return int Sponsor post ID.
	 */
	protected function create_complete_sponsor() {
		$sponsor = self::factory()->post->create( array(
			'post_type'   => 'wcb_sponsor',
			'post_status' => 'publish',
		) );

		$fields = array(
			'company_name'    => 'ACME Corp',
			'first_name'      => 'Jane',
			'last_name'       => 'Doe',
			'email_address'   => 'jane@example.org',
			'phone_number'    => '555-1212',
			'street_address1' => '1 Main St',
			'city'            => 'Seattle',
			'state'           => 'WA',
			'zip_code'        => '98101',
			'country'         => 'US',
		);

		foreach ( $fields as $key => $value ) {
			update_post_meta( $sponsor, "_wcpt_sponsor_$key", $value );
		}

		return $sponsor;
	}

	/**
	 * The set of invoice fields that `set_invoice_status()` treats as required.
	 *
	 * @param int $sponsor_id
	 *
	 * @return array
	 */
	protected function complete_invoice_fields( $sponsor_id ) {
		return array(
			'_wcbsi_sponsor_id'   => (string) $sponsor_id,
			'_wcbsi_description'  => 'Gold sponsorship',
			'_wcbsi_currency'     => 'USD',
			'_wcbsi_amount'       => '5000',
			'_wcbsi_qbo_class_id' => '42',
		);
	}
}
