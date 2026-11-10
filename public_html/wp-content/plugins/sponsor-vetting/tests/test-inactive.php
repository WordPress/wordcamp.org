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
use function WordCamp\Sponsor_Vetting\register_types;
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
	 * Registers the types, and gives the 2018 camp an event that ended last month and the 2019 camp one still to
	 * come.
	 */
	public function set_up(): void {
		parent::set_up();

		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
		register_types();

		foreach ( array(
			self::$year_dot_2018_site_id => array( '-1 month -1 day', '-1 month' ),
			self::$year_dot_2019_site_id => array( '+1 month', '+1 month +1 day' ),
		) as $site_id => list( $start, $end ) ) {
			self::factory()->post->create(
				array(
					'post_type'   => 'wordcamp',
					'post_status' => 'publish',
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
	 * @param string[] $events   The events of its pending sponsorships: `ended` or `upcoming`.
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
					'meta_input'  => array( '_sv_blog_id' => 'ended' === $event ? self::$year_dot_2018_site_id : self::$year_dot_2019_site_id ),
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
			'the sponsor replied'            => array( array( 'ended' ), array( '_sv_sponsor_replied' => time() ), false ),
			'approved in Good Faith, no sponsorship pending' => array( array(), array( '_sv_good_faith_due' => gmdate( 'Y-m-d', strtotime( '+5 days' ) ) ), false ),
		);
	}
}
