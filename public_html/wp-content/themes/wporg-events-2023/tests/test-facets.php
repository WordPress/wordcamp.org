<?php

namespace WordPressdotorg\Theme\Events_2023\Tests;

use WP_UnitTestCase;
use function WordPressdotorg\Events_2023\{ get_query_var_facets, set_query_vars_from_pretty_url };

defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/inc/events-query.php';

/**
 * Tests for reading the filters back out of a "pretty" URL, like `/upcoming-events/filtered/format/online/`.
 *
 * @group events-2023
 */
class Test_Facets extends WP_UnitTestCase {
	/**
	 * `$wp->query_vars` before the test set `event_facets`.
	 *
	 * @var array
	 */
	protected $query_vars_before;

	/**
	 * Keep the main request's query vars, which each test replaces.
	 */
	public function set_up() {
		global $wp;

		parent::set_up();

		$this->query_vars_before = $wp->query_vars;
	}

	/**
	 * Restore the main request's query vars.
	 */
	public function tear_down() {
		global $wp;

		$wp->query_vars = $this->query_vars_before;

		parent::tear_down();
	}

	/**
	 * Read the facets for a pretty URL's facet path.
	 *
	 * @param string $facet_path The part after `filtered/`, like `format/in-person-online/`.
	 *
	 * @return array
	 */
	protected function facets_for( $facet_path ) {
		global $wp;

		$wp->query_vars = array( 'event_facets' => $facet_path );

		return get_query_var_facets();
	}

	/**
	 * Each value in a pretty URL, including a value with a dash of its own.
	 *
	 * @dataProvider data_format_values
	 *
	 * @covers \WordPressdotorg\Events_2023\get_query_var_facets
	 *
	 * @param string $facet_path
	 * @param array  $expected
	 */
	public function test_format_values( $facet_path, $expected ) {
		$this->assertSame( $expected, $this->facets_for( $facet_path )['format'] );
	}

	/**
	 * Pretty-URL format values and what they should read back as.
	 *
	 * @return array
	 */
	public function data_format_values() {
		return array(
			'in person alone'          => array( 'format/in-person/', array( 'in-person' ) ),
			'online alone'             => array( 'format/online/', array( 'online' ) ),
			'in person, then online'   => array( 'format/in-person-online/', array( 'in-person', 'online' ) ),
			'online, then in person'   => array( 'format/online-in-person/', array( 'online', 'in-person' ) ),
		);
	}

	/**
	 * Ticking both formats leaves the other filters as they were: no stray `in` or `person` values.
	 *
	 * @covers \WordPressdotorg\Events_2023\get_query_var_facets
	 */
	public function test_both_formats_leave_the_other_filters_alone() {
		$facets = $this->facets_for( 'type/wordcamp-other/format/in-person-online/month/02-03/country/US/' );

		$this->assertSame( array( 'wordcamp', 'other' ), $facets['type'] );
		$this->assertSame( array( 'in-person', 'online' ), $facets['format'] );
		$this->assertSame( array( '02', '03' ), $facets['month'] );
		$this->assertSame( array( 'US' ), $facets['country'] );
	}

	/**
	 * Only the event filters are read from the URL. Any other key, like a core query var, is dropped.
	 *
	 * @covers \WordPressdotorg\Events_2023\get_query_var_facets
	 */
	public function test_unknown_keys_are_dropped() {
		$facets = $this->facets_for( 'post_status/private-draft/format/online/post_type/page/perm/editable/p/1/' );

		$this->assertSame( array( 'format' ), array_keys( $facets ) );
		$this->assertSame( array( 'online' ), $facets['format'] );
	}

	/**
	 * Each filter is set on the main request under its own query var, and nothing else is.
	 *
	 * @covers \WordPressdotorg\Events_2023\set_query_vars_from_pretty_url
	 */
	public function test_only_filter_query_vars_are_set() {
		global $wp;

		$wp->query_vars = array( 'event_facets' => 'type/wordcamp/post_status/private-draft/format/online/month/05/country/US/' );

		set_query_vars_from_pretty_url( $wp );

		$this->assertSame(
			array(
				'event_facets' => 'type/wordcamp/post_status/private-draft/format/online/month/05/country/US/',
				'event_type'   => array( 'wordcamp' ),
				'format_type'  => array( 'online' ),
				'month'        => array( '05' ),
				'country'      => array( 'US' ),
			),
			$wp->query_vars
		);
	}
}
