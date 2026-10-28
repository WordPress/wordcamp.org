<?php

namespace WordCamp\Groups\Tests;

use GatherPress\Core\Event\Event;
use WP_REST_Request;

use function WordCamp\Groups\Frontend\Event_Date_Format\apply_to_event_date_block;
use function WordCamp\Groups\Frontend\Event_Date_Format\build_choices;
use function WordCamp\Groups\Frontend\Event_Date_Format\get_date_format;
use function WordCamp\Groups\Frontend\Event_Date_Format\get_date_formats;
use function WordCamp\Groups\Frontend\Event_Date_Format\get_time_format;
use function WordCamp\Groups\Frontend\Event_Date_Format\get_time_formats;
use function WordCamp\Groups\Frontend\Event_Date_Format\set_date_format;
use function WordCamp\Groups\Frontend\Event_Date_Format\set_time_format;
use function WordCamp\Groups\Frontend\REST\get_group_info;
use function WordCamp\Groups\Frontend\REST\update_group_info;

use const WordCamp\Groups\Frontend\Event_Date_Format\DATE_CLASS;
use const WordCamp\Groups\Frontend\Event_Date_Format\DATE_OPTION;
use const WordCamp\Groups\Frontend\Event_Date_Format\DATETIME_CLASS;
use const WordCamp\Groups\Frontend\Event_Date_Format\TIME_CLASS;
use const WordCamp\Groups\Frontend\Event_Date_Format\TIME_OPTION;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * @group groups
 */
class Test_Groups_Event_Date_Format extends Groups_TestCase {

	/**
	 * An organizer, who may change this group's settings.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Creates the organizer each test acts as.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * Clears any format a test chose, so the next one starts on the
	 * templates' own.
	 */
	protected function tearDown(): void {
		delete_option( DATE_OPTION );
		delete_option( TIME_OPTION );

		parent::tearDown();
	}

	/**
	 * Build the parsed block a template's marked event-date block produces.
	 *
	 * @param string $role_class The role class name.
	 * @param array  $attrs      Attributes the template carries.
	 */
	private function marked_block( string $role_class, array $attrs ): array {
		return array(
			'blockName' => 'gatherpress/event-date',
			'attrs'     => array( 'className' => $role_class ) + $attrs,
		);
	}

	/**
	 * Until a group chooses, every surface keeps the format its template
	 * already carried. This is what makes the setting safe to ship: nothing
	 * moves for a group that never opens the tab.
	 */
	public function test_nothing_changes_until_a_group_chooses() {
		$this->assertSame( '', get_date_format() );
		$this->assertSame( '', get_time_format() );

		$block = $this->marked_block( DATE_CLASS, array( 'startDateFormat' => 'l, F j' ) );

		$this->assertSame( $block, apply_to_event_date_block( $block ) );
		$this->assertSame( 'F j, Y', apply_filters( 'gatherpress_date_format', 'F j, Y' ) );
		$this->assertSame( 'g:i a', apply_filters( 'gatherpress_time_format', 'g:i a' ) );
	}

	/**
	 * A format that is not on the offered list is not written. The stored
	 * value reaches `wp_date()`, so the allowlist is load-bearing rather than
	 * tidiness.
	 */
	public function test_an_unoffered_format_is_not_stored() {
		set_date_format( 'Y-m-d' );
		$this->assertSame( 'Y-m-d', get_date_format() );

		set_date_format( 'l jS \o\f F Y' );
		$this->assertSame( '', get_date_format(), 'An unoffered format clears the choice rather than being stored.' );

		set_time_format( 'g:i A' );
		set_time_format( 'B' );
		$this->assertSame( '', get_time_format() );
	}

	/**
	 * A value stored before the list changed reads back as "no choice"
	 * rather than as itself, so it can never reach `wp_date()`.
	 */
	public function test_a_stored_format_that_is_no_longer_offered_is_ignored() {
		update_option( DATE_OPTION, 'l jS \o\f F Y' );

		$this->assertSame( '', get_date_format() );
	}

