<?php

namespace WordCamp\Budgets\Tests;

use WP_Post;
use WP_UnitTestCase;
use WordCamp_Budgets;

defined( 'WPINC' ) || die();

/**
 * Quick Edit isn't offered on the budget post types.
 *
 * Quick Edit's status dropdown only knows the core statuses. On a request or an invoice in one of the budget
 * statuses ("Invoice sent to sponsor", "Paid", ...) nothing in it matches, so it shows "Published", and saving
 * moves the post out of its budget status. A sent sponsor invoice then never shows as paid.
 *
 * @see https://github.com/WordPress/wordcamp.org/issues/2441
 *
 * @group budgets
 */
class Test_Quick_Edit extends WP_UnitTestCase {
	/**
	 * The row actions core gives a post in the list table.
	 */
	const CORE_ROW_ACTIONS = array(
		'edit'                 => '<a href="#">Edit</a>',
		'inline hide-if-no-js' => '<button type="button">Quick&nbsp;Edit</button>',
		'trash'                => '<a href="#">Trash</a>',
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
}
