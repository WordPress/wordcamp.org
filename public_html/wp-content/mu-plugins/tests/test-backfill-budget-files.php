<?php

namespace WordCamp\Budgets\Files\Backfill\Tests;

use WP_UnitTestCase;

use function WordCamp\Budgets\Files\Backfill\backfill_files;
use function WordCamp\Budgets\Files\Backfill\get_attached_candidates;
use function WordCamp\Budgets\Files\Backfill\get_unattached_candidates;
use function WordCamp\Budgets\Privacy\is_budget_file;

use const WordCamp\Budgets\Privacy\BUDGET_FILE_BACKFILLED_META_KEY;
use const WordCamp\Budgets\Privacy\BUDGET_FILE_DELETE_META_KEY;
use const WordCamp\Budgets\Privacy\BUDGET_FILE_META_KEY;
use const WordCamp\Budgets\Privacy\REIMBURSEMENT_POST_TYPE;
use const WordCamp\Budgets\Files\Backfill\SPONSOR_AGREEMENT_META_KEY;

defined( 'WPINC' ) || die();

/**
 * The one-time migration behind `wp wc-budget-files`.
 *
 * Goes when `wp-cli-commands/backfill-budget-files.php` does. `Test_Privacy` covers what stays.
 *
 * @group mu-plugins
 * @group budgets
 */
class Test_Backfill_Budget_Files extends WP_UnitTestCase {
	/**
	 * The command file registers its class only under WP-CLI, so pull in the part of it that isn't the command.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		require_once dirname( __DIR__ ) . '/wp-cli-commands/backfill-budget-files.php';
	}

	/**
	 * A file sitting on a request, in the shape rows had before `privacy.php` marked them.
	 *
	 * @param string $status The request's status.
	 *
	 * @return int The attachment ID.
	 */
	protected function create_attached_file( $status = 'draft' ) {
		$request_id = self::factory()->post->create( array(
			'post_type'   => REIMBURSEMENT_POST_TYPE,
			'post_status' => $status,
		) );

		$file_id = self::factory()->attachment->create_object( array(
			'file'           => 'receipt-aB3dEfGhIjKlMn0p.pdf',
			'post_parent'    => $request_id,
			'post_status'    => 'inherit',
			'post_mime_type' => 'application/pdf',
		) );

		return $file_id;
	}

	/**
	 * A file with no parent, in the shape one is left in when its request is deleted.
	 *
	 * @param string $file The value of `_wp_attached_file`.
	 * @param string $date The attachment's `post_date`.
	 *
	 * @return int The attachment ID.
	 */
	protected function create_unattached_file( $file = 'invoice-aB3dEfGhIjKlMn0p.pdf', $date = '2023-05-04 10:00:00' ) {
		return self::factory()->attachment->create_object( array(
			'file'           => $file,
			'post_parent'    => 0,
			'post_date'      => $date,
			'post_status'    => 'inherit',
			'post_mime_type' => 'application/pdf',
		) );
	}

	/**
	 * A file on a request gets the marker, the status and the record of having been backfilled.
	 */
	public function test_a_file_on_a_request_is_migrated() {
		$file_id = $this->create_attached_file();

		$this->assertContains( $file_id, $this->candidate_ids( get_attached_candidates() ) );

		$rows = backfill_files();

		$this->assertSame( 'yes', $this->row_for( $rows, $file_id )['Marked'] );
		$this->assertTrue( is_budget_file( $file_id ) );
		$this->assertSame( 'private', get_post_field( 'post_status', $file_id ) );
		$this->assertSame( '1', get_post_meta( $file_id, BUDGET_FILE_BACKFILLED_META_KEY, true ) );
	}

	/**
	 * A file the hooks already marked is left as it is, and isn't recorded as backfilled.
	 *
	 * Only the backfilled files are the work list for a later pass, so a second run must not add to it.
	 */
	public function test_an_already_marked_file_is_skipped() {
		$file_id = $this->create_attached_file( 'wcb-approved' );

		update_post_meta( $file_id, BUDGET_FILE_META_KEY, 1 );

		$rows = backfill_files();

		$this->assertSame( 'skipped', $this->row_for( $rows, $file_id )['Marked'] );
		$this->assertSame( 'inherit', get_post_field( 'post_status', $file_id ) );
		$this->assertSame( '', get_post_meta( $file_id, BUDGET_FILE_BACKFILLED_META_KEY, true ) );
	}

