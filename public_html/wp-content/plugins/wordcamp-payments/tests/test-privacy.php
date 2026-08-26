<?php

namespace WordCamp\Budgets\Tests;

use WP_UnitTestCase, WP_Query;
use WCP_Payment_Request;
use WordCamp\Budgets\Reimbursement_Requests;

defined( 'WPINC' ) || die();

/**
 * Cross-user privacy for the files attached to budget requests.
 *
 * Reimbursement and vendor payment requests carry attachments with bank details, invoices, and similar
 * financial records. `privacy.php` hides those from everyone except the person who uploaded them, the person
 * who filed the request, and network admins. These tests pin that boundary across the query entry points a
 * logged-in organizer can reach.
 *
 * @group budgets
 */
class Test_Privacy extends WP_UnitTestCase {
	/**
	 * The organizer who filed a payment request and uploaded its attachment.
	 *
	 * @var int
	 */
	protected static $victim_id;

	/**
	 * Another organizer on the same site, with `upload_files` but not `manage_options`.
	 *
	 * @var int
	 */
	protected static $attacker_id;

	/**
	 * A network admin, who may see every payment file.
	 *
	 * @var int
	 */
	protected static $network_admin_id;

	/**
	 * The victim's vendor payment request.
	 *
	 * @var int
	 */
	protected static $payment_request_id;

	/**
	 * The victim's reimbursement request.
	 *
	 * @var int
	 */
	protected static $reimbursement_id;

	/**
	 * The attachment on the victim's vendor payment request.
	 *
	 * @var int
	 */
	protected static $payment_file_id;

	/**
	 * The attachment on the victim's reimbursement request.
	 *
	 * @var int
	 */
	protected static $reimbursement_file_id;

	/**
	 * An ordinary attachment, unrelated to any budget request.
	 *
	 * @var int
	 */
	protected static $public_file_id;

	/**
	 * Create the users and the posts they own.
	 *
	 * @param \WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$victim_id        = $factory->user->create( array( 'role' => 'editor' ) );
		self::$attacker_id      = $factory->user->create( array( 'role' => 'editor' ) );
		self::$network_admin_id = $factory->user->create( array( 'role' => 'administrator' ) );

		grant_super_admin( self::$network_admin_id );

		/*
		 * The budget CPTs log an entry attributed to the current user whenever a request is saved, and read that
		 * user's ID unconditionally. Create the fixtures as the person who'd really be filing them, so the log
		 * has someone to attribute the entry to.
		 */
		wp_set_current_user( self::$victim_id );

		self::$payment_request_id = $factory->post->create( array(
			'post_type'   => WCP_Payment_Request::POST_TYPE,
			'post_author' => self::$victim_id,
			'post_status' => 'draft',
		) );

		self::$reimbursement_id = $factory->post->create( array(
			'post_type'   => Reimbursement_Requests\POST_TYPE,
			'post_author' => self::$victim_id,
			'post_status' => 'draft',
		) );

		self::$payment_file_id = $factory->attachment->create_object( array(
			'file'           => 'invoice-a1b2c3d4e5f6g7h8.pdf',
			'post_parent'    => self::$payment_request_id,
			'post_author'    => self::$victim_id,
			'post_mime_type' => 'application/pdf',
		) );

		self::$reimbursement_file_id = $factory->attachment->create_object( array(
			'file'           => 'receipt-h8g7f6e5d4c3b2a1.pdf',
			'post_parent'    => self::$reimbursement_id,
			'post_author'    => self::$victim_id,
			'post_mime_type' => 'application/pdf',
		) );

		self::$public_file_id = $factory->attachment->create_object( array(
			'file'           => 'sponsor-logo.png',
			'post_parent'    => 0,
			'post_author'    => self::$victim_id,
			'post_mime_type' => 'image/png',
		) );

