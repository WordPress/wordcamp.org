<?php

namespace WordCamp\Budgets_Dashboard\Tests;

use WP_UnitTestCase;
use WordCamp_Budgets;

defined( 'WPINC' ) || die();

/**
 * The capability model the three budget CPTs register with.
 *
 * The list, creation and deletion capabilities of `wcb_reimbursement`, `wcp_payment_request` and
 * `wcb_sponsor_invoice` are mapped to `WordCamp_Budgets::VIEWER_CAP`, so the list tables, the New screen and
 * the delete action require the same capability as the Budget menu they live under rather than the generic
 * post capabilities. `map_meta_cap` stays enabled so the per-record `edit_post`/`delete_post` checks keep
 * routing through the plugin's `modify_capabilities()` logic.
 *
 * These lock down that map: the capability strings themselves, that meta-cap mapping is still on, and the
 * resulting per-role outcome for a record its author still has in draft.
 *
 * @group budgets-dashboard
 */
class Test_Post_Type_Capabilities extends WP_UnitTestCase {
	/**
	 * The three budget CPTs.
	 *
	 * @var string[]
	 */
	const POST_TYPES = array( 'wcb_reimbursement', 'wcp_payment_request', 'wcb_sponsor_invoice' );

	/**
	 * Store fixtures at an arbitrary status without the status rules the plugin runs on insert, so a draft
	 * fixture is genuinely a draft. Restored in `tear_down()` so other suites are unaffected.
	 */
	public function set_up() {
		parent::set_up();

		remove_action( 'save_post', 'WordCamp\Budgets\Reimbursement_Requests\save_request', 10 );
		remove_action( 'save_post', 'WordCamp\Budgets\Sponsor_Invoices\save_invoice', 10 );
		remove_action( 'save_post', array( $GLOBALS['wcp_payment_request'], 'save_payment' ), 10 );

		remove_filter( 'wp_insert_post_data', 'WordCamp\Budgets\Reimbursement_Requests\set_request_status', 10 );
		remove_filter( 'wp_insert_post_data', 'WordCamp\Budgets\Sponsor_Invoices\set_invoice_status', 10 );
		remove_filter( 'wp_insert_post_data', array( $GLOBALS['wcp_payment_request'], 'wp_insert_post_data' ), 10 );
	}

	/**
	 * Re-attach the handlers detached in `set_up()`.
	 */
	public function tear_down() {
		add_action( 'save_post', 'WordCamp\Budgets\Reimbursement_Requests\save_request', 10, 2 );
		add_action( 'save_post', 'WordCamp\Budgets\Sponsor_Invoices\save_invoice', 10, 2 );
		add_action( 'save_post', array( $GLOBALS['wcp_payment_request'], 'save_payment' ), 10, 2 );

		add_filter( 'wp_insert_post_data', 'WordCamp\Budgets\Reimbursement_Requests\set_request_status', 10, 2 );
		add_filter( 'wp_insert_post_data', 'WordCamp\Budgets\Sponsor_Invoices\set_invoice_status', 10, 2 );
		add_filter( 'wp_insert_post_data', array( $GLOBALS['wcp_payment_request'], 'wp_insert_post_data' ), 10, 2 );

		parent::tear_down();
	}

	/**
	 * Each budget CPT, as a data provider.
	 *
	 * @return array
	 */
	public function data_budget_post_types() {
		return array(
			'reimbursement request' => array( 'wcb_reimbursement' ),
			'vendor payment'        => array( 'wcp_payment_request' ),
			'sponsor invoice'       => array( 'wcb_sponsor_invoice' ),
		);
	}

	/**
	 * The list, creation and deletion capabilities are mapped to the Budget viewer capability.
	 *
	 * @dataProvider data_budget_post_types
	 *
	 * @param string $post_type
	 */
	public function test_list_capabilities_require_viewer_cap( $post_type ) {
		$cap = get_post_type_object( $post_type )->cap;

		$this->assertSame( WordCamp_Budgets::VIEWER_CAP, $cap->edit_posts,   "$post_type edit_posts" );
		$this->assertSame( WordCamp_Budgets::VIEWER_CAP, $cap->create_posts, "$post_type create_posts" );
		$this->assertSame( WordCamp_Budgets::VIEWER_CAP, $cap->delete_posts, "$post_type delete_posts" );
	}

	/**
	 * The capabilities that aren't in the map keep their generic defaults, so nothing outside the list,
	 * create and delete paths shifts.
	 *
	 * @dataProvider data_budget_post_types
	 *
	 * @param string $post_type
	 */
	public function test_remaining_capabilities_are_unchanged( $post_type ) {
		$cap = get_post_type_object( $post_type )->cap;

		$this->assertSame( 'edit_others_posts',    $cap->edit_others_posts,    "$post_type edit_others_posts" );
		$this->assertSame( 'edit_published_posts', $cap->edit_published_posts, "$post_type edit_published_posts" );
		$this->assertSame( 'delete_others_posts',  $cap->delete_others_posts,  "$post_type delete_others_posts" );
		$this->assertSame( 'read_private_posts',   $cap->read_private_posts,   "$post_type read_private_posts" );
	}

	/**
	 * Meta-cap mapping stays enabled. Supplying `capabilities` disables the mapping Core turns on by default
	 * for a `post`-based type; without it the per-record `edit_post`/`delete_post` checks would map to
	 * literal primitives no role holds, locking everyone out. This is the regression that has no other guard.
	 *
	 * @dataProvider data_budget_post_types
	 *
	 * @param string $post_type
	 */
	public function test_map_meta_cap_is_enabled( $post_type ) {
		$this->assertTrue( get_post_type_object( $post_type )->map_meta_cap, "$post_type map_meta_cap" );
	}

	/**
	 * Whether the author of a still-draft record can edit and delete it, by role.
	 *
	 * A draft is the case a record's author can still act on, so it's where losing the viewer capability
	 * shows. Contributor is the whole delta: it holds the generic `edit_posts`/`delete_posts` the types used
	 * to inherit, but not `publish_posts`, so once the map routes through the viewer capability a contributor
	 * can no longer manage a payment record, while author and above still can.
	 *
	 * @return array role => can-manage-own-draft.
	 */
	public function data_owner_role_expectations() {
		return array(
			'administrator' => array( 'administrator', true ),
			'editor'        => array( 'editor', true ),
			'author'        => array( 'author', true ),
			'contributor'   => array( 'contributor', false ),
			'subscriber'    => array( 'subscriber', false ),
		);
	}

	/**
	 * @dataProvider data_owner_role_expectations
	 *
	 * @param string $role
	 * @param bool   $can_manage
	 */
	public function test_owner_can_manage_own_draft_by_role( $role, $can_manage ) {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );

		foreach ( self::POST_TYPES as $post_type ) {
			$draft = self::factory()->post->create( array(
				'post_type'   => $post_type,
				'post_status' => 'draft',
				'post_author' => $user_id,
			) );

			$this->assertSame(
				'draft',
				get_post_status( $draft ),
				"Fixture for $post_type wasn't stored as a draft."
			);

			$this->assertSame(
				$can_manage,
				current_user_can( 'edit_post', $draft ),
				"A $role editing their own draft $post_type."
			);

			$this->assertSame(
				$can_manage,
				current_user_can( 'delete_post', $draft ),
				"A $role deleting their own draft $post_type."
			);
		}
	}
}
