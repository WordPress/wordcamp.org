<?php

namespace WordCamp\WCPT\Tests;

use WP_UnitTestCase;
use WordCamp_Admin;
use WordCamp_Loader;

defined( 'WPINC' ) || die();

/**
 * Tests for the status changes a Campus Connect application can make.
 *
 * @group wcpt
 * @group campus-connect
 */
class Test_Campus_Connect_Status_Transitions extends WP_UnitTestCase {
	/**
	 * Where a Campus Connect application in an old-workflow status can go.
	 *
	 * Every CC status except the three intake ones: moving an event that was already past intake back
	 * to Needs Vetting would queue it for the vetting agent again, Needs Action is the agent's hand-off,
	 * and Needs Orientation sends the organizer email.
	 */
	const FALLBACK = array(
		'wcpt-more-info-reque',
		'wcpt-approved-pre-pl',
		'wcpt-scheduled',
		'wcpt-closed',
		'wcpt-rejected',
		'wcpt-cancelled',
	);

	/**
	 * Forget the post the admin path reads from the request.
	 */
	public function tear_down() {
		unset( $_GET['post'] );

		parent::tear_down();
	}

	/**
	 * A Campus Connect application left in an old-workflow status can still be moved on.
	 *
	 * These are the global statuses that aren't Campus Connect ones. Applications from before the CC
	 * workflow are still in them (e.g. In Pre-Planning), and with no transitions their status couldn't be
	 * changed at all.
	 *
	 * @dataProvider data_old_workflow_statuses
	 *
	 * @covers WordCamp_Loader::get_campus_connect_status_transitions
	 *
	 * @param string $status
	 */
	public function test_old_workflow_status_can_move_on( $status ) {
		$this->assertSame( self::FALLBACK, WordCamp_Loader::get_campus_connect_status_transitions( $status ) );
	}

	/**
	 * The global statuses outside the Campus Connect workflow.
	 *
	 * @return array
	 */
	public function data_old_workflow_statuses() {
		$statuses = array(
			'wcpt-interview-sched',
			'wcpt-needs-email',
			'wcpt-needs-site',
			'wcpt-needs-pre-plann',
			'wcpt-pre-planning',
			'wcpt-needs-budget-re',
			'wcpt-budget-rev-sche',
			'wcpt-needs-contract',
			'wcpt-needs-fill-list',
			'wcpt-needs-schedule',
		);

		return array_combine( $statuses, array_map( fn( $status ) => array( $status ), $statuses ) );
	}

	/**
	 * The list above is exactly the global statuses that aren't Campus Connect ones, so a status added to
	 * either list later shows up here.
	 *
	 * @covers WordCamp_Loader::get_campus_connect_status_transitions
	 */
	public function test_old_workflow_statuses_are_the_non_campus_connect_ones() {
		$non_cc = array_diff(
			array_keys( WordCamp_Loader::get_post_statuses() ),
			array_keys( WordCamp_Loader::get_campus_connect_statuses() )
		);

		$this->assertEqualsCanonicalizing( array_keys( $this->data_old_workflow_statuses() ), array_values( $non_cc ) );
	}

	/**
	 * The fallback is only Campus Connect statuses, and none of the intake ones.
	 *
	 * @covers WordCamp_Loader::get_campus_connect_status_transitions
	 */
	public function test_fallback_skips_the_intake_statuses() {
		$transitions = WordCamp_Loader::get_campus_connect_status_transitions( 'wcpt-pre-planning' );

		$this->assertSame( array(), array_diff( $transitions, array_keys( WordCamp_Loader::get_campus_connect_statuses() ) ) );

		foreach ( array( 'wcpt-needs-vetting', 'wcpt-needs-action', 'wcpt-needs-orientati' ) as $intake ) {
			$this->assertNotContains( $intake, $transitions );
		}
	}

	/**
	 * A status nobody registered still gets no transitions, so the dropdown keeps it as the only choice
	 * instead of offering a move.
	 *
	 * @covers WordCamp_Loader::get_campus_connect_status_transitions
	 */
	public function test_unknown_status_still_gets_no_transitions() {
		$this->assertSame( array(), WordCamp_Loader::get_campus_connect_status_transitions( 'wcpt-not-a-status' ) );
	}

	/**
	 * The Campus Connect workflow's own map is unchanged.
	 *
	 * @covers WordCamp_Loader::get_campus_connect_status_transitions
	 */
	public function test_campus_connect_statuses_keep_their_map() {
		$this->assertSame(
			array( 'wcpt-needs-action', 'wcpt-more-info-reque', 'wcpt-approved-pre-pl', 'wcpt-rejected', 'wcpt-cancelled' ),
			WordCamp_Loader::get_campus_connect_status_transitions( 'wcpt-needs-vetting' )
		);

		$this->assertSame(
			array_keys( WordCamp_Loader::get_campus_connect_statuses() ),
			WordCamp_Loader::get_campus_connect_status_transitions( 'wcpt-more-info-reque' )
		);
	}

	/**
	 * On the edit screen of a Campus Connect application in In Pre-Planning (post 13878818's case), the
	 * status dropdown offers the fallback. A regular WordCamp in the same status keeps the global map.
	 *
	 * @covers WordCamp_Admin::get_valid_status_transitions
	 */
	public function test_edit_screen_offers_the_fallback_only_for_campus_connect() {
		$campus_connect = self::factory()->post->create( array(
			'post_type'   => WCPT_POST_TYPE_ID,
			'post_status' => 'wcpt-pre-planning',
		) );
		update_post_meta( $campus_connect, 'event_subtype', 'campusconnect' );

		$wordcamp = self::factory()->post->create( array(
			'post_type'   => WCPT_POST_TYPE_ID,
			'post_status' => 'wcpt-pre-planning',
		) );

		$_GET['post'] = (string) $campus_connect;
		$this->assertSame( self::FALLBACK, WordCamp_Admin::get_valid_status_transitions( 'wcpt-pre-planning' ) );

		$_GET['post'] = (string) $wordcamp;
		$this->assertSame(
			WordCamp_Loader::get_valid_status_transitions( 'wcpt-pre-planning' ),
			WordCamp_Admin::get_valid_status_transitions( 'wcpt-pre-planning' )
		);
	}
}