		wp_set_current_user( 0 );
	}

	/**
	 * Collect the IDs the current user gets back from an attachment query.
	 *
	 * @param array $args Extra `get_posts()` arguments.
	 *
	 * @return int[]
	 */
	protected function get_visible_attachment_ids( array $args = array() ) {
		$attachments = get_posts( array_merge(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'numberposts' => -1,
			),
			$args
		) );

		// A `fields => ids` query returns bare IDs rather than posts.
		if ( $attachments && ! is_object( reset( $attachments ) ) ) {
			return array_map( 'intval', $attachments );
		}

		return array_map( 'intval', wp_list_pluck( $attachments, 'ID' ) );
	}

	/**
	 * `privacy.php` can't reference the post type constants, because it loads on requests where the files that
	 * define them don't. Fail loudly if a slug is ever renamed on one side only.
	 */
	public function test_budget_request_post_types_match_the_constants() {
		$this->assertSame(
			array( Reimbursement_Requests\POST_TYPE, WCP_Payment_Request::POST_TYPE ),
			\WordCamp\Budgets\Privacy\get_budget_request_post_types()
		);
	}

	/**
	 * `get_posts()` defaults to `suppress_filters => true`, which skips every `the_posts`/`posts_clauses`
	 * filter. Anything that hangs the privacy check off those filters alone is bypassed by every core caller
	 * that uses the default -- `wp.getMediaLibrary` over XML-RPC being the reachable one.
	 */
	public function test_suppressed_filters_do_not_expose_others_payment_files() {
		wp_set_current_user( self::$attacker_id );

		$visible = $this->get_visible_attachment_ids();

		$this->assertNotContains( self::$payment_file_id, $visible );
		$this->assertNotContains( self::$reimbursement_file_id, $visible );
	}

	/**
	 * The same boundary, for a query that opts into filters explicitly (the Media Library, the REST API).
	 */
	public function test_unsuppressed_filters_do_not_expose_others_payment_files() {
		wp_set_current_user( self::$attacker_id );

		$visible = $this->get_visible_attachment_ids( array( 'suppress_filters' => false ) );

		$this->assertNotContains( self::$payment_file_id, $visible );
		$this->assertNotContains( self::$reimbursement_file_id, $visible );
	}

	/**
	 * A `WP_Query` built by hand, the way the REST attachments controller does it.
	 */
	public function test_wp_query_does_not_expose_others_payment_files() {
		wp_set_current_user( self::$attacker_id );

		$query = new WP_Query( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
		) );

		$visible = array_map( 'intval', wp_list_pluck( $query->posts, 'ID' ) );

		$this->assertNotContains( self::$payment_file_id, $visible );
		$this->assertNotContains( self::$reimbursement_file_id, $visible );
	}

	/**
	 * `get_children()` queries `post_type => 'any'`, which includes attachments because the `attachment` post
	 * type is public and therefore not excluded from search.
	 */
	public function test_any_post_type_query_does_not_expose_others_payment_files() {
		wp_set_current_user( self::$attacker_id );

		$children = get_children( array(
			'post_parent' => self::$payment_request_id,
			'post_type'   => 'any',
			'post_status' => 'any',
		) );

		$this->assertArrayNotHasKey( self::$payment_file_id, $children );
	}

	/**
	 * A query for bare IDs still has to be filtered -- the results are attachment IDs, and the URL of a payment
	 * file is one `wp_get_attachment_url()` call away from its ID.
	 */
	public function test_id_only_query_does_not_expose_others_payment_files() {
		wp_set_current_user( self::$attacker_id );

		$visible = $this->get_visible_attachment_ids( array( 'fields' => 'ids' ) );

		$this->assertNotContains( self::$payment_file_id, $visible );
		$this->assertContains( self::$public_file_id, $visible );
	}

	/**
	 * `wp.getMediaItem` takes an attachment ID and never runs a `WP_Query`, so the query-level guard cannot
	 * reach it. Attachment IDs are sequential, so this is as good as a listing.
	 */
	public function test_xmlrpc_media_item_redacts_others_payment_files() {
		wp_set_current_user( self::$attacker_id );

		$attachment = get_post( self::$payment_file_id );
		$prepared   = apply_filters(
			'xmlrpc_prepare_media_item',
			array(
				'attachment_id' => (string) $attachment->ID,
				'link'          => wp_get_attachment_url( $attachment->ID ),
				'title'         => $attachment->post_title,
			),
			$attachment,
			'thumbnail'
		);

		$this->assertEmpty( $prepared['link'] ?? '' );
	}

	/**
	 * `found_posts` has to agree with the results, or the Media Library grid stops offering "Load more" as soon
	 * as a page comes back short. That's what excluding the files in SQL rather than in the results buys.
	 */
	public function test_found_posts_matches_the_visible_results() {
		wp_set_current_user( self::$attacker_id );

		$query = new WP_Query( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 100,
		) );

		$this->assertSame( count( $query->posts ), (int) $query->found_posts );
	}

	/**
	 * Attachments are searchable by default, so a front-end search runs over them with no post type named. Nobody
	 * is logged in for most of those, and an anonymous visitor owns nothing.
	 */
	public function test_logged_out_search_does_not_expose_payment_files() {
		wp_set_current_user( 0 );

		$query = new WP_Query( array(
			's'              => 'invoice-a1b2c3d4e5f6g7h8',
			'post_status'    => 'any',
			'posts_per_page' => -1,
		) );

		$visible = array_map( 'intval', wp_list_pluck( $query->posts, 'ID' ) );

		$this->assertNotContains( self::$payment_file_id, $visible );
	}

	/**
	 * Someone who uploads a file onto another organizer's request can still see the file they uploaded.
	 */
	public function test_uploader_sees_own_file_on_another_users_request() {
		$uploaded_by_attacker = self::factory()->attachment->create_object( array(
			'file'           => 'quote-9z8y7x6w5v4u3t2s.pdf',
			'post_parent'    => self::$payment_request_id,
			'post_author'    => self::$attacker_id,
			'post_mime_type' => 'application/pdf',
		) );

		wp_set_current_user( self::$attacker_id );

		$visible = $this->get_visible_attachment_ids();

		$this->assertContains( $uploaded_by_attacker, $visible );
		$this->assertNotContains( self::$payment_file_id, $visible );
	}

	/**
	 * The person who filed the request keeps access to its files.
	 */
	public function test_request_author_sees_own_payment_files() {
		wp_set_current_user( self::$victim_id );

		$visible = $this->get_visible_attachment_ids();

		$this->assertContains( self::$payment_file_id, $visible );
		$this->assertContains( self::$reimbursement_file_id, $visible );
	}

	/**
	 * Network admins review budget requests, so they see everything.
	 */
	public function test_network_admin_sees_all_payment_files() {
		wp_set_current_user( self::$network_admin_id );

		$visible = $this->get_visible_attachment_ids();

		$this->assertContains( self::$payment_file_id, $visible );
		$this->assertContains( self::$reimbursement_file_id, $visible );
	}

	/**
	 * Media that isn't attached to a budget request is untouched.
	 */
	public function test_ordinary_attachments_stay_visible() {
		wp_set_current_user( self::$attacker_id );

		$visible = $this->get_visible_attachment_ids();

		$this->assertContains( self::$public_file_id, $visible );
	}
}
