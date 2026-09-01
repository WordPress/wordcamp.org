<?php

namespace WordCamp\Budgets_Dashboard\Tests;

use WP_UnitTestCase, WordCamp_Budgets;

defined( 'WPINC' ) || die();

/**
 * What a budget request's `save_post` handler is allowed to do as the request is submitted.
 *
 * Submitting moves the request out of the statuses its requester may edit, and the row carries the new status
 * by the time `save_post` runs. The editability checks have to keep reading the status the request held before
 * that save, or the handler that writes the fields entered alongside the submission is refused and the request
 * arrives empty.
 *
 * @group budgets-dashboard
 */
class Test_Submit_Transition extends WP_UnitTestCase {
	/**
	 * A subsite administrator: authors requests, but is not a network admin.
	 *
	 * @var int
	 */
	protected static $requester_id;

	/**
	 * The status each budget CPT moves to when its requester submits it.
	 *
	 * @var array<string, string>
	 */
	const SUBMITTED_STATUSES = array(
		'wcb_sponsor_invoice' => 'wcbsi_submitted',
		'wcp_payment_request' => 'wcb-pending-approval',
		'wcb_reimbursement'   => 'wcb-pending-approval',
	);

	/**
	 * Create the shared user.
	 *
	 * @param \WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$requester_id = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * The `save_post` handlers run nonce checks that `wp_die()` outside a real request, so keep them off while
	 * the fixtures are built. Tests that want one attach it themselves.
	 */
	public function set_up() {
		parent::set_up();

		$_POST    = array();
		$_REQUEST = array();

		wp_set_current_user( self::$requester_id );

		$this->detach_save_handlers();
		$this->detach_status_filters();
	}

	/**
	 * Re-attach the `save_post` handlers detached in `set_up()`.
	 */
	public function tear_down() {
		$this->attach_save_handlers();
		$this->attach_status_filters();

		unset( $GLOBALS['post'] );

		parent::tear_down();
	}

	/**
	 * Detach every budget CPT's `save_post` handler.
	 */
	protected function detach_save_handlers() {
		remove_action( 'save_post', 'WordCamp\Budgets\Sponsor_Invoices\save_invoice', 10 );
		remove_action( 'save_post', 'WordCamp\Budgets\Reimbursement_Requests\save_request', 10 );
		remove_action( 'save_post', array( $GLOBALS['wcp_payment_request'], 'save_payment' ), 10 );
	}

	/**
	 * Re-attach every budget CPT's `save_post` handler.
	 */
	protected function attach_save_handlers() {
		add_action( 'save_post', 'WordCamp\Budgets\Sponsor_Invoices\save_invoice', 10, 2 );
		add_action( 'save_post', 'WordCamp\Budgets\Reimbursement_Requests\save_request', 10, 2 );
		add_action( 'save_post', array( $GLOBALS['wcp_payment_request'], 'save_payment' ), 10, 2 );
	}

	/**
	 * Detach the `wp_insert_post_data` filters that normalise each CPT's status.
	 *
	 * They read the request body to decide the status, so a test that stores a status directly has to set it
	 * aside. The end-to-end test attaches them again.
	 */
	protected function detach_status_filters() {
		remove_filter( 'wp_insert_post_data', 'WordCamp\Budgets\Sponsor_Invoices\set_invoice_status', 10 );
		remove_filter( 'wp_insert_post_data', 'WordCamp\Budgets\Reimbursement_Requests\set_request_status', 10 );
		remove_filter( 'wp_insert_post_data', array( $GLOBALS['wcp_payment_request'], 'wp_insert_post_data' ), 10 );
	}

	/**
	 * Re-attach the `wp_insert_post_data` filters detached above.
	 */
	protected function attach_status_filters() {
		add_filter( 'wp_insert_post_data', 'WordCamp\Budgets\Sponsor_Invoices\set_invoice_status', 10, 2 );
		add_filter( 'wp_insert_post_data', 'WordCamp\Budgets\Reimbursement_Requests\set_request_status', 10, 2 );
		add_filter( 'wp_insert_post_data', array( $GLOBALS['wcp_payment_request'], 'wp_insert_post_data' ), 10, 2 );
	}

