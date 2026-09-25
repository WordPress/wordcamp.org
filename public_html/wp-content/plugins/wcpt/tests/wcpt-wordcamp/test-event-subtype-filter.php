<?php

namespace WordCamp\WCPT\Tests;
use WP_Query;
use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * Tests for the Event Subtype filter on the WordCamp list table.
 *
 * @group wcpt
 */
class Test_Event_Subtype_Filter extends WP_UnitTestCase {

	/**
	 * The admin instance under test, assigned in this suite's bootstrap.
	 *
	 * @var \WordCamp_Admin
	 */
	protected $admin;

	/**
	 * Set up the admin instance.
	 *
	 * The harness empties `$_GET`, `$_POST` and `$_REQUEST` in `set_up()` and replaces
	 * `$wp_the_query` in `tear_down()`, so neither needs clearing here.
	 */
	public function set_up() {
		parent::set_up();

		$this->admin = $GLOBALS['wordcamp_admin'];
	}

	/**
	 * The dropdown and the validation have to keep offering the same set, so pin it
	 * rather than deriving the expectation from the call under test.
	 *
	 * @covers WordCamp_Admin::get_event_subtypes
	 */
	public function test_event_subtypes_are_the_expected_set() {
		$this->assertSame(
			array( 'wordcamp', 'doaction', 'campusconnect', 'student-club', 'other' ),
			array_keys( $this->admin->get_event_subtypes() )
		);
	}

	/**
	 * Every key the dropdown offers has to survive the round trip.
	 *
	 * @covers WordCamp_Admin::get_requested_subtype
	 */
	public function test_known_subtypes_are_returned() {
		foreach ( array_keys( $this->admin->get_event_subtypes() ) as $subtype ) {
			$_GET['type'] = $subtype;

			$this->assertSame( $subtype, $this->admin->get_requested_subtype() );
		}
	}

	/**
	 * @covers WordCamp_Admin::get_requested_subtype
	 */
	public function test_absent_type_returns_empty_string() {
		$this->assertSame( '', $this->admin->get_requested_subtype() );
	}

	/**
	 * `alter_views()` uses the return value as an array key into
	 * `get_event_subtypes()`, so an unknown value used to be an undefined key.
	 *
	 * @covers WordCamp_Admin::get_requested_subtype
	 */
	public function test_unknown_subtype_returns_empty_string() {
		$_GET['type'] = 'not-a-subtype';

		$this->assertSame( '', $this->admin->get_requested_subtype() );
	}

	/**
	 * The value is spliced into view markup that core has already escaped, and
	 * `sanitize_text_field()` passes quotes through byte-for-byte, so anything
	 * carrying attribute syntax has to collapse to no filter. The second case is the
	 * one a weaker prefix check would let through.
	 *
	 * @dataProvider data_markup_bearing_values
	 * @covers WordCamp_Admin::get_requested_subtype
	 *
	 * @param string $value The requested type.
	 */
	public function test_markup_bearing_subtype_returns_empty_string( $value ) {
		$_GET['type'] = $value;

		$this->assertSame( '', $this->admin->get_requested_subtype() );
	}

	/**
	 * Values that carry attribute syntax.
	 *
	 * @return array
	 */
	public function data_markup_bearing_values() {
		return array(
			'attribute break' => array( '" autofocus onfocus="alert(1)//' ),
			'known key plus'  => array( 'wordcamp" autofocus onfocus="alert(1)//' ),
		);
	}

	/**
	 * An array reaches `sanitize_text_field()`, which returns an empty string for one.
	 *
	 * @covers WordCamp_Admin::get_requested_subtype
	 */
	public function test_array_type_returns_empty_string() {
		$_GET['type'] = array( 'wordcamp' );

		$this->assertSame( '', $this->admin->get_requested_subtype() );
	}

	/**
	 * `sanitize_text_field()` trims, so a padded known key still selects it.
	 *
	 * @covers WordCamp_Admin::get_requested_subtype
	 */
	public function test_padded_known_subtype_is_returned() {
		$_GET['type'] = "  wordcamp \t";

		$this->assertSame( 'wordcamp', $this->admin->get_requested_subtype() );
	}

	/**
	 * The list table links are GET, so a POSTed value is not a filter.
	 *
	 * @covers WordCamp_Admin::get_requested_subtype
	 */
	public function test_posted_type_is_ignored() {
		$_POST['type']    = 'doaction';
		$_REQUEST['type'] = 'doaction';

		$this->assertSame( '', $this->admin->get_requested_subtype() );
	}

	/**
	 * A known subtype filters the query.
	 *
	 * @covers WordCamp_Admin::filter_by_subtype
	 */
	public function test_filter_by_subtype_adds_meta_query_for_known_subtype() {
		$_GET['type'] = 'campusconnect';

		$query = $this->build_main_query();
		$this->admin->filter_by_subtype( $query );

		$this->assertSame(
			array(
				array(
					'key'     => 'event_subtype',
					'value'   => 'campusconnect',
					'compare' => '=',
				),
			),
			$query->get( 'meta_query' )
		);
	}

	/**
	 * `filter_mentoring_view()` appends to the same query var, and both run for
	 * `?type=…&mentoring=…`, so the subtype clause has to be additive.
	 *
	 * @covers WordCamp_Admin::filter_by_subtype
	 */
	public function test_filter_by_subtype_preserves_an_existing_meta_query() {
		$_GET['type'] = 'wordcamp';

		$existing = array(
			'key'   => 'Mentor WordPress.org User Name',
			'value' => 'someone',
		);

		$query = $this->build_main_query();
		$query->set( 'meta_query', array( $existing ) );

		$this->admin->filter_by_subtype( $query );

		$this->assertSame(
			array(
				$existing,
				array(
					'key'     => 'event_subtype',
					'value'   => 'wordcamp',
					'compare' => '=',
				),
			),
			$query->get( 'meta_query' )
		);
	}

	/**
	 * An unknown subtype leaves the query alone, so the screen shows the
	 * unfiltered list rather than filtering to nothing.
	 *
	 * @covers WordCamp_Admin::filter_by_subtype
	 */
	public function test_filter_by_subtype_ignores_unknown_subtype() {
		$_GET['type'] = '" autofocus onfocus="alert(1)//';

		$query = $this->build_main_query();
		$this->admin->filter_by_subtype( $query );

		$this->assertSame( '', $query->get( 'meta_query' ) );
	}

	/**
	 * Build a WordCamp query that reports itself as the main query.
	 *
	 * `WP_Query::is_main_query()` compares against `$wp_the_query`, which the harness
	 * replaces in `tear_down()`.
	 *
	 * @return WP_Query
	 */
	protected function build_main_query() {
		$query = new WP_Query();
		$query->set( 'post_type', WCPT_POST_TYPE_ID );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- `is_main_query()` compares against it.
		$GLOBALS['wp_the_query'] = $query;

		return $query;
	}
}
