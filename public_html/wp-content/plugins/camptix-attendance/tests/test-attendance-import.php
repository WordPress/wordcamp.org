<?php

defined( 'WPINC' ) || die();

/**
 * Tests for the Attendance Import (Tools tab) PoC: CSV parsing, row resolution,
 * and the shared apply core.
 *
 * @group camptix-attendance
 * @group bulk-attendance
 */
class Test_Attendance_Import extends WP_UnitTestCase {
	use CampTix_Root_Blog_Fixture;

	/**
	 * Provision central when the harness points at a blog the test install lacks.
	 *
	 * @param WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		if ( ! get_site( WORDCAMP_ROOT_BLOG_ID ) ) {
			self::create_wordcamp_root_blog( $factory );
		}
	}

	/**
	 * Remove the root blog this class provisioned.
	 */
	public static function wpTearDownAfterClass() {
		if ( self::$wordcamp_root_blog_id ) {
			self::delete_wordcamp_root_blog();

			self::$wordcamp_root_blog_id = null;
		}
	}

	/**
	 * Write CSV content to a temp file and return its path.
	 *
	 * @param string $content
	 *
	 * @return string
	 */
	protected function csv( $content ) {
		$path = wp_tempnam( 'attendance-import-test' );

		file_put_contents( $path, $content );

		return $path;
	}

	/**
	 * Create a published attendee with an email.
	 *
	 * @param string $email
	 * @param bool   $attended
	 * @param string $status
	 *
	 * @return int
	 */
	protected function make_attendee( $email, $attended = false, $status = 'publish' ) {
		$id = self::factory()->post->create( array(
			'post_type' => 'tix_attendee', 'post_status' => $status,
		) );

		update_post_meta( $id, 'tix_email', $email );

		if ( $attended ) {
			update_post_meta( $id, 'tix_attended', true );
		}

		return $id;
	}

	/**
	 * Parser reads id email and attended columns.
	 */
	public function test_parser_reads_id_email_and_attended_columns() {
		$addon = new CampTix_Attendance();

		$rows = $addon->parse_attendance_csv( $this->csv(
			"id,first_name,email,attended\n" .
			"12,Ada,ada@example.org,yes\n" .
			"0,Grace,grace@example.org,no\n" .
			",Alan,alan@example.org,1\n"
		) );

		$this->assertCount( 3, $rows );

		$expected_first_row = array(
			'id'       => 12,
			'email'    => 'ada@example.org',
			'attended' => true,
		);

		$this->assertSame( $expected_first_row, $rows[0] );
		$this->assertFalse( $rows[1]['attended'] );
		$this->assertTrue( $rows[2]['attended'] );
	}

