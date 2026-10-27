<?php

namespace WordCamp\WCPT\Tests;

use WP_Query;
use WP_UnitTestCase;
use WordCamp_Loader;

defined( 'WPINC' ) || die();

/**
 * Tests for Campus Connect events on Central's Planned list.
 *
 * A Campus Connect application goes from Approved For Pre-Planning straight to WordCamp
 * Scheduled, so it never enters the statuses the Planned list reads (#1714). Approved For
 * Pre-Planning is the same status as a regular camp's "Approved for Pre-Planning Pending
 * Agreement", which isn't planned yet, so it counts only for Campus Connect posts.
 *
 * @group wcpt
 * @group campus-connect
 */
class Test_Campus_Connect_Planned extends WP_UnitTestCase {
	/**
	 * The Planned list's query, without the sidebar's date and feed conditions.
	 *
	 * @param array $args
	 *
	 * @return int[]
	 */
	protected function planned_ids( $args = array() ) {
		$query = new WP_Query(
			array_merge(
				array(
					'post_type'                   => WCPT_POST_TYPE_ID,
					'post_status'                 => WordCamp_Loader::get_planned_post_statuses(),
					'wcpt_campus_connect_planned' => true,
					'posts_per_page'              => -1,
					'fields'                      => 'ids',
				),
				$args
			)
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Create a camp in a status, optionally a Campus Connect one.
	 *
	 * @param string $status
	 * @param bool   $campus_connect
	 * @param string $title
	 *
	 * @return int
	 */
	protected function create_camp( $status, $campus_connect, $title = '' ) {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => WCPT_POST_TYPE_ID,
				'post_status' => $status,
				'post_title'  => $title ?: "Camp in $status",
			)
		);

		if ( $campus_connect ) {
			update_post_meta( $post_id, 'event_subtype', 'campusconnect' );
		}

		return $post_id;
	}

	/**
	 * An approved Campus Connect event is planned.
	 *
	 * @covers WordCamp_Loader::get_planned_post_statuses
	 * @covers WordCamp_Loader::limit_campus_connect_planned
	 */
	public function test_approved_campus_connect_event_is_planned() {
		$cc = $this->create_camp( 'wcpt-approved-pre-pl', true );

		$this->assertContains( $cc, $this->planned_ids() );
	}

	/**
	 * A regular camp in the same status is still waiting on its agreement, so it isn't.
	 *
	 * @covers WordCamp_Loader::limit_campus_connect_planned
	 */
	public function test_regular_camp_pending_agreement_is_not_planned() {
		$regular = $this->create_camp( 'wcpt-approved-pre-pl', false );

		$this->assertNotContains( $regular, $this->planned_ids() );
	}

	/**
	 * The regular pre-planning statuses are unchanged, for both kinds of camp.
	 *
	 * @covers WordCamp_Loader::get_planned_post_statuses
	 * @covers WordCamp_Loader::limit_campus_connect_planned
	 */
	public function test_pre_planning_statuses_are_still_planned() {
		$regular = $this->create_camp( 'wcpt-pre-planning', false );
		$budget  = $this->create_camp( 'wcpt-needs-budget-re', false );
		$old_cc  = $this->create_camp( 'wcpt-pre-planning', true );

		$ids = $this->planned_ids();

		$this->assertContains( $regular, $ids );
		$this->assertContains( $budget, $ids );
		$this->assertContains( $old_cc, $ids );
	}

	/**
	 * Earlier and later Campus Connect statuses aren't planned.
	 *
	 * @covers WordCamp_Loader::get_planned_post_statuses
	 */
	public function test_other_campus_connect_statuses_are_not_planned() {
		$orientation = $this->create_camp( 'wcpt-needs-orientati', true );
		$on_hold     = $this->create_camp( 'wcpt-more-info-reque', true );
		$scheduled   = $this->create_camp( 'wcpt-scheduled', true );

		$ids = $this->planned_ids();

		$this->assertNotContains( $orientation, $ids );
		$this->assertNotContains( $on_hold, $ids );
		$this->assertNotContains( $scheduled, $ids );
	}

	/**
	 * Without the query var, a query for the status gets every camp in it, as before.
	 *
	 * The wp-admin list and anything else that names the status keep seeing regular camps.
	 *
	 * @covers WordCamp_Loader::limit_campus_connect_planned
	 */
	public function test_query_without_the_var_is_unchanged() {
		$regular = $this->create_camp( 'wcpt-approved-pre-pl', false );
		$cc      = $this->create_camp( 'wcpt-approved-pre-pl', true );

		$ids = $this->planned_ids( array( 'wcpt_campus_connect_planned' => false ) );

		$this->assertContains( $regular, $ids );
		$this->assertContains( $cc, $ids );
	}

	/**
	 * The list's other conditions still apply alongside it.
	 *
	 * @covers WordCamp_Loader::limit_campus_connect_planned
	 */
	public function test_combines_with_meta_conditions() {
		$hidden = $this->create_camp( 'wcpt-approved-pre-pl', true );
		$shown  = $this->create_camp( 'wcpt-approved-pre-pl', true );

		update_post_meta( $hidden, 'Hide from Event Feeds', 'on' );

		$ids = $this->planned_ids(
			array(
				'meta_query' => array(
					array(
						'key'     => 'Hide from Event Feeds',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$this->assertContains( $shown, $ids );
		$this->assertNotContains( $hidden, $ids );
	}

	/**
	 * The Planned sidebar on /schedule/ lists the approved Campus Connect event.
	 *
	 * Rendered from the theme's template, with and without a start date, since it runs one
	 * query for each.
	 *
	 * @covers WordCamp_Loader::limit_campus_connect_planned
	 */
	public function test_planned_sidebar_lists_approved_campus_connect_events() {
		$dated   = $this->create_camp( 'wcpt-approved-pre-pl', true, 'WordPress Campus Connect Dated' );
		$undated = $this->create_camp( 'wcpt-approved-pre-pl', true, 'WordPress Campus Connect Undated' );
		$pending = $this->create_camp( 'wcpt-approved-pre-pl', false, 'WordCamp Pending Agreement' );

		update_post_meta( $dated, 'Start Date (YYYY-mm-dd)', strtotime( '+3 months' ) );
		update_post_meta( $undated, 'Start Date (YYYY-mm-dd)', 0 );
		update_post_meta( $pending, 'Start Date (YYYY-mm-dd)', strtotime( '+3 months' ) );

		ob_start();
		require dirname( __DIR__, 4 ) . '/themes/wordcamp-central-2012/sidebar-schedule.php';
		$html = ob_get_clean();

		$this->assertStringContainsString( 'WordPress Campus Connect Dated', $html );
		$this->assertStringContainsString( 'WordPress Campus Connect Undated', $html );
		$this->assertStringNotContainsString( 'WordCamp Pending Agreement', $html );
	}
}
