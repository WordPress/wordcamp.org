<?php

defined( 'WPINC' ) || die();

/**
 * Tests for the bulk "mark all matching" PoC: the shared attendee-ID query and
 * the bulk write core.
 *
 * @group camptix-attendance
 * @group bulk-attendance
 */
class Test_Bulk_Attendance extends WP_UnitTestCase {
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
	 * Ticket A/B post IDs and attendee fixtures shared by most tests.
	 *
	 * @var array
	 */
	protected $fx;

	/**
	 * Build the standard fixture: two tickets, four published attendees
	 * (a1 + a2 on ticket A — a1 already attended — a3 on ticket B), plus one
	 * draft attendee that must never match anything.
	 */
	protected function make_fixture() {
		$ticket_a = self::factory()->post->create( array(
			'post_type' => 'tix_ticket', 'post_status' => 'publish', 'post_title' => 'General',
		) );
		$ticket_b = self::factory()->post->create( array(
			'post_type' => 'tix_ticket', 'post_status' => 'publish', 'post_title' => 'Contributor Day',
		) );

		$make_attendee = function ( $ticket_id, $first, $last, $attended = false, $status = 'publish' ) {
			$id = self::factory()->post->create( array(
				'post_type'   => 'tix_attendee',
				'post_status' => $status,
				'post_title'  => "$first $last",
			) );

			update_post_meta( $id, 'tix_ticket_id', $ticket_id );
			update_post_meta( $id, 'tix_first_name', $first );
			update_post_meta( $id, 'tix_last_name', $last );

			if ( $attended ) {
				update_post_meta( $id, 'tix_attended', true );
			}

			return $id;
		};

		$this->fx = array(
			'ticket_a' => $ticket_a,
			'ticket_b' => $ticket_b,
			'a1'       => $make_attendee( $ticket_a, 'Ada', 'Lovelace', true ),
			'a2'       => $make_attendee( $ticket_a, 'Grace', 'Hopper' ),
			'a3'       => $make_attendee( $ticket_b, 'Alan', 'Turing' ),
			'draft'    => $make_attendee( $ticket_a, 'Drafty', 'McDraft', false, 'draft' ),
		);

		// Fresh instance AFTER tickets exist (get_tickets() memoizes per instance).
		return new CampTix_Attendance();
	}

	/**
	 * All-tickets filter set (the UI default: every ticket selected).
	 *
	 * @param array $overrides
	 *
	 * @return array
	 */
	protected function filters( array $overrides = array() ) {
		$defaults = array(
			'attendance' => 'none',
			'tickets'    => array( $this->fx['ticket_a'], $this->fx['ticket_b'] ),
		);

		return array_merge( $defaults, $overrides );
	}

	/**
	 * Query matches published attendees only.
	 */
	public function test_query_matches_published_attendees_only() {
		$addon = $this->make_fixture();

		$ids = $addon->query_attendee_ids( $this->filters() );

		$this->assertEqualSets( array( $this->fx['a1'], $this->fx['a2'], $this->fx['a3'] ), $ids );
		$this->assertNotContains( $this->fx['draft'], $ids );
	}

	/**
	 * Query filters by attendance both ways.
	 */
	public function test_query_filters_by_attendance_both_ways() {
		$addon = $this->make_fixture();

		$attending     = $addon->query_attendee_ids( $this->filters( array( 'attendance' => 'attending' ) ) );
		$not_attending = $addon->query_attendee_ids( $this->filters( array( 'attendance' => 'not-attending' ) ) );

		$this->assertEqualSets( array( $this->fx['a1'] ), $attending );
		$this->assertEqualSets( array( $this->fx['a2'], $this->fx['a3'] ), $not_attending );
	}

	/**
	 * Query filters by ticket and search.
	 */
	public function test_query_filters_by_ticket_and_search() {
		$addon = $this->make_fixture();

		$ticket_b_only = $addon->query_attendee_ids( $this->filters( array( 'tickets' => array( $this->fx['ticket_b'] ) ) ) );
		$this->assertEqualSets( array( $this->fx['a3'] ), $ticket_b_only );

		$search = $addon->query_attendee_ids( $this->filters(), 'Grace Hopper' );
		$this->assertEqualSets( array( $this->fx['a2'] ), $search );
	}

