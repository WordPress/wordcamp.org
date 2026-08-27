<?php

namespace WordCamp\Groups\Tests;

use WP_REST_Request;
use WordPressdotorg\GatherPress_Recurring_Events\Database as Recurring_Events_Database;
use WordPressdotorg\GatherPress_Recurring_Events\Occurrences;

use function WordCamp\Groups\Frontend\Export\build_csv;
use function WordCamp\Groups\Frontend\Export\collect_export_data;
use function WordCamp\Groups\Frontend\Export\esc_csv_cell;
use function WordCamp\Groups\Frontend\Export\export_permissions_check;
use function WordCamp\Groups\Frontend\Export\filter_json_fields;
use function WordCamp\Groups\Frontend\REST\create_event;

use const WordCamp\Groups\Frontend\Export\CSV_COLUMNS;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * @group groups
 */
class Test_Groups_Export extends Groups_TestCase {

	/**
	 * Creates a published event via the REST create path, so the GatherPress
	 * dates table and venue terms are written exactly as production does.
	 *
	 * Runs as a temporary editor, then restores the previous user, so tests
	 * can set up fixtures without disturbing their own current-user state.
	 */
	private function create_test_event( array $params = array() ): int {
		$previous_user = get_current_user_id();
		$editor_id     = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$request = new WP_REST_Request( 'POST', '/wporg-groups/v1/event' );
		foreach (
			$params + array(
				'title'      => 'Test Event',
				'date'       => current_datetime()->modify( '+1 week' )->format( 'Y-m-d' ),
				'time_start' => '18:00',
				'time_end'   => '20:00',
			) as $key => $value
		) {
			$request->set_param( $key, $value );
		}

		$response = create_event( $request );
		wp_set_current_user( $previous_user );

		$this->assertNotWPError( $response );

		return (int) $response->get_data()['id'];
	}

