<?php

namespace WordCamp\Budgets_Dashboard\Tests;

use WP_UnitTestCase;
use WordCamp_Budgets;

defined( 'WPINC' ) || die();

/**
 * Who may edit and delete a budget CPT record once it's left draft.
 *
 * Each of the three budget CPTs adds `manage_network` to the capabilities required for `edit_post` and
 * `delete_post` once a record is past draft, so that requests queued for payout are only changed by network
 * admins. All three resolve the record they're deciding about through
 * `WordCamp_Budgets::get_map_meta_cap_post()`, so these assert the outcome for every form a post can arrive
 * in -- an int ID, a numeric string ID (what Core's `wp_ajax_upload_attachment()` passes), and a `WP_Post`
 * -- and regardless of what the global `$post` is set to at the time.
 *
 * @group budgets-dashboard
 */
class Test_Map_Meta_Cap extends WP_UnitTestCase {
	/**
	 * The organizer who filed the request. Not a network admin.
	 *
	 * @var int
	 */
	protected static $requester_id;

	/**
	 * Another organizer on the same site, holding `edit_others_posts` and `delete_others_posts` but not
	 * `manage_network`.
	 *
	 * @var int
	 */
	protected static $editor_id;

	/**
	 * A network administrator: may edit and delete requests at any status.
	 *
	 * @var int
	 */
	protected static $network_admin_id;

	/**
	 * The budget CPTs, and a post status for each that's past draft.
	 *
	 * @var array
	 */
	const NON_DRAFT_STATUSES = array(
		'wcb_reimbursement'   => 'wcb-approved',
		'wcp_payment_request' => 'wcb-approved',
		'wcb_sponsor_invoice' => 'wcbsi_submitted',
	);

	/**
	 * Create the shared users.
	 *
	 * @param \WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$requester_id     = $factory->user->create( array( 'role' => 'editor' ) );
		self::$editor_id        = $factory->user->create( array( 'role' => 'editor' ) );
		self::$network_admin_id = $factory->user->create( array( 'role' => 'administrator' ) );

		grant_super_admin( self::$network_admin_id );
	}

	/**
	 * Put the request into the shape the `map_meta_cap` callbacks read it in.
	 *
	 * They treat any `action` other than `edit` as saving rather than as opening the edit screen, so
	 * `editpost` is what makes them evaluate the `edit_post` rule at all. The `save_post` and
	 * `wp_insert_post_data` handlers are detached so the fixtures below can be stored at an arbitrary status
	 * without running the nonce checks and status rules that other tests cover, and restored in
	 * `tear_down()` so other suites are unaffected.
	 */
	public function set_up() {
		parent::set_up();

		$_POST    = array();
		$_REQUEST = array( 'action' => 'editpost' );

		unset( $GLOBALS['post'] );

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

		unset( $GLOBALS['post'] );

		parent::tear_down();
	}

	/**
	 * Create a stored request of the given CPT, authored by the requester, at a status past draft.
	 *
	 * @param string $post_type
	 *
	 * @return int
	 */
	protected function create_non_draft( $post_type ) {
		return self::factory()->post->create( array(
			'post_type'   => $post_type,
			'post_status' => self::NON_DRAFT_STATUSES[ $post_type ],
			'post_author' => self::$requester_id,
		) );
	}

	/**
	 * The forms a post can reach a `map_meta_cap` callback in.
	 *
	 * @param int $post_id
	 *
	 * @return array Label => value.
	 */
	protected function argument_forms( $post_id ) {
		return array(
			'int ID'    => $post_id,
			'string ID' => (string) $post_id,
			'WP_Post'   => get_post( $post_id ),
		);
	}

