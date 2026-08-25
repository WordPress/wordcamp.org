<?php

namespace WordCamp\Budgets\Tests;

use WP_Query;
use WP_UnitTestCase;

/**
 * Tests for the payment file privacy filter.
 *
 * @see \WordCamp\Budgets\Privacy\exclude_others_payment_files_from_query()
 */
class Test_Privacy extends WP_UnitTestCase {

	/**
	 * @var int Admin user ID.
	 */
	private static $admin_id;

	/**
	 * @var int Editor user ID (non-admin).
	 */
	private static $editor_id;

	/**
	 * @var int Another editor user ID.
	 */
	private static $other_editor_id;

	/**
	 * @var int A payment request post owned by $editor_id.
	 */
	private static $payment_post_id;

	/**
	 * @var int Attachment on the payment post, uploaded by $editor_id.
	 */
	private static $own_attachment_id;

	/**
	 * @var int Attachment on the payment post, uploaded by $other_editor_id.
	 */
	private static $other_attachment_id;

	/**
	 * @var int A regular (non-payment) attachment.
	 */
	private static $regular_attachment_id;

	/**
	 * Create test users, a payment post, and attachments for all tests.
	 *
	 * @param WP_UnitTest_Factory $factory Test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin_id       = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$editor_id      = $factory->user->create( array( 'role' => 'editor' ) );
		self::$other_editor_id = $factory->user->create( array( 'role' => 'editor' ) );

		// In multisite, manage_options requires super admin.
		grant_super_admin( self::$admin_id );

		// Create a payment request post owned by editor.
		self::$payment_post_id = $factory->post->create( array(
			'post_type'   => 'wcp_payment_request',
			'post_status' => 'draft',
			'post_author' => self::$editor_id,
		) );

		// Attachment uploaded by the editor (owner), attached to the payment post.
		self::$own_attachment_id = $factory->post->create( array(
			'post_type'   => 'attachment',
			'post_parent' => self::$payment_post_id,
			'post_author' => self::$editor_id,
			'post_status' => 'inherit',
		) );

		// Attachment uploaded by another editor, attached to the same payment post.
		self::$other_attachment_id = $factory->post->create( array(
			'post_type'   => 'attachment',
			'post_parent' => self::$payment_post_id,
			'post_author' => self::$other_editor_id,
			'post_status' => 'inherit',
		) );

		// A regular attachment not attached to any payment post.
		self::$regular_attachment_id = $factory->post->create( array(
			'post_type'   => 'attachment',
			'post_parent' => 0,
			'post_author' => self::$other_editor_id,
			'post_status' => 'inherit',
		) );
	}

	/**
	 * Helper to query attachments as a specific user.
	 *
	 * @param int $user_id The user to run the query as.
	 *
	 * @return int[] Attachment IDs returned by the query.
	 */
	private function query_attachments_as( $user_id ) {
		wp_set_current_user( $user_id );

		$query = new WP_Query( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 100,
			'fields'         => 'ids',
		) );

		return $query->posts;
	}

	/**
	 * Admins (super admins in multisite) should see all attachments, including payment files.
	 */
	public function test_admin_sees_all_attachments() {
		$ids = $this->query_attachments_as( self::$admin_id );

		$this->assertContains( self::$own_attachment_id, $ids );
		$this->assertContains( self::$other_attachment_id, $ids );
		$this->assertContains( self::$regular_attachment_id, $ids );
	}

	/**
	 * Editors should see payment attachments they uploaded.
	 */
	public function test_editor_sees_own_payment_attachment() {
		$ids = $this->query_attachments_as( self::$editor_id );

		$this->assertContains( self::$own_attachment_id, $ids, 'Editor should see their own attachment on their payment post.' );
	}

	/**
	 * Editors should see all attachments on their own payment posts, even if uploaded by others.
	 */
	public function test_editor_sees_attachment_on_own_payment_post() {
		// other_editor uploaded an attachment to editor's payment post.
		// editor (the payment post author) should still see it.
		$ids = $this->query_attachments_as( self::$editor_id );

		$this->assertContains( self::$other_attachment_id, $ids, 'Editor should see attachments on their own payment post, even if uploaded by someone else.' );
	}

	/**
	 * Editors unrelated to a payment post should not see its attachments.
	 */
	public function test_other_editor_cannot_see_payment_attachments() {
		// A third editor who is neither the attachment author nor the payment post author.
		$third_editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$ids = $this->query_attachments_as( $third_editor_id );

		$this->assertNotContains( self::$own_attachment_id, $ids, 'Unrelated editor should not see payment attachments from other users.' );
		$this->assertNotContains( self::$other_attachment_id, $ids, 'Unrelated editor should not see payment attachments from other users.' );
	}

	/**
	 * Non-payment attachments should remain visible to all users.
	 */
	public function test_regular_attachments_visible_to_all() {
		$third_editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$ids = $this->query_attachments_as( $third_editor_id );

		$this->assertContains( self::$regular_attachment_id, $ids, 'Regular attachments should be visible to all users.' );
	}

	/**
	 * Verify found_posts matches the actual result count.
	 *
	 * When filtering happens after the query (e.g. via the_posts), found_posts can be higher than
	 * the actual number of returned posts, which breaks media library grid pagination.
	 */
	public function test_found_posts_matches_returned_count() {
		$third_editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_current_user( $third_editor_id );

		$query = new WP_Query( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 100,
		) );

		$this->assertSame(
			count( $query->posts ),
			(int) $query->found_posts,
			'found_posts should match the actual number of returned posts (the original bug).'
		);
	}
}