	/**
	 * Query returns empty when no tickets selected.
	 */
	public function test_query_returns_empty_when_no_tickets_selected() {
		$addon = $this->make_fixture();

		$this->assertSame( array(), $addon->query_attendee_ids( $this->filters( array( 'tickets' => array() ) ) ) );
	}

	/**
	 * Query filters do not stack across calls.
	 */
	public function test_query_filters_do_not_stack_across_calls() {
		$addon = $this->make_fixture();

		// A narrow query first; if its posts_clauses closures leaked, the broad
		// query after it would be wrongly narrowed too.
		$narrow = $this->filters( array(
			'attendance' => 'attending',
			'tickets'    => array( $this->fx['ticket_b'] ),
		) );

		$addon->query_attendee_ids( $narrow, 'Ada' );

		$broad = $addon->query_attendee_ids( $this->filters() );

		$this->assertCount( 3, $broad );
	}

	/**
	 * Bulk dry run counts without writing.
	 */
	public function test_bulk_dry_run_counts_without_writing() {
		$addon = $this->make_fixture();

		$summary = $addon->bulk_set_attendance( $this->filters(), '', true, true );

		$this->assertSame( 3, $summary['matched'] );
		$this->assertSame( 0, $summary['changed'] );
		$this->assertSame( '', get_post_meta( $this->fx['a2'], 'tix_attended', true ) );
	}

	/**
	 * Bulk marks only the unmarked.
	 */
	public function test_bulk_marks_only_the_unmarked() {
		$addon = $this->make_fixture();

		$summary = $addon->bulk_set_attendance( $this->filters(), '', true );

		// a1 was already attended: matched 3, changed only 2.
		$this->assertSame( 3, $summary['matched'] );
		$this->assertSame( 2, $summary['changed'] );

		foreach ( array( 'a1', 'a2', 'a3' ) as $key ) {
			$this->assertSame( '1', get_post_meta( $this->fx[ $key ], 'tix_attended', true ) );
		}

		$this->assertSame( '', get_post_meta( $this->fx['draft'], 'tix_attended', true ) );
	}

	/**
	 * Bulk is idempotent.
	 */
	public function test_bulk_is_idempotent() {
		$addon = $this->make_fixture();

		$addon->bulk_set_attendance( $this->filters(), '', true );
		$again = $addon->bulk_set_attendance( $this->filters(), '', true );

		$this->assertSame( 0, $again['changed'] );
	}

	/**
	 * Bulk unmarks with same filter semantics.
	 */
	public function test_bulk_unmarks_with_same_filter_semantics() {
		$addon = $this->make_fixture();

		// The undo story: bulk-unmark everyone currently attending on ticket A.
		$summary = $addon->bulk_set_attendance(
			$this->filters( array(
				'attendance' => 'attending', 'tickets' => array( $this->fx['ticket_a'] ),
			) ),
			'',
			false
		);

		$this->assertSame( 1, $summary['matched'] );
		$this->assertSame( 1, $summary['changed'] );
		$this->assertSame( '', get_post_meta( $this->fx['a1'], 'tix_attended', true ) );
	}

	/**
	 * Bulk respects search scope.
	 */
	public function test_bulk_respects_search_scope() {
		$addon = $this->make_fixture();

		$summary = $addon->bulk_set_attendance( $this->filters(), 'Turing', true );

		$this->assertSame( 1, $summary['matched'] );
		$this->assertSame( '1', get_post_meta( $this->fx['a3'], 'tix_attended', true ) );
		$this->assertSame( '', get_post_meta( $this->fx['a2'], 'tix_attended', true ) );
	}

	/**
	 * The confirmed write must act on the IDs the count guard already checked.
	 */
	public function test_bulk_set_attendance_accepts_precomputed_ids() {
		$addon = $this->make_fixture();

		$summary = $addon->bulk_set_attendance(
			$this->filters(),
			'',
			true,
			false,
			array( $this->fx['a2'] )
		);

		$this->assertSame( 1, $summary['matched'] );
		$this->assertSame( 1, $summary['changed'] );
		$this->assertSame( '1', get_post_meta( $this->fx['a2'], 'tix_attended', true ) );
		$this->assertSame( '', get_post_meta( $this->fx['a3'], 'tix_attended', true ) );
	}
}