	/**
	 * Parser defaults to attended without column and handles bom.
	 */
	public function test_parser_defaults_to_attended_without_column_and_handles_bom() {
		$addon = new CampTix_Attendance();

		// A scanner export: just emails, UTF-8 BOM in front of the header.
		$rows = $addon->parse_attendance_csv( $this->csv(
			"\xEF\xBB\xBFEmail\n" .
			"ada@example.org\n"
		) );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'ada@example.org', $rows[0]['email'] );
		$this->assertTrue( $rows[0]['attended'] );
	}

	/**
	 * Parser rejects files without key columns.
	 */
	public function test_parser_rejects_files_without_key_columns() {
		$addon = new CampTix_Attendance();

		$error = $addon->parse_attendance_csv( $this->csv( "first_name,last_name\nAda,Lovelace\n" ) );

		$this->assertWPError( $error );
		$this->assertSame( 'no_key_column', $error->get_error_code() );
	}

	/**
	 * Resolver matches by id then email and reports unmatched.
	 */
	public function test_resolver_matches_by_id_then_email_and_reports_unmatched() {
		$addon = new CampTix_Attendance();

		$by_id    = $this->make_attendee( 'ida@example.org' );
		$by_email = $this->make_attendee( 'emma@example.org' );
		$draft    = $this->make_attendee( 'draft@example.org', false, 'draft' );

		$plan = $addon->resolve_attendance_rows( array(
			array(
				'id' => $by_id, 'email' => '', 'attended' => true,
			),
			array(
				'id' => 0, 'email' => 'emma@example.org', 'attended' => true,
			),
			array(
				'id' => 0, 'email' => 'draft@example.org', 'attended' => true,
			),   // Draft: must not match.
			array(
				'id' => 0, 'email' => 'nobody@example.org', 'attended' => true,
			),  // Unknown.
			array(
				'id' => 999999, 'email' => '', 'attended' => true,
			),               // Bogus ID.
		) );

		$this->assertEqualSets( array( $by_id, $by_email ), $plan['set'] );
		$this->assertSame( array(), $plan['unset'] );
		$this->assertEqualSets( array( 'draft@example.org', 'nobody@example.org', '#999999' ), $plan['unmatched'] );
		$this->assertSame( 5, $plan['total_rows'] );
	}

	/**
	 * Resolver splits directions and dedupes.
	 */
	public function test_resolver_splits_directions_and_dedupes() {
		$addon = new CampTix_Attendance();

		$going    = $this->make_attendee( 'going@example.org' );
		$notgoing = $this->make_attendee( 'notgoing@example.org', true );

		$plan = $addon->resolve_attendance_rows( array(
			array(
				'id' => $going, 'email' => '', 'attended' => true,
			),
			array(
				'id' => $going, 'email' => '', 'attended' => true,
			),      // Duplicate row.
			array(
				'id' => $notgoing, 'email' => '', 'attended' => false,
			),
		) );

		$this->assertSame( array( $going ), $plan['set'] );
		$this->assertSame( array( $notgoing ), $plan['unset'] );
	}

	/**
	 * Resolver email matches all duplicates.
	 */
	public function test_resolver_email_matches_all_duplicates() {
		$addon = new CampTix_Attendance();

		$twin_a = $this->make_attendee( 'shared@example.org' );
		$twin_b = $this->make_attendee( 'shared@example.org' );

		$plan = $addon->resolve_attendance_rows( array(
			array(
				'id' => 0, 'email' => 'shared@example.org', 'attended' => true,
			),
		) );

		$this->assertEqualSets( array( $twin_a, $twin_b ), $plan['set'] );
	}

	/**
	 * Apply core handles both directions from a plan.
	 */
	public function test_apply_core_handles_both_directions_from_a_plan() {
		$addon = new CampTix_Attendance();

		$a = $this->make_attendee( 'a@example.org' );          // To mark.
		$b = $this->make_attendee( 'b@example.org', true );    // Already marked: no-op.
		$c = $this->make_attendee( 'c@example.org', true );    // To unmark.

		$marked   = $addon->set_attendance_for_ids( array( $a, $b ), true, 'import' );
		$unmarked = $addon->set_attendance_for_ids( array( $c ), false, 'import' );

		$this->assertSame( 1, $marked );
		$this->assertSame( 1, $unmarked );
		$this->assertSame( '1', get_post_meta( $a, 'tix_attended', true ) );
		$this->assertSame( '1', get_post_meta( $b, 'tix_attended', true ) );
		$this->assertSame( '', get_post_meta( $c, 'tix_attended', true ) );
	}

	/**
	 * Full snapshot of an attendee: every post field + every meta row.
	 *
	 * The tix_log meta is excluded: it's CampTix's append-only audit trail, which
	 * vanilla CampTix stores as postmeta (each write ADDS a log entry — that's the
	 * point). On WordCamp.org production, postmeta logging is disabled and
	 * camptix-network-tools routes entries to the network log table instead, so
	 * even this key is untouched there.
	 *
	 * @param int $id
	 *
	 * @return array
	 */
	protected function snapshot( $id ) {
		clean_post_cache( $id );

		$meta = get_post_meta( $id );
		unset( $meta['tix_log'] );

		return array(
			'post' => get_post( $id, ARRAY_A ),
			'meta' => $meta,
		);
	}

	/**
	 * Import touches only `tix_attended` and nothing else.
	 */
	public function test_import_touches_only_tix_attended_and_nothing_else() {
		$addon = new CampTix_Attendance();

		$id = $this->make_attendee( 'only@example.org' );
		update_post_meta( $id, 'tix_first_name', 'Only' );
		update_post_meta( $id, 'tix_ticket_id', 42 );
		update_post_meta( $id, 'tix_access_token', 'tok123' );

		$before = $this->snapshot( $id );

		$addon->set_attendance_for_ids( array( $id ), true, 'import' );

		$after = $this->snapshot( $id );

		// The post row itself is byte-identical — not even post_modified moves.
		$this->assertSame( $before['post'], $after['post'] );

		// The meta diff is exactly one key: tix_attended.
		$added   = array_diff_key( $after['meta'], $before['meta'] );
		$removed = array_diff_key( $before['meta'], $after['meta'] );
		$changed = array();
		foreach ( array_intersect_key( $before['meta'], $after['meta'] ) as $key => $value ) {
			if ( $value !== $after['meta'][ $key ] ) {
				$changed[] = $key;
			}
		}

		$this->assertSame( array( 'tix_attended' ), array_keys( $added ) );
		$this->assertSame( array(), array_keys( $removed ) );
		$this->assertSame( array(), $changed );
	}

	/**
	 * Yes then no restores the never attended exactly.
	 */
	public function test_yes_then_no_restores_the_never_attended_exactly() {
		$addon = new CampTix_Attendance();

		$id     = $this->make_attendee( 'fresh@example.org' );
		$before = $this->snapshot( $id );

		// Upload "yes", then upload "no" (same rows).
		$addon->set_attendance_for_ids( array( $id ), true, 'import' );
		$addon->set_attendance_for_ids( array( $id ), false, 'import' );

		// Byte-identical to the original: post row AND all meta (the delete removes
		// the row entirely, so there's no leftover empty value).
		$this->assertSame( $before, $this->snapshot( $id ) );
	}

	/**
	 * Yes then no is a direction not a rollback.
	 */
	public function test_yes_then_no_is_a_direction_not_a_rollback() {
		$addon = new CampTix_Attendance();

		// This attendee was ALREADY attended before any import (e.g. marked at the
		// door). The "yes" file is a no-op for them — but a subsequent "no" file
		// UNMARKS them, which is NOT their pre-import state. Documented asymmetry:
		// a reverse file sets a direction; it does not roll back the first import.
		$id = $this->make_attendee( 'door@example.org', true );

		$changed_by_yes = $addon->set_attendance_for_ids( array( $id ), true, 'import' );
		$this->assertSame( 0, $changed_by_yes );

		$changed_by_no = $addon->set_attendance_for_ids( array( $id ), false, 'import' );
		$this->assertSame( 1, $changed_by_no );
		$this->assertSame( '', get_post_meta( $id, 'tix_attended', true ) );
	}

	/**
	 * Audit log records the acting user.
	 */
	public function test_audit_log_records_the_acting_user() {
		$addon = new CampTix_Attendance();

		$user_id = self::factory()->user->create( array(
			'role' => 'administrator', 'user_login' => 'importer_admin',
		) );
		wp_set_current_user( $user_id );

		$id = $this->make_attendee( 'audit@example.org' );

		$addon->set_attendance_for_ids( array( $id ), true, 'import' );

		wp_set_current_user( 0 );

		// Vanilla CampTix stores the log as tix_log postmeta in this environment.
		$log      = get_post_meta( $id, 'tix_log', true );
		$messages = wp_list_pluck( (array) $log, 'message' );

		$this->assertContains( 'Marked attendee as attended (import by importer_admin).', $messages );
	}

	/**
	 * Parser enforces the row cap.
	 */
	public function test_parser_enforces_the_row_cap() {
		$addon = new CampTix_Attendance();

		$cap = function () {
			return 2;
		};
		add_filter( 'camptix_attendance_import_max_rows', $cap );

		$error = $addon->parse_attendance_csv( $this->csv(
			"email\none@example.org\ntwo@example.org\nthree@example.org\n"
		) );

		remove_filter( 'camptix_attendance_import_max_rows', $cap );

		$this->assertWPError( $error );
		$this->assertSame( 'too_many_rows', $error->get_error_code() );
	}

	/**
	 * Each plan gets a unique apply token.
	 */
	public function test_each_plan_gets_a_unique_apply_token() {
		$addon = new CampTix_Attendance();

		$plan_a = $addon->resolve_attendance_rows( array() );
		$plan_b = $addon->resolve_attendance_rows( array() );

		$this->assertNotEmpty( $plan_a['token'] );
		$this->assertNotSame( $plan_a['token'], $plan_b['token'] );
	}

	/**
	 * Tools tab is registered for organizers only.
	 */
	public function test_tools_tab_is_registered_for_organizers_only() {
		// The bootstrap ran camptix_init anonymously, so the tab is absent...
		$sections = apply_filters( 'camptix_menu_tools_tabs', array( 'summarize' => 'Summarize' ) );
		$this->assertArrayNotHasKey( 'attendance-import', $sections );

		// ...and registers once an organizer-capable user initializes the addon.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$addon = new CampTix_Attendance();
		$addon->camptix_init();

		$sections = apply_filters( 'camptix_menu_tools_tabs', array( 'summarize' => 'Summarize' ) );
		$this->assertArrayHasKey( 'attendance-import', $sections );

		remove_filter( 'camptix_menu_tools_tabs', array( $addon, 'add_import_tools_tab' ) );
		wp_set_current_user( 0 );
	}
}