	/**
	 * Inserts an approved RSVP comment with the given status term and meta,
	 * mirroring how GatherPress stores RSVPs.
	 */
	private function create_rsvp( int $event_id, int $user_id, string $status, array $args = array() ): int {
		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'  => $event_id,
				'comment_type'     => 'gatherpress_rsvp',
				'comment_approved' => 1,
				'user_id'          => $user_id,
				'comment_date_gmt' => $args['timestamp_gmt'] ?? current_time( 'mysql', true ),
			)
		);

		if ( 'no_status' !== $status ) {
			wp_set_object_terms( $comment_id, $status, '_gatherpress_rsvp_status' );
		}

		if ( ! empty( $args['guests'] ) ) {
			update_comment_meta( $comment_id, 'gatherpress_rsvp_guests', (int) $args['guests'] );
		}

		if ( ! empty( $args['anonymous'] ) ) {
			update_comment_meta( $comment_id, 'gatherpress_rsvp_anonymous', 1 );
		}

		return $comment_id;
	}

	/**
	 * Creates a published weekly series with `$count` occurrences, the first
	 * two weeks out so every occurrence starts in the future.
	 */
	private function create_recurring_test_event( int $count ): int {
		$date    = gmdate( 'Y-m-d', strtotime( '+14 days' ) );
		$weekday = strtoupper( substr( gmdate( 'D', strtotime( $date ) ), 0, 2 ) );

		Recurring_Events_Database::maybe_install();

		return $this->create_test_event(
			array(
				'date'       => $date,
				'recurrence' => array(
					'frequency' => 'weekly',
					'interval'  => 1,
					'weekdays'  => array( $weekday ),
					'end_type'  => 'count',
					'count'     => $count,
				),
			)
		);
	}

	/**
	 * Dispatches GET /export through the full REST pipeline, so route
	 * registration, arg validation, and permission callbacks all run.
	 */
	private function dispatch_export_request( array $params = array() ) {
		$request = new WP_REST_Request( 'GET', '/wporg-groups/v1/export' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request );
	}

	/**
	 * Parses CSV body rows (BOM and header stripped) into arrays.
	 */
	private function parse_csv_rows( string $csv ): array {
		$csv   = preg_replace( '/^\xEF\xBB\xBF/', '', $csv );
		$lines = array_filter( explode( "\n", trim( $csv ) ), 'strlen' );

		return array_map(
			// Empty escape = strict RFC 4180, matching how the CSV is written.
			static function ( $line ) {
				return str_getcsv( $line, ',', '"', '' );
			},
			$lines
		);
	}

	/**
	 * A logged-out request is rejected as unauthenticated, not just forbidden.
	 */
	public function test_export_requires_login() {
		wp_set_current_user( 0 );

		$permission = export_permissions_check();

		$this->assertWPError( $permission );
		$this->assertSame( 'rest_not_logged_in', $permission->get_error_code() );
		$this->assertSame( 401, $permission->get_error_data()['status'] );
	}

	/**
	 * Members and Event Organisers (authors) are below the Organiser tier
	 * and cannot export.
	 */
	public function test_export_denied_below_organiser_tier() {
		foreach ( array( 'subscriber', 'author' ) as $role ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );

			$permission = export_permissions_check();

			$this->assertWPError( $permission, "A {$role} must not be able to export." );
			$this->assertSame( 'rest_forbidden', $permission->get_error_code() );
			$this->assertSame( 403, $permission->get_error_data()['status'] );
		}
	}

	/**
	 * Organisers (editors) and administrators can export.
	 */
	public function test_export_allowed_for_organiser_tier() {
		foreach ( array( 'editor', 'administrator' ) as $role ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );

			$this->assertTrue( export_permissions_check(), "An {$role} must be able to export." );
		}
	}

	/**
	 * The route enforces the permission callback on real dispatches too, and
	 * rejects unknown formats via the arg schema.
	 */
	public function test_export_route_dispatch() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$response = $this->dispatch_export_request();
		$this->assertSame( 403, $response->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = $this->dispatch_export_request( array( 'format' => 'xml' ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
	}

	/**
	 * The default format is a CSV attachment; JSON gets its own filename.
	 */
	public function test_export_response_headers() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = $this->dispatch_export_request();
		$headers  = $response->get_headers();
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringStartsWith( 'text/csv', $headers['Content-Type'] );
		$this->assertStringContainsString( '.csv"', $headers['Content-Disposition'] );

		$response = $this->dispatch_export_request( array( 'format' => 'json' ) );
		$headers  = $response->get_headers();
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( '.json"', $headers['Content-Disposition'] );
	}

	/**
	 * Counts are broken down by status; RSVPs GatherPress left in `no_status`
	 * appear as records but don't inflate any of the three counts.
	 */
	public function test_export_counts_by_status() {
		$event_id = $this->create_test_event();

		$this->create_rsvp( $event_id, self::factory()->user->create(), 'attending' );
		$this->create_rsvp( $event_id, self::factory()->user->create(), 'attending' );
		$this->create_rsvp( $event_id, self::factory()->user->create(), 'waiting_list' );
		$this->create_rsvp( $event_id, self::factory()->user->create(), 'not_attending' );
		$this->create_rsvp( $event_id, self::factory()->user->create(), 'no_status' );

		$data  = collect_export_data();
		$event = $data['events'][0];

		$this->assertSame( $event_id, $event['id'] );
		$this->assertSame(
			array(
				'attending'     => 2,
				'waiting_list'  => 1,
				'not_attending' => 1,
			),
			$event['counts']
		);
		$this->assertCount( 5, $event['rsvps'] );
	}

	/**
	 * Event fields carry through: dates from the GatherPress table, the
	 * organiser's display name, and the attendee's identity and RSVP details.
	 */
	public function test_export_event_and_rsvp_fields() {
		$event_id = $this->create_test_event( array( 'title' => 'Coffee Meetup' ) );
		$user_id  = self::factory()->user->create(
			array(
				'display_name' => 'Jane Doe',
				'user_login'   => 'janedoe',
			)
		);
		$this->create_rsvp(
			$event_id,
			$user_id,
			'attending',
			array(
				'guests'        => 2,
				'timestamp_gmt' => '2026-01-02 03:04:05',
			)
		);

		$event = collect_export_data()['events'][0];

		$this->assertSame( 'Coffee Meetup', $event['title'] );
		$this->assertNotEmpty( $event['start_gmt'] );
		$this->assertNotEmpty( $event['end_gmt'] );
		$this->assertSame( get_userdata( (int) get_post_field( 'post_author', $event_id ) )->display_name, $event['organiser'] );

		$rsvp = $event['rsvps'][0];
		$this->assertSame( 'Jane Doe', $rsvp['attendee_name'] );
		$this->assertSame( 'janedoe', $rsvp['attendee_login'] );
		$this->assertSame( 'attending', $rsvp['status'] );
		$this->assertSame( '2026-01-02 03:04:05', $rsvp['timestamp_gmt'] );
		$this->assertSame( 2, $rsvp['guests'] );
		$this->assertFalse( $rsvp['anonymous'] );
	}

	/**
	 * Venue resolves to the venue post's title for physical events, the
	 * "Online" label for online events, and stays empty with no venue.
	 */
	public function test_export_venue_names() {
		$physical_id = $this->create_test_event( array( 'new_venue_name' => 'Community Hall' ) );

		// The `online-event` sentinel term normally exists on a production
		// site; the fixture blog starts without it.
		if ( ! term_exists( 'online-event', '_gatherpress_venue' ) ) {
			wp_insert_term( 'Online event', '_gatherpress_venue', array( 'slug' => 'online-event' ) );
		}
		$online_id = $this->create_test_event(
			array(
				'is_online'         => true,
				'online_event_link' => 'https://meet.example.org/coffee',
			)
		);

		$bare_id = $this->create_test_event();

		$venues = array_column( collect_export_data()['events'], 'venue', 'id' );

		$this->assertSame( 'Community Hall', $venues[ $physical_id ] );
		$this->assertSame( 'Online', $venues[ $online_id ] );
		$this->assertSame( '', $venues[ $bare_id ] );
	}

	/**
	 * The export carries the title the organizer typed, not its stored encoding.
	 *
	 * `collect_export_data()` deliberately reads the raw `post_title` rather than
	 * `get_the_title()`, because entity-encoding belongs to HTML output and not to a
	 * spreadsheet. Titles are stored encoded (see `wcorg_sanitize_plain_text()`), so
	 * honouring that intent means decoding on the way out.
	 */
	public function test_export_event_title_is_decoded() {
		$event_id = $this->create_test_event( array( 'title' => 'Hall < 100 > seats' ) );

		$stored = (string) get_post_field( 'post_title', $event_id, 'raw' );
		$this->assertStringContainsString( '&lt;', $stored, 'Fixture precondition: the stored title is encoded.' );

		$titles = array_column( collect_export_data()['events'], 'title', 'id' );
		$this->assertSame( 'Hall < 100 > seats', $titles[ $event_id ] );

		// Both formats read the same array, so the CSV follows — and the decoded
		// value must not gain a leading `=`/`+`/`-`/`@` treatment it didn't need.
		$rows = str_getcsv( build_csv( collect_export_data(), CSV_COLUMNS ), "\n" );
		$this->assertStringContainsString( 'Hall < 100 > seats', implode( "\n", $rows ) );
	}

	/**
	 * Venue names travel the same path, from the venue post title and from the
	 * term-name fallback — both of which are stored entity-encoded.
	 */
	public function test_export_venue_name_is_decoded() {
		$event_id = $this->create_test_event( array( 'new_venue_name' => 'Hall < 100 > seats' ) );

		$venues = array_column( collect_export_data()['events'], 'venue', 'id' );

		$this->assertSame( 'Hall < 100 > seats', $venues[ $event_id ] );
	}

	/**
	 * Anonymous RSVPs export a stable, non-identifying token — never the
	 * member's name or login.
	 */
	public function test_export_anonymous_rsvp_token() {
		$event_id = $this->create_test_event();
		$user_id  = self::factory()->user->create(
			array(
				'display_name' => 'Secret Sam',
				'user_login'   => 'secretsam',
			)
		);
		$this->create_rsvp( $event_id, $user_id, 'attending', array( 'anonymous' => true ) );

		$rsvp = collect_export_data()['events'][0]['rsvps'][0];

		$this->assertMatchesRegularExpression( '/^anonymous-[0-9a-f]{12}$/', $rsvp['attendee_name'] );
		$this->assertSame( '', $rsvp['attendee_login'] );
		$this->assertTrue( $rsvp['anonymous'] );

		// Stable across exports, so re-exports stay comparable.
		$again = collect_export_data()['events'][0]['rsvps'][0];
		$this->assertSame( $rsvp['attendee_name'], $again['attendee_name'] );

		// The token must not leak the identity anywhere in the export.
		$serialized = wp_json_encode( collect_export_data() );
		$this->assertStringNotContainsString( 'Secret Sam', $serialized );
		$this->assertStringNotContainsString( 'secretsam', $serialized );
	}

	/**
	 * Events with no RSVPs still appear: as an empty list in JSON, and as a
	 * single row with blank RSVP columns in CSV.
	 */
	public function test_export_includes_zero_rsvp_events() {
		$event_id = $this->create_test_event( array( 'title' => 'Lonely Event' ) );

		$data = collect_export_data();
		$this->assertSame( array(), $data['events'][0]['rsvps'] );

		$rows = $this->parse_csv_rows( build_csv( $data ) );
		$this->assertCount( 2, $rows ); // Header + one event row.
		$this->assertSame( (string) $event_id, $rows[1][0] );
		$this->assertSame( 'Lonely Event', $rows[1][1] );
		$this->assertSame( '', $rows[1][11], 'attendee_name must be blank on a zero-RSVP row.' );
	}

	/**
	 * CSV structure: exact header, one row per RSVP, BOM for Excel, and
	 * values with commas/quotes surviving a round-trip.
	 */
	public function test_export_csv_structure() {
		$event_id = $this->create_test_event( array( 'title' => 'Title, with "quotes"' ) );
		$this->create_rsvp( $event_id, self::factory()->user->create(), 'attending' );
		$this->create_rsvp( $event_id, self::factory()->user->create(), 'waiting_list' );

		$csv = build_csv( collect_export_data() );

		$this->assertStringStartsWith( "\xEF\xBB\xBF", $csv );

		$rows = $this->parse_csv_rows( $csv );
		$this->assertSame( CSV_COLUMNS, $rows[0] );
		$this->assertCount( 3, $rows ); // Header + one row per RSVP.
		$this->assertSame( 'Title, with "quotes"', $rows[1][1] );

		// RFC 4180: a backslash before a quote is data, not an escape — the
		// row must still parse to the right number of columns, with the
		// stored title intact.
		$tricky_id = $this->create_test_event( array( 'title' => 'Bad \\ slash, "and" quotes' ) );
		$rows      = $this->parse_csv_rows( build_csv( collect_export_data() ) );
		foreach ( $rows as $row ) {
			$this->assertCount( count( CSV_COLUMNS ), $row );
		}
		$this->assertContains( get_post_field( 'post_title', $tricky_id, 'raw' ), array_column( $rows, 1 ) );
	}

	/**
	 * Cells that a spreadsheet would execute as formulas are neutralised.
	 */
	public function test_export_csv_escapes_formulas() {
		$event_id = $this->create_test_event( array( 'title' => '=HYPERLINK("https://evil.example")' ) );
		$this->create_rsvp(
			$event_id,
			self::factory()->user->create( array( 'display_name' => '+SUM(A1:A9)' ) ),
			'attending'
		);

		$rows = $this->parse_csv_rows( build_csv( collect_export_data() ) );

		$this->assertStringStartsWith( "'=", $rows[1][1] );
		$this->assertStringStartsWith( "'+", $rows[1][11] );

		// Unit-level: every trigger character is prefixed, plain text is not.
		foreach ( array( '=x', '+x', '-x', '@x' ) as $dangerous ) {
			$this->assertSame( "'" . $dangerous, esc_csv_cell( $dangerous ) );
		}
		$this->assertSame( 'safe', esc_csv_cell( 'safe' ) );
		$this->assertSame( '', esc_csv_cell( '' ) );

		// Whitespace a spreadsheet would trim doesn't hide the trigger.
		$this->assertSame( "'\t=x", esc_csv_cell( "\t=x" ) );
		$this->assertSame( "' =x", esc_csv_cell( ' =x' ) );

		// Triggers further into the value are data, not formulas — every cell
		// is quoted, so no delimiter choice can promote one to a cell start.
		$this->assertSame( 'WordPress - Istanbul', esc_csv_cell( 'WordPress - Istanbul' ) );
		$this->assertSame( 'Meetup @ Venue', esc_csv_cell( 'Meetup @ Venue' ) );
		$this->assertSame( 'safe;=1+1', esc_csv_cell( 'safe;=1+1' ) );

		// Negative numbers are numeric, not formulas.
		$this->assertSame( '-5', esc_csv_cell( '-5' ) );
	}

	/**
	 * Every field is quoted, so a reader that splits on a delimiter other
	 * than the comma still sees one field per value rather than a formula.
	 */
	public function test_export_csv_quotes_every_field() {
		$event_id = $this->create_test_event( array( 'title' => 'Semicolons; =1+1' ) );

		$csv = build_csv( collect_export_data() );

		$this->assertStringContainsString( '"event_id","event_title"', $csv );
		$this->assertStringContainsString( '"' . $event_id . '","Semicolons; =1+1"', $csv );
	}

	/**
	 * The events filter narrows the export to the selected event IDs.
	 */
	public function test_export_filters_by_events() {
		$kept_id = $this->create_test_event( array( 'title' => 'Kept' ) );
		$this->create_test_event( array( 'title' => 'Dropped' ) );

		$data = collect_export_data( array( 'events' => array( $kept_id ) ) );

		$this->assertCount( 1, $data['events'] );
		$this->assertSame( $kept_id, $data['events'][0]['id'] );
	}

	/**
	 * Rewrites an event's dates-table row, so tests can build past events —
	 * the create endpoint itself refuses dates in the past.
	 */
	private function backdate_event( int $event_id, string $start_gmt, string $end_gmt ): void {
		global $wpdb;

		$table = sprintf( \GatherPress\Core\Event\Event::TABLE_FORMAT, $wpdb->prefix );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture writing to GatherPress's custom table.
		$wpdb->update(
			$table,
			array(
				'datetime_start_gmt' => $start_gmt,
				'datetime_end_gmt'   => $end_gmt,
			),
			array( 'post_id' => $event_id )
		);
	}

	/**
	 * The range filter keeps upcoming, past, or a custom start-date window.
	 */
	public function test_export_filters_by_range() {
		$past_id = $this->create_test_event( array( 'title' => 'Past Event' ) );
		$this->backdate_event(
			$past_id,
			gmdate( 'Y-m-d H:i:s', strtotime( '-2 weeks' ) ),
			gmdate( 'Y-m-d H:i:s', strtotime( '-2 weeks +2 hours' ) )
		);
		$future_id = $this->create_test_event( array( 'title' => 'Future Event' ) ); // +1 week.

		$upcoming = collect_export_data( array( 'range' => 'upcoming' ) );
		$this->assertSame( array( $future_id ), array_column( $upcoming['events'], 'id' ) );

		$past = collect_export_data( array( 'range' => 'past' ) );
		$this->assertSame( array( $past_id ), array_column( $past['events'], 'id' ) );

		// A window around the past event only, one day of slack on each side.
		$custom = collect_export_data(
			array(
				'range'  => 'custom',
				'after'  => gmdate( 'Y-m-d', strtotime( '-2 weeks -1 day' ) ),
				'before' => gmdate( 'Y-m-d', strtotime( '-2 weeks +1 day' ) ),
			)
		);
		$this->assertSame( array( $past_id ), array_column( $custom['events'], 'id' ) );

		// Open-ended lower bound keeps everything from the past event on.
		$open = collect_export_data(
			array(
				'range' => 'custom',
				'after' => gmdate( 'Y-m-d', strtotime( '-3 weeks' ) ),
			)
		);
		$this->assertCount( 2, $open['events'] );
	}

	/**
	 * A column subset trims the CSV to those columns, in canonical order.
	 */
	public function test_export_csv_column_selection() {
		$event_id = $this->create_test_event( array( 'title' => 'Column Test' ) );
		$this->create_rsvp(
			$event_id,
			self::factory()->user->create( array( 'display_name' => 'Jane Doe' ) ),
			'attending'
		);

		$rows = $this->parse_csv_rows(
			build_csv(
				collect_export_data(),
				array( 'event_title', 'attendee_name', 'rsvp_status' )
			)
		);

		$this->assertSame( array( 'event_title', 'attendee_name', 'rsvp_status' ), $rows[0] );
		$this->assertSame( array( 'Column Test', 'Jane Doe', 'attending' ), $rows[1] );
	}

	/**
	 * The same column selection trims the JSON export's fields.
	 */
	public function test_export_json_field_selection() {
		$event_id = $this->create_test_event( array( 'title' => 'JSON Fields' ) );
		$this->create_rsvp( $event_id, self::factory()->user->create(), 'attending' );

		$data  = filter_json_fields(
			collect_export_data(),
			array( 'event_title', 'attendee_name', 'rsvp_status' )
		);
		$event = $data['events'][0];

		$this->assertSame( 'JSON Fields', $event['title'] );
		$this->assertArrayNotHasKey( 'venue', $event );
		$this->assertArrayNotHasKey( 'counts', $event );
		$this->assertArrayNotHasKey( 'occurrences', $event );

		$rsvp = $event['rsvps'][0];
		$this->assertArrayHasKey( 'attendee_name', $rsvp );
		$this->assertArrayHasKey( 'anonymous', $rsvp ); // Travels with attendee_name.
		$this->assertSame( 'attending', $rsvp['status'] );
		$this->assertArrayNotHasKey( 'timestamp_gmt', $rsvp );
		$this->assertArrayNotHasKey( 'guests', $rsvp );

		// No RSVP-level columns selected → the rsvps list is dropped.
		$counts_only = filter_json_fields( collect_export_data(), array( 'attending_count' ) );
		$this->assertArrayNotHasKey( 'rsvps', $counts_only['events'][0] );
		$this->assertSame( array( 'attending' => 1 ), $counts_only['events'][0]['counts'] );
	}

	/**
	 * Either occurrence column keeps the series occurrence list, trimmed to
	 * the selected value(s).
	 */
	public function test_export_json_occurrence_field_selection() {
		$this->create_recurring_test_event( 2 );

		$event = filter_json_fields(
			collect_export_data(),
			array( 'event_id', 'occurrence_end_gmt' )
		)['events'][0];

		$this->assertNotEmpty( $event['occurrences'] );
		$this->assertArrayHasKey( 'end_gmt', $event['occurrences'][0] );
		$this->assertArrayNotHasKey( 'start_gmt', $event['occurrences'][0] );
		$this->assertSame( 'scheduled', $event['occurrences'][0]['status'] );
	}

	/**
	 * Filter params are validated on real dispatches.
	 */
	public function test_export_route_validates_filter_params() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = $this->dispatch_export_request( array( 'columns' => array( 'bogus_column' ) ) );
		$this->assertSame( 400, $response->get_status() );

		$response = $this->dispatch_export_request( array( 'range' => 'someday' ) );
		$this->assertSame( 400, $response->get_status() );

		$response = $this->dispatch_export_request( array(
			'range' => 'custom', 'after' => 'not-a-date',
		) );
		$this->assertSame( 400, $response->get_status() );

		// Right shape, impossible calendar date.
		$response = $this->dispatch_export_request( array(
			'range' => 'custom', 'before' => '2026-99-99',
		) );
		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * RSVPs mapped to an occurrence carry that occurrence's dates; unmapped
	 * RSVPs on the same series don't.
	 */
	public function test_export_occurrence_attribution() {
		$event_id    = $this->create_recurring_test_event( 3 );
		$occurrences = Occurrences::all( $event_id, 'upcoming', 10 );
		$this->assertNotEmpty( $occurrences, 'The recurring event must project occurrences.' );
		$first = $occurrences[0];

		$mapped_id   = $this->create_rsvp( $event_id, self::factory()->user->create(), 'attending' );
		$unmapped_id = $this->create_rsvp( $event_id, self::factory()->user->create(), 'attending' );
		Recurring_Events_Database::map_comment( $mapped_id, $event_id, $first->recurrence_id );

		$event = collect_export_data()['events'][0];
		$this->assertTrue( $event['is_recurring'] );
		$this->assertCount( 3, $event['occurrences'] );

		$rsvps_by_comment = array();
		foreach ( $event['rsvps'] as $index => $rsvp ) {
			$rsvps_by_comment[ array( $mapped_id, $unmapped_id )[ $index ] ] = $rsvp;
		}

		$this->assertSame( $first->datetime_start_gmt, $rsvps_by_comment[ $mapped_id ]['occurrence_start_gmt'] );
		$this->assertSame( $first->datetime_end_gmt, $rsvps_by_comment[ $mapped_id ]['occurrence_end_gmt'] );
		$this->assertNull( $rsvps_by_comment[ $unmapped_id ]['occurrence_start_gmt'] );
	}

	/**
	 * A recurring series is exported occurrence by occurrence: every date
	 * gets a row even with no RSVPs, and the counts on each row are that
	 * occurrence's, not the series'.
	 */
	public function test_export_csv_row_per_occurrence() {
		$event_id    = $this->create_recurring_test_event( 3 );
		$occurrences = Occurrences::all( $event_id, 'upcoming', 10 );
		$this->assertCount( 3, $occurrences );

		// One RSVP each on the first two dates; nobody on the third.
		foreach ( array( 0, 1 ) as $index ) {
			$comment_id = $this->create_rsvp( $event_id, self::factory()->user->create(), 'attending' );
			Recurring_Events_Database::map_comment( $comment_id, $event_id, $occurrences[ $index ]->recurrence_id );
		}

		$data = collect_export_data();
		$rows = array_slice( $this->parse_csv_rows( build_csv( $data ) ), 1 );

		$this->assertCount( 3, $rows, 'Every occurrence needs a row, RSVPs or not.' );
		$this->assertSame(
			wp_list_pluck( $occurrences, 'datetime_start_gmt' ),
			array_column( $rows, 9 )
		);

		// Series-wide counts would report 2 attending on all three rows.
		$this->assertSame( array( '1', '1', '0' ), array_column( $rows, 6 ) );
		$this->assertSame( '', $rows[2][11], 'The RSVP-less occurrence has no attendee.' );

		// The JSON export carries the same per-occurrence counts, alongside
		// the series totals at the event level.
		$event = $data['events'][0];
		$this->assertSame( 2, $event['counts']['attending'] );
		$this->assertSame( array( 1, 1, 0 ), array_column( array_column( $event['occurrences'], 'counts' ), 'attending' ) );
	}

	/**
	 * The range filter judges a series by its occurrences, not by the series
	 * row — and drops the occurrences that fall outside the range.
	 */
	public function test_export_range_filter_uses_occurrences() {
		$event_id = $this->create_recurring_test_event( 3 );

		// A past series row with future occurrences: judging by the row alone
		// hid the series from 'upcoming' and exported future dates as 'past'.
		$this->backdate_event(
			$event_id,
			gmdate( 'Y-m-d H:i:s', strtotime( '-2 weeks' ) ),
			gmdate( 'Y-m-d H:i:s', strtotime( '-2 weeks +2 hours' ) )
		);

		$upcoming = collect_export_data( array( 'range' => 'upcoming' ) );
		$this->assertSame( array( $event_id ), array_column( $upcoming['events'], 'id' ) );
		$this->assertCount( 3, $upcoming['events'][0]['occurrences'] );

		$this->assertSame( array(), collect_export_data( array( 'range' => 'past' ) )['events'] );

		// A window covering only the first occurrence keeps just that one.
		$custom = collect_export_data(
			array(
				'range'  => 'custom',
				'after'  => gmdate( 'Y-m-d', strtotime( '+13 days' ) ),
				'before' => gmdate( 'Y-m-d', strtotime( '+15 days' ) ),
			)
		);
		$this->assertCount( 1, $custom['events'][0]['occurrences'] );
		$this->assertCount( 1, array_slice( $this->parse_csv_rows( build_csv( $custom ) ), 1 ) );
	}
}
