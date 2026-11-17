<?php

namespace WordCamp\Groups\Tests;

use WP_UnitTestCase;

use function WordCamp\Groups\Frontend\RSVP_Labels\get_count_formats;
use function WordCamp\Groups\Frontend\RSVP_Labels\get_count_label;
use function WordCamp\Groups\Frontend\RSVP_Labels\get_count_parts;

use const WordCamp\Groups\Frontend\RSVP_Labels\COUNT_FORMAT_KEYS;

defined( 'WPINC' ) || die();

/**
 * Covers the RSVP count line.
 *
 * The wording is produced twice — here for the server-rendered markup, and in
 * `view.js` for the post-RSVP update — so these tests pin the four branches
 * and the format table that the view module selects from. The matching
 * browser-side cases live in `tests-js/event-rsvp.test.js`; the two files
 * share a case list on purpose, so a change to one branch fails the other.
 *
 * @group groups
 */
class Test_Groups_RSVP_Labels extends WP_UnitTestCase {
	/**
	 * Every count/attending combination the line has to describe.
	 *
	 * @return array<string, array{0: int, 1: bool, 2: string}>
	 */
	public function data_count_labels(): array {
		return array(
			'nobody yet'                 => array( 0, false, 'Be the first to RSVP' ),
			'one other person'           => array( 1, false, '1 going' ),
			'several other people'       => array( 14, false, '14 going' ),
			'you, alone'                 => array( 1, true, 'First one in' ),
			'you and one other'          => array( 2, true, 'You and 1 other' ),
			'you and several others'     => array( 15, true, 'You and 14 others' ),
			// Not reachable through the UI, but `$count` comes from GatherPress
			// and the branch order shouldn't fall through to "N going" if it
			// ever disagrees with the stored RSVP.
			'attending with a zero count' => array( 0, true, 'First one in' ),
		);
	}

	/**
	 * @dataProvider data_count_labels
	 *
	 * @param int    $count        Number of attendees.
	 * @param bool   $is_attending Whether the current user is one of them.
	 * @param string $expected     The finished line.
	 */
	public function test_get_count_label( int $count, bool $is_attending, string $expected ): void {
		$this->assertSame( $expected, get_count_label( $count, $is_attending ) );
	}

	/**
	 * The view module indexes `context.labels` by these exact keys, so a rename
	 * here without the matching rename in `view.js` would silently render an
	 * empty count line rather than fail.
	 */
	public function test_count_formats_cover_the_documented_keys(): void {
		$formats = get_count_formats();

		$this->assertSame( COUNT_FORMAT_KEYS, array_keys( $formats ) );
		$this->assertNotContains( '', $formats, 'Every count format should be a non-empty string.' );
	}

	/**
	 * The formats are harvested from `get_count_parts()` rather than restated,
	 * so each one has to come back as the untouched format string — a filled-in
	 * number here would mean the table was built from finished labels and the
	 * view module would render "You and 1 other" for every count.
	 */
	public function test_count_formats_keep_their_placeholder(): void {
		$formats = get_count_formats();

		foreach ( array( 'countSingular', 'countPlural', 'countYouAndOneOther', 'countYouAndOthers' ) as $key ) {
			$this->assertStringContainsString( '%s', $formats[ $key ], "$key should still take an argument." );
		}

		foreach ( array( 'countZero', 'countYouFirst' ) as $key ) {
			$this->assertStringNotContainsString( '%s', $formats[ $key ], "$key takes no argument." );
		}
	}

	/**
	 * `get_count_label()` has to agree with the table the view module gets,
	 * otherwise the line would change wording on RSVP for reasons unrelated to
	 * the count. Checked at the counts the table itself is harvested from.
	 */
	public function test_server_label_matches_the_harvested_formats(): void {
		$formats = get_count_formats();

		$this->assertSame( $formats['countZero'], get_count_label( 0, false ) );
		$this->assertSame( $formats['countYouFirst'], get_count_label( 1, true ) );
		$this->assertSame( sprintf( $formats['countSingular'], '1' ), get_count_label( 1, false ) );
		$this->assertSame( sprintf( $formats['countPlural'], '2' ), get_count_label( 2, false ) );
		$this->assertSame( sprintf( $formats['countYouAndOneOther'], '1' ), get_count_label( 2, true ) );
		$this->assertSame( sprintf( $formats['countYouAndOthers'], '2' ), get_count_label( 3, true ) );
	}

	/**
	 * The server side passes the real count to `_n()` rather than picking from
	 * the two-entry table, which is what lets languages with more than two
	 * plural forms get the right one on first paint.
	 */
	public function test_parts_pass_the_real_count_to_the_translator(): void {
		$this->assertSame( array( '%s going', 9 ), get_count_parts( 9, false ) );
		$this->assertSame( array( 'You and %s others', 8 ), get_count_parts( 9, true ) );
	}
}