	/**
	 * A file's name is what identifies it once its request is gone, and only a random suffix counts.
	 */
	public function test_only_files_named_like_a_payment_file_are_migrated() {
		$suffixed = $this->create_unattached_file();
		$wordlike = $this->create_unattached_file( 'invoice-origineelformaat.pdf' );
		$older    = $this->create_unattached_file( 'invoice-aB3dEfGhIjKlMn0q.pdf', '2018-01-01 10:00:00' );

		$candidates = $this->candidate_ids( get_unattached_candidates() );

		$this->assertContains( $suffixed, $candidates );
		$this->assertNotContains( $wordlike, $candidates );
		$this->assertNotContains( $older, $candidates );

		backfill_files();

		$this->assertTrue( is_budget_file( $suffixed ) );
		$this->assertSame( 'private', get_post_field( 'post_status', $suffixed ) );
		$this->assertFalse( is_budget_file( $wordlike ) );
		$this->assertFalse( is_budget_file( $older ) );
		$this->assertSame( 'inherit', get_post_field( 'post_status', $older ) );
	}

	/**
	 * Sponsorship agreements share the naming, and have their own migration.
	 */
	public function test_a_sponsorship_agreement_is_left_alone() {
		$agreement_id = $this->create_unattached_file( 'agreement-aB3dEfGhIjKlMn0p.pdf' );

		update_post_meta( $agreement_id, SPONSOR_AGREEMENT_META_KEY, 1 );

		$this->assertNotContains( $agreement_id, $this->candidate_ids( get_unattached_candidates() ) );

		backfill_files();

		$this->assertFalse( is_budget_file( $agreement_id ) );
		$this->assertSame( 'inherit', get_post_field( 'post_status', $agreement_id ) );
	}

	/**
	 * The IDs `scan` names as copies are recorded for a later pass, and nothing else is.
	 */
	public function test_copies_are_recorded_and_the_rest_are_not() {
		$copy_id  = $this->create_unattached_file( 'invoice-aB3dEfGhIjKlMn0p.pdf' );
		$other_id = $this->create_unattached_file( 'receipt-qR5tUvWxYz1aB2cD.pdf' );

		backfill_files( array( $copy_id ) );

		$this->assertSame( '1', get_post_meta( $copy_id, BUDGET_FILE_DELETE_META_KEY, true ) );
		$this->assertSame( '', get_post_meta( $other_id, BUDGET_FILE_DELETE_META_KEY, true ) );

		$this->assertTrue( is_budget_file( $copy_id ) );
		$this->assertTrue( is_budget_file( $other_id ) );
	}

	/**
	 * A copy that an earlier run marked is still recorded, because `scan` works the network out one site at a
	 * time and the copy can be marked before its original is found.
	 */
	public function test_a_copy_is_recorded_on_a_later_run_too() {
		$copy_id = $this->create_unattached_file( 'invoice-zZ9yYxXwWvVuUtT1.pdf' );

		backfill_files();

		$this->assertTrue( is_budget_file( $copy_id ) );
		$this->assertSame( '', get_post_meta( $copy_id, BUDGET_FILE_DELETE_META_KEY, true ) );

		$rows = backfill_files( array( $copy_id ) );

		$this->assertSame( 'skipped', $this->row_for( $rows, $copy_id )['Marked'] );
		$this->assertSame( 'yes', $this->row_for( $rows, $copy_id )['Copy'] );
		$this->assertSame( '1', get_post_meta( $copy_id, BUDGET_FILE_DELETE_META_KEY, true ) );
	}

	/**
	 * A dry run reports what a real one would do, and touches nothing.
	 */
	public function test_a_dry_run_writes_nothing() {
		$attached_id   = $this->create_attached_file();
		$unattached_id = $this->create_unattached_file();

		$dry_run = backfill_files( array( $unattached_id ), true );

		$this->assertFalse( is_budget_file( $attached_id ) );
		$this->assertFalse( is_budget_file( $unattached_id ) );
		$this->assertSame( 'inherit', get_post_field( 'post_status', $attached_id ) );
		$this->assertSame( '', get_post_meta( $unattached_id, BUDGET_FILE_DELETE_META_KEY, true ) );

		$this->assertSame( $dry_run, backfill_files( array( $unattached_id ) ) );
	}

	/**
	 * The attachment IDs of a set of candidate rows, as integers rather than as `$wpdb` strings.
	 *
	 * @param object[] $candidates
	 *
	 * @return int[]
	 */
	protected function candidate_ids( $candidates ) {
		return array_map( 'intval', wp_list_pluck( $candidates, 'ID' ) );
	}

	/**
	 * The report row for one attachment.
	 *
	 * @param array[] $rows          The report `backfill_files()` returned.
	 * @param int     $attachment_id
	 *
	 * @return array
	 */
	protected function row_for( $rows, $attachment_id ) {
		$matches = array_values( array_filter( $rows, fn( $row ) => $row['Attachment'] === $attachment_id ) );

		$this->assertCount( 1, $matches, "No report row for attachment {$attachment_id}." );

		return $matches[0];
	}
}