	/**
	 * Choosing a date format rewrites the date line, and only the formats
	 * the block actually carried.
	 */
	public function test_the_date_role_takes_the_groups_date_format() {
		set_date_format( 'Y-m-d' );

		$block = apply_to_event_date_block(
			$this->marked_block( DATE_CLASS, array( 'startDateFormat' => 'l, F j' ) )
		);

		$this->assertSame( 'Y-m-d', $block['attrs']['startDateFormat'] );
		$this->assertArrayNotHasKey(
			'endDateFormat',
			$block['attrs'],
			'Adding an end format would turn a start-only line into a range.'
		);
	}

	/**
	 * The time line carries both a start and an end format, and both move.
	 */
	public function test_the_time_role_takes_both_of_the_blocks_formats() {
		set_time_format( 'H:i' );

		$block = apply_to_event_date_block(
			$this->marked_block(
				TIME_CLASS,
				array(
					'startDateFormat' => 'g:i A',
					'endDateFormat'   => 'g:i A',
				)
			)
		);

		$this->assertSame( 'H:i', $block['attrs']['startDateFormat'] );
		$this->assertSame( 'H:i', $block['attrs']['endDateFormat'] );
	}

	/**
	 * Each role only answers to its own choice, so a group that set one and
	 * not the other does not have the other quietly rewritten.
	 */
	public function test_each_role_answers_only_to_its_own_choice() {
		set_date_format( 'Y-m-d' );

		$time = apply_to_event_date_block(
			$this->marked_block( TIME_CLASS, array( 'startDateFormat' => 'g:i A' ) )
		);

		$this->assertSame( 'g:i A', $time['attrs']['startDateFormat'] );
	}

	/**
	 * The event cards show both in one block, composed from the two choices
	 * rather than from a third setting.
	 */
	public function test_the_combined_role_composes_both_choices() {
		set_date_format( 'Y-m-d' );
		set_time_format( 'H:i' );

		$block = apply_to_event_date_block(
			$this->marked_block( DATETIME_CLASS, array( 'startDateFormat' => 'M j, Y · g:i A' ) )
		);

		$this->assertSame( 'Y-m-d \\· H:i', $block['attrs']['startDateFormat'] );
	}

	/**
	 * Half an answer is not enough to rebuild a combined format, so the
	 * card keeps the one its template describes rather than rendering
	 * something nobody asked for.
	 */
	public function test_the_combined_role_needs_both_choices() {
		set_date_format( 'Y-m-d' );

		$block = apply_to_event_date_block(
			$this->marked_block( DATETIME_CLASS, array( 'startDateFormat' => 'M j, Y · g:i A' ) )
		);

		$this->assertSame( 'M j, Y · g:i A', $block['attrs']['startDateFormat'] );
	}

	/**
	 * An unmarked event-date block, and any other block, are left alone.
	 */
	public function test_unmarked_blocks_are_untouched() {
		set_date_format( 'Y-m-d' );

		$unmarked = array(
			'blockName' => 'gatherpress/event-date',
			'attrs'     => array( 'startDateFormat' => 'l, F j' ),
		);
		$this->assertSame( $unmarked, apply_to_event_date_block( $unmarked ) );

		$other = array(
			'blockName' => 'core/paragraph',
			'attrs'     => array( 'className' => DATE_CLASS ),
		);
		$this->assertSame( $other, apply_to_event_date_block( $other ) );
	}

	/**
	 * The emails are the half the block filter cannot reach: they call
	 * `get_display_datetime()` with no format at all, so GatherPress
	 * resolves its own setting through these filters.
	 */
	public function test_the_groups_choice_reaches_gatherpress_own_formats() {
		set_date_format( 'Y-m-d' );
		set_time_format( 'H:i' );

		$this->assertSame( 'Y-m-d', apply_filters( 'gatherpress_date_format', 'F j, Y' ) );
		$this->assertSame( 'H:i', apply_filters( 'gatherpress_time_format', 'g:i a' ) );
	}

