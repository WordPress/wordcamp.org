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
use function WordCamp\Sponsor_Vetting\reactivate_replied_companies;
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
	 * A compliance request unanswered for 30 days, with no event still to come waiting on it, goes Inactive and
	 * cancels its pending sponsorships, as the system; one with an event to come, a recent one, one with a sponsor
	 * reply to read, or one with Good Faith changes owed stays.
	 *
	 * @dataProvider data_stalled_requests
	 *
	 * @param string[] $events   The events of its pending sponsorships: `ended`, `upcoming` or `canceled`.
	 * @param array    $meta     The company's meta.
	 * @param bool     $inactive Whether it goes Inactive.
	 * @param int      $asked    How many days ago it moved into Compliance Requested.
	 */
	public function test_stalled_compliance_requests_go_inactive( array $events, array $meta, bool $inactive, int $asked ): void {
		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );

		$company_id = $this->create_company( 'sv-compliance-req', $asked, $meta );

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
	 * Compliance requests, and whether the daily job moves them to Inactive. All but one went unanswered for 31 days.
	 *
	 * @return array[]
	 */
	public function data_stalled_requests(): array {
		return array(
			'no pending sponsorship'         => array( array(), array(), true, 31 ),
			'every event has ended'          => array( array( 'ended', 'ended' ), array(), true, 31 ),
			'the event to come was canceled' => array( array( 'ended', 'canceled' ), array(), true, 31 ),
			'one event is still to come'     => array( array( 'ended', 'upcoming' ), array(), false, 31 ),
			'asked 5 days ago'               => array( array( 'ended' ), array(), false, 5 ),
			'the sponsor replied'            => array( array( 'ended' ), array( '_sv_sponsor_replied' => time() ), false, 31 ),
			'approved in Good Faith, no sponsorship pending' => array( array(), array( '_sv_good_faith_due' => gmdate( 'Y-m-d', strtotime( '+5 days' ) ) ), false, 31 ),
		);
	}

	/**
	 * The daily job reads a company's compliance conversation before moving it: a reviewer's message restarts the
	 * sponsor's 30 days, and a reply the hourly check hasn't seen yet is flagged instead.
	 *
	 * Fakes FreeScout's API through `pre_http_request`: see fake_conversation().
	 *
	 * @dataProvider data_conversations
	 *
	 * @param array[] $threads The conversation's messages: who wrote each and when.
	 * @param string  $status  The company's status after the job.
	 * @param bool    $flagged Whether the company is flagged as replied to.
	 */
	public function test_the_conversation_is_read_before_a_company_goes_inactive( array $threads, string $status, bool $flagged ): void {
		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );

		$company_id = $this->create_company( 'sv-compliance-req', 31 );
		$this->add_conversation( $company_id, 31 );

		$fake = $this->fake_conversation( $threads );
		add_filter( 'pre_http_request', $fake, 10, 3 );
		deactivate_stalled_companies();
		remove_filter( 'pre_http_request', $fake, 10 );

		$actual = array( get_post_status( $company_id ), (bool) get_post_meta( $company_id, '_sv_sponsor_replied', true ) );

		restore_current_blog();

		$this->assertSame( array( $status, $flagged ), $actual );
	}

	/**
	 * Conversations for a request sent 31 days ago, and what the daily job does with its company.
	 *
	 * @return array[]
	 */
	public function data_conversations(): array {
		$request = array( 'message', '-31 days' );

		return array(
			'no one wrote since the request'  => array( array( $request ), 'sv-inactive', false ),
			'a reviewer wrote 5 days ago'     => array( array( $request, array( 'customer', '-20 days' ), array( 'message', '-5 days' ) ), 'sv-compliance-req', false ),
			'the sponsor replied an hour ago' => array( array( $request, array( 'customer', '-1 hour' ) ), 'sv-compliance-req', true ),
		);
	}

	/**
	 * A sponsor's reply to a compliance request that was dropped brings its company back to Compliance Requested,
	 * flagged for a reviewer, and reopens the sponsorship that going Inactive canceled, as the system, unless its
	 * event is over. A reply the reviewer had when dropping the request, or to a company that wasn't waiting on
	 * one, changes nothing.
	 *
	 * Fakes FreeScout's API through `pre_http_request`: see fake_conversation().
	 *
	 * @dataProvider data_late_replies
	 *
	 * @param string $from    The status the company went Inactive from.
	 * @param string $replied When the sponsor replied, as strtotime() reads it.
	 * @param bool   $returns Whether the company goes back to Compliance Requested.
	 * @param bool   $ended   Optional. Whether its sponsorship's event is over.
	 */
	public function test_a_sponsors_reply_brings_an_inactive_company_back( string $from, string $replied, bool $returns, bool $ended = false ): void {
		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );

		$company_id = $this->create_company( $from, 40 );
		$this->add_conversation( $company_id, 40 );

		$sponsorship_id = self::factory()->post->create(
			array(
				'post_type'   => SPONSORSHIP_POST_TYPE,
				'post_status' => 'svs-pending',
				'post_parent' => $company_id,
				'meta_input'  => array( '_sv_blog_id' => $ended ? self::$year_dot_2018_site_id : self::$year_dot_2019_site_id ),
			)
		);

		$reviewer_id = self::factory()->user->create();
		wp_set_current_user( $reviewer_id );
		wp_update_post(
			array(
				'ID'          => $company_id,
				'post_status' => 'sv-inactive',
			)
		);

		$fake = $this->fake_conversation( array( array( 'message', '-40 days' ), array( 'customer', $replied ) ) );
		add_filter( 'pre_http_request', $fake, 10, 3 );
		reactivate_replied_companies();
		remove_filter( 'pre_http_request', $fake, 10 );

		$changes = get_post_meta( $company_id, '_sv_status_change', false );
		$actual  = array(
			'company'     => get_post_status( $company_id ),
			'sponsorship' => get_post_status( $sponsorship_id ),
			'flagged'     => (bool) get_post_meta( $company_id, '_sv_sponsor_replied', true ),
			'moved_by'    => (int) end( $changes )['user_id'],
		);

		restore_current_blog();

		$this->assertSame(
			array(
				'company'     => $returns ? 'sv-compliance-req' : 'sv-inactive',
				'sponsorship' => $returns && ! $ended ? 'svs-pending' : 'svs-canceled',
				'flagged'     => $returns,
				'moved_by'    => $returns ? 0 : $reviewer_id,
			),
			$actual
		);
	}

	/**
	 * Inactive companies, when their sponsor replied, and whether that brings them back.
	 *
	 * @return array[]
	 */
	public function data_late_replies(): array {
		return array(
			'replied after it went Inactive'              => array( 'sv-compliance-req', '+1 hour', true ),
			'replied after its event was over'            => array( 'sv-compliance-req', '+1 hour', true, true ),
			'replied before it went Inactive'             => array( 'sv-compliance-req', '-1 day', false ),
			'went Inactive before changes were requested' => array( 'sv-needs-vetting', '+1 hour', false ),
		);
	}

	/**
	 * Creates a company that entered its status some days ago.
	 *
	 * @param string $status The company's status.
	 * @param int    $days   How many days ago.
	 * @param array  $meta   Optional. More meta.
	 * @return int The company.
	 */
	protected function create_company( string $status, int $days, array $meta = array() ): int {
		$company_id = self::factory()->post->create(
			array(
				'post_type'   => COMPANY_POST_TYPE,
				'post_status' => $status,
				'meta_input'  => $meta,
			)
		);

		delete_post_meta( $company_id, '_sv_status_change' );
		add_post_meta(
			$company_id,
			'_sv_status_change',
			array(
				'user_id' => 0,
				'time'    => strtotime( "-{$days} days" ),
				'from'    => 'sv-in-progress',
				'to'      => $status,
			)
		);

		return $company_id;
	}

	/**
	 * Records the compliance request a company was sent some days ago, as conversation 501.
	 *
	 * @param int $company_id The company.
	 * @param int $days       How many days ago.
	 */
	protected function add_conversation( int $company_id, int $days ): void {
		add_post_meta(
			$company_id,
			'_sv_conversation',
			array(
				'id'             => 501,
				'url'            => 'https://freescout.wordpress.net/conversation/501',
				'type'           => 'compliance',
				'sponsorship_id' => 0,
				'user_id'        => 0,
				'time'           => strtotime( "-{$days} days" ),
			)
		);
	}

	/**
	 * Fakes the FreeScout API call the daily job makes to read a compliance conversation
	 * (`GET /api/conversations/501`), so the tests make no external request. The response follows the API's shape.
	 *
	 * @param array[] $threads The conversation's messages: each a thread type (`message` for an agent, `customer`
	 *                         for the sponsor) and when it was written, as strtotime() reads it.
	 * @return \Closure A `pre_http_request` callback.
	 */
	protected function fake_conversation( array $threads ): \Closure {
		return static function ( mixed $response, array $args, string $url ) use ( $threads ): mixed {
			$api = 'https://freescout.wordpress.net/api/';

			if ( ! str_starts_with( $url, $api ) ) {
				return $response;
			}

			$found = 'GET conversations/501?embed=threads,tags' === $args['method'] . ' ' . substr( $url, strlen( $api ) );
			$body  = array( 'message' => 'Not found' );

			if ( $found ) {
				$body = array(
					'id'           => 501,
					'number'       => 6789,
					'type'         => 'email',
					'status'       => 'active',
					'state'        => 'published',
					'subject'      => 'Action Required: Compliance for WordPress Event Sponsorship',
					'mailboxId'    => 7,
					'threadsCount' => count( $threads ),
					'createdAt'    => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $threads[0][1] ) ),
					'_embedded'    => array(
						'threads' => array_map(
							static fn( int $index, array $thread ): array => array(
								'id'        => $index + 1,
								'type'      => $thread[0],
								'status'    => 'active',
								'state'     => 'published',
								'createdAt' => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $thread[1] ) ),
							),
							array_keys( $threads ),
							$threads
						),
						'tags'    => array(),
					),
				);
			}

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $body ),
				'response' => array(
					'code'    => $found ? 200 : 404,
					'message' => '',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
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
