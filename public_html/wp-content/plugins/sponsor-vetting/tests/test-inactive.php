<?php
/**
 * Tests for the Inactive status and the daily job that moves stalled compliance requests to it.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting\Tests;

use WordCamp\Tests\Database_TestCase;
use function WordCamp\Sponsor_Vetting\deactivate_stalled_companies;
use function WordCamp\Sponsor_Vetting\get_log;
use function WordCamp\Sponsor_Vetting\get_organizer_statuses;
use function WordCamp\Sponsor_Vetting\register_types;
use function WordCamp\Sponsor_Vetting\request_vetting;
use const WordCamp\Sponsor_Vetting\COMPANY_POST_TYPE;
use const WordCamp\Sponsor_Vetting\SPONSORSHIP_POST_TYPE;

defined( 'WPINC' ) || die();

/**
 * Extends `Database_TestCase` for its Central and camp sites.
 *
 * @group sponsor-vetting
 */
class Test_Inactive extends Database_TestCase {
	/**
	 * Registers the types, and gives the 2018 camp an event that ended last month, the 2019 camp one still to come,
	 * and the 2020 camp one still to come that was canceled.
	 */
	public function set_up(): void {
		parent::set_up();

		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
		register_types();

		foreach ( array(
			self::$year_dot_2018_site_id   => array( '-1 month -1 day', '-1 month', 'wcpt-closed' ),
			self::$year_dot_2019_site_id   => array( '+1 month', '+1 month +1 day', 'wcpt-scheduled' ),
			self::$slash_year_2020_site_id => array( '+2 months', '+2 months +1 day', 'wcpt-cancelled' ),
		) as $site_id => list( $start, $end, $status ) ) {
			self::factory()->post->create(
				array(
					'post_type'   => 'wordcamp',
					'post_status' => $status,
					'meta_input'  => array(
						'_site_id'                => $site_id,
						'Start Date (YYYY-mm-dd)' => strtotime( $start ),
						'End Date (YYYY-mm-dd)'   => strtotime( $end ),
					),
				)
			);
		}

		restore_current_blog();
	}

	/**
	 * A compliance request with nothing left waiting on it goes Inactive and cancels its pending sponsorships, as the
	 * system; one with an event to come, a sponsor reply to read, or Good Faith changes owed stays.
	 *
	 * @dataProvider data_stalled_requests
	 *
	 * @param string[] $events   The events of its pending sponsorships: `ended`, `upcoming` or `canceled`.
	 * @param array    $meta     The company's meta.
	 * @param bool     $inactive Whether it goes Inactive.
	 */
	public function test_stalled_compliance_requests_go_inactive( array $events, array $meta, bool $inactive ): void {
		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );

		$company_id = self::factory()->post->create(
			array(
				'post_type'   => COMPANY_POST_TYPE,
				'post_status' => 'sv-compliance-req',
				'meta_input'  => $meta,
			)
		);

		$sponsorship_ids = array();
		foreach ( $events as $event ) {
			$sponsorship_ids[] = self::factory()->post->create(
				array(
					'post_type'   => SPONSORSHIP_POST_TYPE,
					'post_status' => 'svs-pending',
					'post_parent' => $company_id,
					'meta_input'  => array(
						'_sv_blog_id' => array(
							'ended'    => self::$year_dot_2018_site_id,
							'upcoming' => self::$year_dot_2019_site_id,
							'canceled' => self::$slash_year_2020_site_id,
						)[ $event ],
					),
				)
			);
		}

		wp_set_current_user( self::factory()->user->create() );
		deactivate_stalled_companies();

		$status       = get_post_status( $company_id );
		$sponsorships = array_map( 'get_post_status', $sponsorship_ids );
		$authors      = array_unique( wp_list_pluck( get_log( $company_id ), 'user_id' ) );

		restore_current_blog();

		$this->assertSame( $inactive ? 'sv-inactive' : 'sv-compliance-req', $status );
		$this->assertSame( array_fill( 0, count( $events ), $inactive ? 'svs-canceled' : 'svs-pending' ), $sponsorships );

		if ( $inactive ) {
			$this->assertSame( array( 0 ), array_values( $authors ) );
		}
	}

	/**
	 * Compliance requests, and whether the daily job moves them to Inactive.
	 *
	 * @return array[]
	 */
	public function data_stalled_requests(): array {
		return array(
			'no pending sponsorship'         => array( array(), array(), true ),
			'every event has ended'          => array( array( 'ended', 'ended' ), array(), true ),
			'one event is still to come'     => array( array( 'ended', 'upcoming' ), array(), false ),
			'the event to come was canceled' => array( array( 'ended', 'canceled' ), array(), true ),
			'the sponsor replied'            => array( array( 'ended' ), array( '_sv_sponsor_replied' => time() ), false ),
			'approved in Good Faith, no sponsorship pending' => array( array(), array( '_sv_good_faith_due' => gmdate( 'Y-m-d', strtotime( '+5 days' ) ) ), false ),
		);
	}

	/**
	 * A reviewer making a company Inactive stops the vetting its camps asked for, which organizers can ask for
	 * again: that reopens the sponsorship and puts the company back in the queue.
	 */
	public function test_requesting_vetting_again_reopens_an_inactive_companys_sponsorship(): void {
		switch_to_blog( self::$year_dot_2019_site_id );
		$sponsor_id = self::factory()->post->create(
			array(
				'post_type'   => 'wcb_sponsor',
				'post_status' => 'publish',
				'post_title'  => 'Acme',
				'meta_input'  => array( '_wcpt_sponsor_website' => 'https://acme.example' ),
			)
		);
		restore_current_blog();

		$sponsorship_id = request_vetting( self::$year_dot_2019_site_id, $sponsor_id );

		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
		$company_id = (int) get_post_field( 'post_parent', $sponsorship_id );
		wp_update_post(
			array(
				'ID'          => $company_id,
				'post_status' => 'sv-inactive',
			)
		);
		$canceled = get_post_status( $sponsorship_id );
		restore_current_blog();

		switch_to_blog( self::$year_dot_2019_site_id );
		$organizer = get_organizer_statuses( array( $sponsor_id ) )[ $sponsor_id ];
		restore_current_blog();

		request_vetting( self::$year_dot_2019_site_id, $sponsor_id );

		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
		$reopened = get_post_status( $sponsorship_id );
		$company  = get_post_status( $company_id );
		restore_current_blog();

		$this->assertSame( 'svs-canceled', $canceled );
		$this->assertSame(
			array(
				'label'     => 'Vetting stopped',
				'requested' => false,
			),
			$organizer
		);
		$this->assertSame( 'svs-pending', $reopened );
		$this->assertSame( 'sv-needs-vetting', $company );
	}
}
