<?php

namespace WordCamp\Groups\Tests;

use WP_UnitTestCase;

use function WordCamp\Groups\Frontend\RSVP_Labels\get_count_formats;
use function WordCamp\Groups\Frontend\RSVP_Labels\get_count_label;

defined( 'WPINC' ) || die();

/**
 * Covers the RSVP count line.
 *
 * The wording is produced twice — here for the server-rendered markup, and in
 * `view.js` for the post-RSVP update — so these tests pin the four branches.
 * The matching browser-side cases live in `tests-js/event-rsvp.test.js`; the
 * two files share a case list on purpose, so a change to one branch fails the
 * other.
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
	 * The formats shipped to `view.js` render the same line as the server, at
	 * counts other than the ones they were harvested at.
	 */
	public function test_shipped_formats_match_the_server_label(): void {
		$formats = get_count_formats();

		$this->assertSame( get_count_label( 0, false ), $formats['countZero'] );
		$this->assertSame( get_count_label( 1, true ), $formats['countYouFirst'] );
		$this->assertSame( get_count_label( 1, false ), sprintf( $formats['countSingular'], '1' ) );
		$this->assertSame( get_count_label( 14, false ), sprintf( $formats['countPlural'], '14' ) );
		$this->assertSame( get_count_label( 2, true ), sprintf( $formats['countYouAndOneOther'], '1' ) );
		$this->assertSame( get_count_label( 15, true ), sprintf( $formats['countYouAndOthers'], '14' ) );
	}
}