	/**
	 * Set the global `$post`, which several of these assert isn't consulted.
	 *
	 * @param int $post_id
	 */
	protected function set_global_post( $post_id ) {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- this is the state under test.
		$GLOBALS['post'] = get_post( $post_id );
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
	 * An editor can't edit another organizer's request once it's past draft, however the post reaches the
	 * capability check.
	 *
	 * @dataProvider data_budget_post_types
	 *
	 * @param string $post_type
	 */
	public function test_editor_cannot_edit_non_draft_request( $post_type ) {
		$request = $this->create_non_draft( $post_type );

		wp_set_current_user( self::$editor_id );

		foreach ( $this->argument_forms( $request ) as $label => $argument ) {
			$this->assertFalse(
				current_user_can( 'edit_post', $argument ),
				"An editor was allowed to edit a non-draft $post_type passed as a $label."
			);
		}
	}

	/**
	 * The same, for deleting.
	 *
	 * @dataProvider data_budget_post_types
	 *
	 * @param string $post_type
	 */
	public function test_editor_cannot_delete_non_draft_request( $post_type ) {
		$request = $this->create_non_draft( $post_type );

		wp_set_current_user( self::$editor_id );

		foreach ( $this->argument_forms( $request ) as $label => $argument ) {
			$this->assertFalse(
				current_user_can( 'delete_post', $argument ),
				"An editor was allowed to delete a non-draft $post_type passed as a $label."
			);
		}
	}

	/**
	 * The outcome is decided from the post the check names, not from whatever the global `$post` is.
	 *
	 * @dataProvider data_budget_post_types
	 *
	 * @param string $post_type
	 */
	public function test_unrelated_global_post_is_not_consulted( $post_type ) {
		$request = $this->create_non_draft( $post_type );

		$this->set_global_post( self::factory()->post->create( array(
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_author' => self::$editor_id,
		) ) );

		wp_set_current_user( self::$editor_id );

		foreach ( $this->argument_forms( $request ) as $label => $argument ) {
			$this->assertFalse(
				current_user_can( 'edit_post', $argument ),
				"An unrelated global \$post let an editor edit a non-draft $post_type passed as a $label."
			);

			$this->assertFalse(
				current_user_can( 'delete_post', $argument ),
				"An unrelated global \$post let an editor delete a non-draft $post_type passed as a $label."
			);
		}
	}

	/**
	 * Conversely, a budget record in the global `$post` doesn't affect checks about a different post.
	 *
	 * @dataProvider data_budget_post_types
	 *
	 * @param string $post_type
	 */
	public function test_global_budget_post_does_not_affect_an_unrelated_post( $post_type ) {
		$this->set_global_post( $this->create_non_draft( $post_type ) );

		$blog_post = self::factory()->post->create( array(
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_author' => self::$editor_id,
		) );

		wp_set_current_user( self::$editor_id );

		$this->assertTrue( current_user_can( 'edit_post', $blog_post ) );
		$this->assertTrue( current_user_can( 'edit_post', (string) $blog_post ) );
	}

	/**
	 * A network admin can still edit and delete a request past draft -- `manage_network` is what's required,
	 * not a blanket denial.
	 *
	 * @dataProvider data_budget_post_types
	 *
	 * @param string $post_type
	 */
	public function test_network_admin_can_edit_and_delete_non_draft_request( $post_type ) {
		$request = $this->create_non_draft( $post_type );

		wp_set_current_user( self::$network_admin_id );

		foreach ( $this->argument_forms( $request ) as $label => $argument ) {
			$this->assertTrue(
				current_user_can( 'edit_post', $argument ),
				"A network admin was refused editing a non-draft $post_type passed as a $label."
			);

			$this->assertTrue(
				current_user_can( 'delete_post', $argument ),
				"A network admin was refused deleting a non-draft $post_type passed as a $label."
			);
		}
	}

	/**
	 * A request that's still a draft isn't restricted, so organizers can keep working on their own.
	 *
	 * @dataProvider data_budget_post_types
	 *
	 * @param string $post_type
	 */
	public function test_draft_request_is_not_restricted( $post_type ) {
		$draft = self::factory()->post->create( array(
			'post_type'   => $post_type,
			'post_status' => 'draft',
			'post_author' => self::$requester_id,
		) );

		wp_set_current_user( self::$requester_id );

		foreach ( $this->argument_forms( $draft ) as $label => $argument ) {
			$this->assertTrue(
				current_user_can( 'edit_post', $argument ),
				"An organizer was refused editing their own draft $post_type passed as a $label."
			);
		}
	}

	/**
	 * An argument that doesn't name a post is refused.
	 *
	 * @dataProvider data_budget_post_types
	 *
	 * @param string $post_type
	 */
	public function test_unresolvable_post_is_refused( $post_type ) {
		// A restricted record in the global $post, to confirm it isn't consulted for an argument that names
		// some other post.
		$this->set_global_post( $this->create_non_draft( $post_type ) );

		wp_set_current_user( self::$editor_id );

		$this->assertFalse( current_user_can( 'edit_post', 'not-a-post-id' ) );
		$this->assertFalse( current_user_can( 'delete_post', 'not-a-post-id' ) );
		$this->assertFalse( current_user_can( 'edit_post', PHP_INT_MAX ) );
	}

	/**
	 * The helper resolves every form of the argument, and prefers it over the global `$post`.
	 */
	public function test_get_map_meta_cap_post_resolves_every_argument_form() {
		$request    = $this->create_non_draft( 'wcp_payment_request' );
		$other_post = self::factory()->post->create( array( 'post_type' => 'post' ) );

		$this->set_global_post( $other_post );

		$this->assertSame( $request, WordCamp_Budgets::get_map_meta_cap_post( array( $request ) )->ID );
		$this->assertSame( $request, WordCamp_Budgets::get_map_meta_cap_post( array( (string) $request ) )->ID );
		$this->assertSame( $request, WordCamp_Budgets::get_map_meta_cap_post( array( get_post( $request ) ) )->ID );

		// No argument, so the global is the only thing left to go on.
		$this->assertSame( $other_post, WordCamp_Budgets::get_map_meta_cap_post( array() )->ID );

		// An argument that names nothing resolvable doesn't fall back to the global.
		$this->assertNull( WordCamp_Budgets::get_map_meta_cap_post( array( 'not-a-post-id' ) ) );
		$this->assertNull( WordCamp_Budgets::get_map_meta_cap_post( array( PHP_INT_MAX ) ) );
	}

	/**
	 * The helper doesn't create a global `$post` when there isn't one, since that would leak into whatever
	 * ran the capability check.
	 */
	public function test_get_map_meta_cap_post_does_not_create_a_global_post() {
		$request = $this->create_non_draft( 'wcp_payment_request' );

		WordCamp_Budgets::get_map_meta_cap_post( array( $request ) );
		$this->assertArrayNotHasKey( 'post', $GLOBALS );

		WordCamp_Budgets::get_map_meta_cap_post( array() );
		$this->assertArrayNotHasKey( 'post', $GLOBALS );
	}
}
