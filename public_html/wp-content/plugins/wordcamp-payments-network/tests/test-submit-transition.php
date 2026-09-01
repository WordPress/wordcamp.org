<?php

namespace WordCamp\Budgets_Dashboard\Tests;

use WP_UnitTestCase, WP_Post, WordCamp_Budgets;

defined( 'WPINC' ) || die();

/**
 * What a budget request's `save_post` handler may do as the request is submitted, and what it still may not.
 *
 * Submitting moves a request out of the statuses its requester may edit, and the row carries the new status by
 * the time `save_post` runs. The editability checks have to read the status the request held before that save,
 * or the handler that writes the values entered alongside the submission is refused and the request arrives
 * empty. They must keep refusing everything else, which is what the second half of this file is about.
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
	 * An Editor on the subsite: holds `edit_others_posts`, holds no network capability.
	 *
	 * @var int
	 */
	protected static $editor_id;

	/**
	 * How each budget CPT is submitted, and what it becomes.
	 *
	 * The request body key is the one the CPT's own `wp_insert_post_data` callback looks for.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	const SUBMISSIONS = array(
		'wcb_sponsor_invoice' => array( 'wcbsi_submitted', 'send-invoice' ),
		'wcp_payment_request' => array( 'wcb-pending-approval', 'wcb-update' ),
		'wcb_reimbursement'   => array( 'wcb-pending-approval', 'wcb-update' ),
	);

	/**
	 * A status past submission, which only a network admin may act on.
	 *
	 * @var string
	 */
	const APPROVED_STATUS = 'wcb-approved';

	/**
	 * Create the shared users.
	 *
	 * @param \WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$requester_id = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$editor_id    = $factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * Fixtures are built with the hooks off, because the `save_post` handlers run nonce checks that `wp_die()`
	 * outside a real request, and the status filters would rewrite a status stored directly. Tests attach back
	 * whichever half they are about.
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
	 * Put the hooks back, so other suites are unaffected.
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
	 * Detach the `wp_insert_post_data` callbacks that set each CPT's status.
	 *
	 * These are also where the pre-save status is recorded, so a test about the submit transition has to leave
	 * them attached -- only fixture building turns them off.
	 */
	protected function detach_status_filters() {
		remove_filter( 'wp_insert_post_data', 'WordCamp\Budgets\Sponsor_Invoices\set_invoice_status', 10 );
		remove_filter( 'wp_insert_post_data', 'WordCamp\Budgets\Reimbursement_Requests\set_request_status', 10 );
		remove_filter( 'wp_insert_post_data', array( $GLOBALS['wcp_payment_request'], 'wp_insert_post_data' ), 10 );
	}

	/**
	 * Re-attach the `wp_insert_post_data` callbacks detached above.
	 */
	protected function attach_status_filters() {
		add_filter( 'wp_insert_post_data', 'WordCamp\Budgets\Sponsor_Invoices\set_invoice_status', 10, 2 );
		add_filter( 'wp_insert_post_data', 'WordCamp\Budgets\Reimbursement_Requests\set_request_status', 10, 2 );
		add_filter( 'wp_insert_post_data', array( $GLOBALS['wcp_payment_request'], 'wp_insert_post_data' ), 10, 2 );
	}

	/**
	 * Create a stored request of the given type and status, authored by the requester.
	 *
	 * @param string $post_type
	 * @param string $post_status
	 *
	 * @return int
	 */
	protected function create_request( $post_type, $post_status = 'draft' ) {
		return self::factory()->post->create( array(
			'post_type'   => $post_type,
			'post_status' => $post_status,
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
		$GLOBALS['post'] = get_post( $post_id );

		$_REQUEST['action'] = 'editpost';
		$_POST['action']    = 'editpost';
	}

	/**
	 * Run the save that submits a request, the way `edit_post()` does: request body first, then the same array
	 * handed to `wp_update_post()`.
	 *
	 * @param int    $post_id
	 * @param string $post_type
	 * @param array  $extra_fields Field values entered alongside the submission.
	 */
	protected function submit_request( $post_id, $post_type, $extra_fields = array() ) {
		list( , $submit_key ) = self::SUBMISSIONS[ $post_type ];

		$this->simulate_edit_form_submission( $post_id );

		$_POST[ $submit_key ] = '1';
		$_POST                = array_merge( $_POST, $this->default_submission_fields( $post_type ), $extra_fields );
		$_REQUEST             = array_merge( $_REQUEST, $_POST );

		$this->attach_status_filters();

		wp_update_post( array_merge( $_POST, array( 'ID' => $post_id ) ) );
	}

	/**
	 * The request body a complete submission carries.
	 *
	 * A sponsor invoice is only promoted when its sponsor and its own fields are complete, so a test about the
	 * transition has to send a submission the CPT would actually accept. The other two need nothing beyond the
	 * button.
	 *
	 * @param string $post_type
	 *
	 * @return array
	 */
	protected function default_submission_fields( $post_type ) {
		if ( 'wcb_sponsor_invoice' !== $post_type ) {
			return array();
		}

		return array(
			'_wcbsi_sponsor_id'     => (string) $this->create_complete_sponsor(),
			'_wcbsi_qbo_class_id'   => '42',
			'_wcbsi_description'    => 'Gold sponsorship',
			'_wcbsi_currency'       => 'ZAR',
			'_wcbsi_amount'         => '5000',
			'status_nonce'          => wp_create_nonce( 'status' ),
			'sponsor_invoice_nonce' => wp_create_nonce( 'sponsor_invoice' ),
		);
	}

	/**
	 * Watch what `post_edit_is_actionable()` answers inside `save_post`, which is where the handlers ask it.
	 *
	 * @param string $post_type
	 * @param mixed  $answer     Set by reference when the save runs.
	 */
	protected function watch_save_post_gate( $post_type, &$answer ) {
		add_action(
			'save_post',
			function ( $saved_id, $post ) use ( &$answer, $post_type ) {
				$answer = WordCamp_Budgets::post_edit_is_actionable( $post, $post_type );
			},
			10,
			2
		);
	}

	/**
	 * @return array<string, array>
	 */
	public function data_budget_post_types() {
		$data = array();

		foreach ( self::SUBMISSIONS as $post_type => $submission ) {
			$data[ $post_type ] = array( $post_type, $submission[0] );
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
		$post_id    = $this->create_request( $post_type );
		$actionable = null;

		$this->watch_save_post_gate( $post_type, $actionable );
		$this->submit_request( $post_id, $post_type );

		$this->assertSame( $submitted_status, get_post_status( $post_id ), 'the save stored the new status' );
		$this->assertTrue( $actionable, 'the gate the save handler sees' );
	}

	/**
	 * A request that was already past the editable statuses before the save stays closed to its requester.
	 *
	 * @dataProvider data_budget_post_types
	 *
	 * @param string $post_type
	 * @param string $submitted_status
	 */
	public function test_save_handler_may_not_act_on_an_already_submitted_request( $post_type, $submitted_status ) {
		$post_id    = $this->create_request( $post_type, $submitted_status );
		$actionable = null;

		$this->watch_save_post_gate( $post_type, $actionable );
		$this->submit_request( $post_id, $post_type, array( 'post_title' => 'Edited after submission' ) );

		$this->assertFalse( $actionable );
	}

	/**
	 * Nor may its requester act on one that has been approved for payout.
	 *
	 * @dataProvider data_budget_post_types
	 *
	 * @param string $post_type
	 */
	public function test_save_handler_may_not_act_on_an_approved_request( $post_type ) {
		$post_id    = $this->create_request( $post_type, self::APPROVED_STATUS );
		$actionable = null;

		$this->watch_save_post_gate( $post_type, $actionable );
		$this->submit_request( $post_id, $post_type, array( 'post_title' => 'Edited after approval' ) );

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

		$post_id    = $this->create_request( $post_type, $submitted_status );
		$actionable = null;

		wp_set_current_user( $network_admin_id );

		$this->watch_save_post_gate( $post_type, $actionable );
		$this->submit_request( $post_id, $post_type, array( 'post_title' => 'Corrected by Central' ) );

		$this->assertTrue( $actionable );
	}

	/**
	 * An Editor is refused a non-draft request however the capability check names it.
	 *
	 * The control from the report that #1966 answered: the reservation must not depend on the argument's type,
	 * nor on which post happens to be in the global. Repeated here because this file changes what the same
	 * filters read.
	 *
	 * @dataProvider data_budget_post_types
	 *
	 * @param string $post_type
	 */
	public function test_editor_is_refused_a_non_draft_request_in_every_argument_form( $post_type ) {
		$post_id = $this->create_request( $post_type, self::APPROVED_STATUS );

		/*
		 * The context the report measured: `wp_ajax_upload_attachment()`, which passes `$_REQUEST['post_id']`
		 * to `current_user_can()` uncast. The reservation reads this to tell opening a request apart from
		 * writing to one, so a test that leaves it unset is not asking the question.
		 */
		$_REQUEST['action'] = 'upload-attachment';

		// A post of another type, as that endpoint and the list tables leave it.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- standing in for an unrelated screen.
		$GLOBALS['post'] = get_post( self::factory()->post->create() );

		wp_set_current_user( self::$editor_id );

		$this->assertTrue( current_user_can( 'edit_others_posts' ), 'the Editor holds the generic primitive' );
		$this->assertFalse( current_user_can( 'manage_network' ), 'and holds no network capability' );

		foreach ( array( 'edit_post', 'delete_post' ) as $capability ) {
			$this->assertFalse( current_user_can( $capability, $post_id ), "$capability, int" );
			$this->assertFalse( current_user_can( $capability, (string) $post_id ), "$capability, numeric string" );
			$this->assertFalse( current_user_can( $capability, get_post( $post_id ) ), "$capability, WP_Post" );
		}
	}

	/**
	 * Submitting one request does not open a different one.
	 *
	 * The recorded status is keyed by post, so the save that submits the requester's own draft must not change
	 * the answer for somebody else's approved request while it runs.
	 *
	 * @dataProvider data_budget_post_types
	 *
	 * @param string $post_type
	 */
	public function test_submitting_one_request_does_not_open_another( $post_type ) {
		$own_draft         = $this->create_request( $post_type );
		$approved_id       = $this->create_request( $post_type, self::APPROVED_STATUS );
		$verdict_for_own   = null;
		$verdict_for_other = null;

		add_action(
			'save_post',
			function () use ( &$verdict_for_own, &$verdict_for_other, $own_draft, $approved_id ) {
				$verdict_for_own   = current_user_can( 'edit_post', $own_draft );
				$verdict_for_other = current_user_can( 'edit_post', $approved_id );
			},
			10
		);

		$this->submit_request( $own_draft, $post_type );

		$this->assertTrue( $verdict_for_own, 'the request being submitted' );
		$this->assertFalse( $verdict_for_other, 'a different, approved request' );
	}

	/**
	 * The recorded status is not honoured outside the `save_post` it belongs to.
	 *
	 * `wp_after_insert_post` clears it, but a caller passing `$fire_after_hooks = false` skips that hook, so an
	 * entry can outlive its save. It must not widen anything when it does.
	 */
	public function test_recorded_status_is_only_honoured_inside_a_save() {
		$post_id = $this->create_request( 'wcb_sponsor_invoice' );

		add_action(
			'save_post',
			function () {
				// Skip the cleanup, the way a `$fire_after_hooks = false` caller does.
				remove_action( 'wp_after_insert_post', array( 'WordCamp_Budgets', 'forget_status_before_save' ), PHP_INT_MAX );
			},
			5
		);

		$this->submit_request( $post_id, 'wcb_sponsor_invoice' );

		$this->assertSame( 'wcbsi_submitted', get_post_status( $post_id ), 'the request was submitted' );
		$this->assertSame(
			'wcbsi_submitted',
			WordCamp_Budgets::get_status_for_edit_check( get_post( $post_id ) ),
			'and reads as submitted once the save is over'
		);
		$this->assertFalse( current_user_can( 'edit_post', $post_id ) );
	}

	/**
	 * A request that isn't being saved reads its stored status.
	 */
	public function test_status_for_edit_check_falls_back_to_the_stored_status() {
		$post_id = $this->create_request( 'wcb_sponsor_invoice' );

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
		$invoice_id = $this->create_request( 'wcb_sponsor_invoice' );

		$this->attach_save_handlers();

		$entered_fields = array(
			'_wcbsi_sponsor_id'   => (string) $sponsor_id,
			'_wcbsi_qbo_class_id' => '42',
			'_wcbsi_description'  => 'Gold sponsorship',
			'_wcbsi_currency'     => 'ZAR',
			'_wcbsi_amount'       => '5000',
		);

		$this->submit_request( $invoice_id, 'wcb_sponsor_invoice', $entered_fields );

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