	/**
	 * Create a stored draft request of the given type, authored by the requester.
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

	/**
	 * Put the request into the state `wp-admin/post.php` leaves before `edit_post()` updates the row.
	 *
	 * @param int $post_id
	 */
	protected function simulate_edit_form_submission( $post_id ) {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- `post.php` sets this before it saves.
		$GLOBALS['post']    = get_post( $post_id );
		$_REQUEST['action'] = 'editpost';
		$_POST['action']    = 'editpost';
	}

	/**
	 * @return array<string, array>
	 */
	public function data_budget_post_types() {
		$data = array();

		foreach ( self::SUBMITTED_STATUSES as $post_type => $submitted_status ) {
			$data[ $post_type ] = array( $post_type, $submitted_status );
		}

		return $data;
	}

	/**
	 * A `save_post` handler may still act on the request that this save submitted.
	 *
	 * @dataProvider data_budget_post_types
	 *
	 * @param string $post_type
	 * @param string $submitted_status
	 */
	public function test_save_handler_may_act_while_submitting( $post_type, $submitted_status ) {
		$post_id = $this->create_draft( $post_type );

		$this->simulate_edit_form_submission( $post_id );

		$actionable = null;

		add_action(
			'save_post',
			function ( $saved_id, $post ) use ( &$actionable, $post_type ) {
				$actionable = WordCamp_Budgets::post_edit_is_actionable( $post, $post_type );
			},
			10,
			2
		);

		wp_update_post( array(
			'ID' => $post_id, 'post_status' => $submitted_status,
		) );

		$this->assertSame( $submitted_status, get_post_status( $post_id ) );
		$this->assertTrue( $actionable );
	}

	/**
	 * A request that was already past the editable statuses before the save stays closed to its requester.
	 *
	 * This is the restriction the check above has to keep: the requester may submit, but may not then edit
	 * what they submitted.
	 *
	 * @dataProvider data_budget_post_types
	 *
	 * @param string $post_type
	 * @param string $submitted_status
	 */
	public function test_save_handler_may_not_act_on_an_already_submitted_request( $post_type, $submitted_status ) {
		$post_id = $this->create_draft( $post_type );

		wp_update_post( array(
			'ID' => $post_id, 'post_status' => $submitted_status,
		) );

		$this->simulate_edit_form_submission( $post_id );

		$actionable = null;

		add_action(
			'save_post',
			function ( $saved_id, $post ) use ( &$actionable, $post_type ) {
				$actionable = WordCamp_Budgets::post_edit_is_actionable( $post, $post_type );
			},
			10,
			2
		);

		wp_update_post( array(
			'ID' => $post_id, 'post_title' => 'Edited after submission',
		) );

		$this->assertFalse( $actionable );
	}

	/**
	 * A network admin may act on a submitted request, as before.
	 *
	 * @dataProvider data_budget_post_types
	 *
	 * @param string $post_type
	 * @param string $submitted_status
	 */
	public function test_network_admin_may_act_on_a_submitted_request( $post_type, $submitted_status ) {
		$network_admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $network_admin_id );

		$post_id = $this->create_draft( $post_type );
		wp_update_post( array(
			'ID' => $post_id, 'post_status' => $submitted_status,
		) );

		wp_set_current_user( $network_admin_id );
		$this->simulate_edit_form_submission( $post_id );

		$actionable = null;

		add_action(
			'save_post',
			function ( $saved_id, $post ) use ( &$actionable, $post_type ) {
				$actionable = WordCamp_Budgets::post_edit_is_actionable( $post, $post_type );
			},
			10,
			2
		);

		wp_update_post( array(
			'ID' => $post_id, 'post_title' => 'Corrected by Central',
		) );