	/**
	 * End to end through the block, rather than through the filter alone:
	 * a real event rendered by a marked block reads in the chosen format.
	 */
	public function test_a_marked_block_renders_an_event_in_the_chosen_format() {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
			)
		);

		( new Event( $event_id ) )->save_datetimes(
			array(
				'post_id'        => $event_id,
				'datetime_start' => '2026-09-29 18:00:00',
				'datetime_end'   => '2026-09-29 20:00:00',
				'timezone'       => 'UTC',
			)
		);

		$this->go_to( home_url( "?p={$event_id}&post_type=gatherpress_event" ) );

		$markup = '<!-- wp:gatherpress/event-date {"displayType":"start","startDateFormat":"l, F j","className":"' . DATE_CLASS . '"} /-->';

		$this->assertStringContainsString( 'Tuesday, September 29', do_blocks( $markup ) );

		set_date_format( 'Y-m-d' );

		$this->assertStringContainsString( '2026-09-29', do_blocks( $markup ) );
	}

	/**
	 * Every offered format renders an example, which is what the settings
	 * form labels its radio buttons with. A format that rendered nothing
	 * would be an unlabelled option.
	 */
	public function test_every_offered_format_renders_an_example() {
		foreach ( array_merge( get_date_formats(), get_time_formats() ) as $format ) {
			$this->assertNotEmpty( $format );
		}

		foreach ( build_choices( array_merge( get_date_formats(), get_time_formats() ) ) as $choice ) {
			$this->assertNotEmpty( $choice['example'], "Format {$choice['format']} rendered no example." );
			$this->assertNotSame( $choice['format'], $choice['example'], 'The example must be a rendered date, not the format code.' );
		}
	}

	/**
	 * The settings form reads its current values and its choices from the
	 * same request it already makes.
	 */
	public function test_group_info_exposes_the_formats_and_the_choices() {
		wp_set_current_user( $this->editor_id );
		set_date_format( 'Y-m-d' );

		$data = get_group_info()->get_data();

		$this->assertSame( 'Y-m-d', $data['dateFormat'] );
		$this->assertSame( '', $data['timeFormat'] );
		$this->assertNotEmpty( $data['dateChoices'] );
		$this->assertSame( array( 'format', 'example' ), array_keys( $data['dateChoices'][0] ) );
	}

	/**
	 * Saving writes both, and an empty value clears the choice rather than
	 * being stored as a format.
	 */
	public function test_group_info_saves_and_clears_the_formats() {
		wp_set_current_user( $this->editor_id );

		$request = new WP_REST_Request( 'POST', '/wporg-groups/v1/group-info' );
		$request->set_param( 'date_format', 'Y-m-d' );
		$request->set_param( 'time_format', 'H:i' );
		update_group_info( $request );

		$this->assertSame( 'Y-m-d', get_date_format() );
		$this->assertSame( 'H:i', get_time_format() );

		$clear = new WP_REST_Request( 'POST', '/wporg-groups/v1/group-info' );
		$clear->set_param( 'date_format', '' );
		$clear->set_param( 'time_format', '' );
		update_group_info( $clear );

		$this->assertSame( '', get_date_format() );
		$this->assertSame( '', get_time_format() );
		$this->assertFalse( get_option( DATE_OPTION, false ), 'Clearing removes the option rather than storing an empty one.' );
	}

	/**
	 * A request that says nothing about the formats leaves them alone, the
	 * same way the rest of this route treats absent fields -- otherwise
	 * saving the About tab would wipe a choice made on the Design tab.
	 */
	public function test_a_request_without_the_formats_leaves_them_alone() {
		wp_set_current_user( $this->editor_id );
		set_date_format( 'Y-m-d' );

		$request = new WP_REST_Request( 'POST', '/wporg-groups/v1/group-info' );
		$request->set_param( 'title', 'Still A Group' );
		update_group_info( $request );

		$this->assertSame( 'Y-m-d', get_date_format() );
	}
}
