<?php

namespace WordCamp\Budgets\Tests;

use WP_Post;
use WP_UnitTestCase;
use WordCamp_Budgets;

defined( 'WPINC' ) || die();

/**
 * Quick Edit and Bulk Edit aren't offered on the budget post types.
 *
 * Quick Edit's status dropdown only knows the core statuses. On a request or an invoice in one of the budget
 * statuses ("Invoice sent to sponsor", "Paid", ...) nothing in it matches, so it shows "Published", and saving
 * moves the post out of its budget status. A sent sponsor invoice then never shows as paid.
 *
 * Bulk Edit has the same dropdown. Even left at "No change", a bulk save of sponsor invoices runs their status
 * filter without the sponsor, which can turn each one into a draft.
 *
 * @see https://github.com/WordPress/wordcamp.org/issues/2441
 *
 * @group budgets
 */
class Test_Quick_And_Bulk_Edit extends WP_UnitTestCase {
	/**
	 * The row actions core gives a post in the list table.
	 */
	const CORE_ROW_ACTIONS = array(
		'edit'                 => '<a href="#">Edit</a>',
		'inline hide-if-no-js' => '<button type="button">Quick&nbsp;Edit</button>',
		'trash'                => '<a href="#">Trash</a>',
	);

	/**
	 * The bulk actions core gives the posts list table.
	 */
	const CORE_BULK_ACTIONS = array(
		'edit'  => 'Edit',
		'trash' => 'Move to Trash',
	);

	/**
	 * Hook the plugin up the way `bootstrap.php` does on a camp site.
	 *
	 * The tests' bootstrap only loads the class. `WP_UnitTestCase` restores the hooks after each test.
	 */
	public function set_up() {
		parent::set_up();

		new WordCamp_Budgets();
	}

	/**
	 * A post of the given type and status, never saved.
	 *
	 * The budget post types change the status on insert, which isn't what these tests are about.
	 *
	 * @param string $post_type
	 * @param string $post_status
	 *
	 * @return WP_Post
	 */
	protected function make_post( $post_type, $post_status ) {
		return new WP_Post( (object) array(
			'ID'          => 123,
			'post_type'   => $post_type,
			'post_status' => $post_status,
			'filter'      => 'raw',
		) );
	}

	/**
	 * The budget post types, each in a status Quick Edit doesn't know.
	 *
	 * @return array[]
	 */
	public function data_budget_posts() {
		return array(
			'sent sponsor invoice'          => array( 'wcb_sponsor_invoice', 'wcbsi_approved' ),
			'paid sponsor invoice'          => array( 'wcb_sponsor_invoice', 'wcbsi_paid' ),
			'approved vendor payment'       => array( 'wcp_payment_request', 'wcb-approved' ),
			'approved reimbursement'        => array( 'wcb_reimbursement', 'wcb-approved' ),
		);
	}

	/**
	 * The list table has no Quick Edit link on a budget post.
	 *
	 * @dataProvider data_budget_posts
	 *
	 * @covers \WordCamp_Budgets::remove_quick_edit
	 *
	 * @param string $post_type
	 * @param string $post_status
	 */
	public function test_budget_posts_have_no_quick_edit( $post_type, $post_status ) {
		$actions = apply_filters( 'post_row_actions', self::CORE_ROW_ACTIONS, $this->make_post( $post_type, $post_status ) );

		$this->assertArrayNotHasKey( 'inline hide-if-no-js', $actions );
	}

	/**
	 * The other row actions stay.
	 *
	 * @covers \WordCamp_Budgets::remove_quick_edit
	 */
	public function test_other_row_actions_stay() {
		$actions = apply_filters( 'post_row_actions', self::CORE_ROW_ACTIONS, $this->make_post( 'wcb_sponsor_invoice', 'wcbsi_approved' ) );

		$this->assertSame( array( 'edit', 'trash' ), array_keys( $actions ) );
	}

	/**
	 * Other post types keep Quick Edit.
	 *
	 * @covers \WordCamp_Budgets::remove_quick_edit
	 */
	public function test_other_post_types_keep_quick_edit() {
		$actions = apply_filters( 'post_row_actions', self::CORE_ROW_ACTIONS, $this->make_post( 'post', 'publish' ) );

		$this->assertSame( self::CORE_ROW_ACTIONS, $actions );
	}

	/**
	 * The budget post types.
	 *
	 * @return array[]
	 */
	public function data_budget_post_types() {
		return array(
			'sponsor invoices' => array( 'wcb_sponsor_invoice' ),
			'vendor payments'  => array( 'wcp_payment_request' ),
			'reimbursements'   => array( 'wcb_reimbursement' ),
		);
	}

	/**
	 * The list table's bulk actions have no Edit on a budget post type, and still have Move to Trash.
	 *
	 * @dataProvider data_budget_post_types
	 *
	 * @covers \WordCamp_Budgets::remove_bulk_edit
	 *
	 * @param string $post_type
	 */
	public function test_budget_post_types_have_no_bulk_edit( $post_type ) {
		$actions = apply_filters( "bulk_actions-edit-$post_type", self::CORE_BULK_ACTIONS ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- core's hook name.

		$this->assertSame( array( 'trash' => 'Move to Trash' ), $actions );
	}

	/**
	 * Other post types keep Bulk Edit.
	 *
	 * @covers \WordCamp_Budgets::remove_bulk_edit
	 */
	public function test_other_post_types_keep_bulk_edit() {
		$actions = apply_filters( 'bulk_actions-edit-post', self::CORE_BULK_ACTIONS ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- core's hook name.

		$this->assertSame( self::CORE_BULK_ACTIONS, $actions );
	}
}