		$this->assertTrue( $actionable );
	}

	/**
	 * The recorded status only describes the save it belongs to.
	 */
	public function test_recorded_status_does_not_outlive_the_save() {
		$post_id = $this->create_draft( 'wcb_sponsor_invoice' );

		wp_update_post( array(
			'ID' => $post_id, 'post_status' => 'wcbsi_submitted',
		) );

		$this->assertSame(
			'wcbsi_submitted',
			WordCamp_Budgets::get_status_for_edit_check( get_post( $post_id ) )
		);
	}

	/**
	 * A request that isn't being saved reads its stored status.
	 */
	public function test_status_for_edit_check_falls_back_to_the_stored_status() {
		$post_id = $this->create_draft( 'wcb_sponsor_invoice' );

		$this->assertSame( 'draft', WordCamp_Budgets::get_status_for_edit_check( get_post( $post_id ) ) );
	}

	/**
	 * Submitting a sponsor invoice stores the values entered alongside the submission.
	 *
	 * The end-to-end version of the checks above, through the real `save_invoice()` handler: an invoice
	 * submitted straight from the editor has to arrive at Central with its sponsor, community, description,
	 * currency and amount, not as an empty record.
	 */
	public function test_submitting_a_sponsor_invoice_stores_its_fields() {
		$sponsor_id = $this->create_complete_sponsor();
		$invoice_id = $this->create_draft( 'wcb_sponsor_invoice' );

		$this->simulate_edit_form_submission( $invoice_id );

		$_POST['send-invoice']          = 'Send to WordCamp Central';
		$_POST['_wcbsi_sponsor_id']     = (string) $sponsor_id;
		$_POST['_wcbsi_qbo_class_id']   = '42';
		$_POST['_wcbsi_description']    = 'Gold sponsorship';
		$_POST['_wcbsi_currency']       = 'ZAR';
		$_POST['_wcbsi_amount']         = '5000';
		$_POST['status_nonce']          = wp_create_nonce( 'status' );
		$_POST['sponsor_invoice_nonce'] = wp_create_nonce( 'sponsor_invoice' );
		$_REQUEST                       = array_merge( $_REQUEST, $_POST );

		$this->attach_save_handlers();
		$this->attach_status_filters();

		wp_update_post( array(
			'ID' => $invoice_id, 'post_status' => 'draft',
		) );

		$this->assertSame( 'wcbsi_submitted', get_post_status( $invoice_id ) );
		$this->assertSame( (string) $sponsor_id, get_post_meta( $invoice_id, '_wcbsi_sponsor_id', true ) );
		$this->assertSame( '42', get_post_meta( $invoice_id, '_wcbsi_qbo_class_id', true ) );
		$this->assertSame( 'Gold sponsorship', get_post_meta( $invoice_id, '_wcbsi_description', true ) );
		$this->assertSame( 'ZAR', get_post_meta( $invoice_id, '_wcbsi_currency', true ) );
		$this->assertSame( 5000.0, (float) get_post_meta( $invoice_id, '_wcbsi_amount', true ) );
	}

	/**
	 * Create a sponsor with every field `prepare_sponsor_data()` treats as required.
	 *
	 * @return int
	 */
	protected function create_complete_sponsor() {
		$sponsor_id = self::factory()->post->create( array(
			'post_type'   => 'wcb_sponsor',
			'post_status' => 'publish',
			'post_title'  => 'Example Sponsor',
		) );

		$fields = array(
			'company_name'    => 'Example Sponsor',
			'first_name'      => 'Example',
			'last_name'       => 'Person',
			'email_address'   => 'sponsor@example.org',
			'phone_number'    => '555 0100',
			'street_address1' => '123 Example Street',
			'city'            => 'Johannesburg',
			'state'           => 'Gauteng',
			'zip_code'        => '2000',
			'country'         => 'South Africa',
		);

		foreach ( $fields as $field => $value ) {
			update_post_meta( $sponsor_id, "_wcpt_sponsor_$field", $value );
		}

		return $sponsor_id;
	}
}
