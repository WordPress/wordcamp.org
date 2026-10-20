<?php

namespace WordCamp\Budgets\Tests;

use WP_UnitTestCase;
use WordCamp\Budgets\Reimbursement_Requests;

defined( 'WPINC' ) || die();

/**
 * What the save handler is allowed to write, and what it decides that from.
 *
 * A reimbursement request is only editable by the organizer who filed it while it's still a draft, or marked
 * incomplete. Past that it belongs to the reviewers, and the fields on it are what a payment gets made from.
 * These pin where that line is read.
 *
 * @group budgets
 */
class Test_Reimbursement_Request extends WP_UnitTestCase {
	/** @var int The organizer who files the request. */
	protected static $organizer;

	/**
	 * @param \WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$organizer = $factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * Clear the request the tests fill, so one test's body can't reach the next.
	 */
	public function tear_down() {
		unset( $_REQUEST['action'] );
		$_POST    = array();
		$_REQUEST = array();

		parent::tear_down();
	}

	/**
	 * Fill the request body the Edit screen posts, with valid nonces for the current user.
	 *
	 * @param array $overrides Values to add or replace.
	 */
	protected function populate_request( array $overrides = array() ) {
		$nonces = array(
			'status_nonce'              => 'status',
			'notes_nonce'               => 'notes',
			'general_information_nonce' => 'general_information',
			'payment_details_nonce'     => 'payment_details',
			'expenses_nonce'            => 'expenses',
		);

		$_POST = array(
			'wcbrr_new_note'       => '',
			'wcbrr-expenses-data'  => '[]',
			'_wcbrr_name_of_payer' => 'Original Payer',
			'_wcbrr_currency'      => 'USD',
			'_wcbrr_reason'        => 'other',
			'_wcbrr_reason_other'  => '',
		);

		foreach ( $nonces as $field => $action ) {
			$_POST[ $field ] = wp_create_nonce( $action );
		}

		$_POST = array_merge( $_POST, $overrides );

		// `post.php` submits as `editpost`, which is what tells this post type's `map_meta_cap` callback that
		// the request is being written to rather than opened.
		$_REQUEST           = $_POST;
		$_REQUEST['action'] = 'editpost';
	}

	/**
	 * Create a request owned by the organizer, at a given status.
	 *
	 * @param string $status
	 *
	 * @return int
	 */
	protected function create_request( $status ) {
		return self::factory()->post->create( array(
			'post_type'   => Reimbursement_Requests\POST_TYPE,
			'post_author' => self::$organizer,
			'post_status' => $status,
		) );
	}

	/**
	 * The status the handler reads is the stored one, not one named in the request body.
	 *
	 * The body carries a field holding the status the request had before the save, which the handler used to
	 * take this decision from. A draft is editable whatever that field says.
	 */
	public function test_editable_request_is_saved_whatever_the_body_claims() {
		wp_set_current_user( self::$organizer );

		$post_id = $this->create_request( 'draft' );

		$this->populate_request( array(
			'original_post_status' => 'wcb-paid',
			'_wcbrr_name_of_payer' => 'Saved Payer',
		) );

		Reimbursement_Requests\save_request( $post_id, get_post( $post_id ) );

		$this->assertSame( 'Saved Payer', get_post_meta( $post_id, '_wcbrr_name_of_payer', true ) );
	}

	/**
	 * And the other way round: naming an editable status doesn't make a request editable.
	 */
	public function test_request_past_the_editable_statuses_is_not_saved() {
		wp_set_current_user( self::$organizer );

		$post_id = $this->create_request( 'wcb-approved' );

		update_post_meta( $post_id, '_wcbrr_name_of_payer', 'Original Payer' );

		$this->populate_request( array(
			'original_post_status' => 'draft',
			'_wcbrr_name_of_payer' => 'Rewritten Payer',
		) );

		Reimbursement_Requests\save_request( $post_id, get_post( $post_id ) );

		$this->assertSame( 'Original Payer', get_post_meta( $post_id, '_wcbrr_name_of_payer', true ) );
	}

	/**
	 * A request marked incomplete goes back to the organizer to correct, so it's editable again.
	 */
	public function test_incomplete_request_is_saved() {
		wp_set_current_user( self::$organizer );

		$post_id = $this->create_request( 'wcb-incomplete' );

		$this->populate_request( array( '_wcbrr_name_of_payer' => 'Corrected Payer' ) );

		Reimbursement_Requests\save_request( $post_id, get_post( $post_id ) );

		$this->assertSame( 'Corrected Payer', get_post_meta( $post_id, '_wcbrr_name_of_payer', true ) );
	}
}
