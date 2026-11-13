<?php
/**
 * Tests for the REST routes the vetting screen reads.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting\Tests;

use WordCamp\Tests\Database_TestCase;
use function WordCamp\Sponsor_Vetting\register_post_types;
use function WordCamp\Sponsor_Vetting\register_types;
use function WordCamp\Sponsor_Vetting\set_company_domains;
use const WordCamp\Sponsor_Vetting\COMPANY_POST_TYPE;
use const WordCamp\Sponsor_Vetting\SPONSORSHIP_POST_TYPE;

defined( 'WPINC' ) || die();

/**
 * Extends `Database_TestCase` for its camp sites, which give the events their names.
 *
 * @group sponsor-vetting
 */
class Test_Rest extends Database_TestCase {
	/**
	 * Registers the post types on Central, where the routes are.
	 */
	public function set_up(): void {
		parent::set_up();

		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
		register_types();
		// Only on Central do the post types have screens to link to.
		register_post_types();
	}

	/**
	 * Clears the subroles and the REST server a request set up.
	 */
	public function tear_down(): void {
		$GLOBALS['wcorg_subroles'] = array();
		$GLOBALS['wp_rest_server'] = null;

		restore_current_blog();

		parent::tear_down();
	}

	/**
	 * Only sponsor vetters read the routes, and a company's route only opens companies.
	 *
	 * @dataProvider data_requests
	 *
	 * @param string $subrole The user's subrole.
	 * @param string $path    The route, with `%d` for the record's ID.
	 * @param string $record  The record the route names: `company` or `sponsorship`.
	 * @param int    $status  The response's status.
	 */
	public function test_only_vetters_read_companies( string $subrole, string $path, string $record, int $status ): void {
		$ids                = array();
		$ids['company']     = self::factory()->post->create(
			array(
				'post_type'   => COMPANY_POST_TYPE,
				'post_status' => 'sv-needs-vetting',
			)
		);
		$ids['sponsorship'] = $this->create_sponsorship( self::$year_dot_2018_site_id, 'svs-pending', $ids['company'] );

		$this->assertSame( $status, $this->request( sprintf( $path, $ids[ $record ] ), $subrole )->get_status() );
	}

	/**
	 * Who asks for what, and how the routes answer.
	 *
	 * @return array[]
	 */
	public function data_requests(): array {
		return array(
			'a vetter lists the companies'             => array( 'sponsor_vetter', '/companies', 'company', 200 ),
			'a vetter opens a company'                 => array( 'sponsor_vetter', '/companies/%d', 'company', 200 ),
			'a vetter opens a sponsorship as a company' => array( 'sponsor_vetter', '/companies/%d', 'sponsorship', 404 ),
			'a wrangler lists the companies'           => array( 'wordcamp_wrangler', '/companies', 'company', 403 ),
			'a wrangler opens a company'               => array( 'wordcamp_wrangler', '/companies/%d', 'company', 403 ),
		);
	}

	/**
	 * The list describes every company by name: its websites, status, flags and last vetting, the soonest event
	 * still to come among its pending sponsorships, and how many sponsorships it has.
	 */
	public function test_list_describes_each_company(): void {
		$due    = gmdate( 'Y-m-d', strtotime( '-10 days' ) );
		$acme   = self::factory()->post->create(
			array(
				'post_type'   => COMPANY_POST_TYPE,
				'post_status' => 'sv-compliance-req',
				'post_title'  => 'Acme & Sons',
				'meta_input'  => array(
					'_sv_good_faith_due' => $due,
					'_sv_last_vetted'    => strtotime( '2026-05-06 12:00:00 UTC' ),
				),
			)
		);
		$zenith = self::factory()->post->create(
			array(
				'post_type'   => COMPANY_POST_TYPE,
				'post_status' => 'sv-needs-vetting',
				'post_title'  => 'Zenith',
			)
		);

		set_company_domains( $acme, array( 'https://www.acme.test/hosting/' ) );
		$this->create_events( $acme );

		$this->assertSame(
			array(
				array(
					'id'           => $acme,
					'name'         => 'Acme & Sons',
					'domains'      => array( 'acme.test' ),
					'status'       => 'sv-compliance-req',
					'flags'        => array(
						array(
							'type'    => 'good_faith',
							'text'    => 'Good Faith overdue for 10 days',
							'date'    => $due,
							'overdue' => true,
						),
					),
					'next_event'   => gmdate( 'Y-m-d', strtotime( '+10 days' ) ),
					'last_vetted'  => '2026-05-06',
					'sponsorships' => 3,
				),
				array(
					'id'           => $zenith,
					'name'         => 'Zenith',
					'domains'      => array(),
					'status'       => 'sv-needs-vetting',
					'flags'        => array(),
					'next_event'   => '',
					'last_vetted'  => '',
					'sponsorships' => 0,
				),
			),
			$this->request( '/companies' )->get_data()
		);
	}

	/**
	 * A company opens with its contact and findings, its events with the next one first and past ones last, its
	 * compliance requests, and the notes of the company and its sponsorships as one timeline, newest first.
	 */
	public function test_company_opens_with_its_events_and_one_timeline(): void {
		$company_id = self::factory()->post->create(
			array(
				'post_type'   => COMPANY_POST_TYPE,
				'post_status' => 'sv-in-progress',
				'meta_input'  => array(
					'_sv_contact_first_name' => 'Dana',
					'_sv_contact_last_name'  => 'Whitfield',
					'_sv_contact_email'      => 'dana@acme.test',
					'_sv_gpl_findings'       => '- All **good**.',
				),
			)
		);
		$events     = $this->create_events( $company_id );

		// Only the entries below, at times of their own, so their order is certain.
		foreach ( array_merge( array( $company_id ), $events ) as $post_id ) {
			delete_post_meta( $post_id, '_sv_status_change' );
		}

		$notes = array(
			array( $company_id, 1790000100, 'Checked the <b>site</b>' ),
			array( $events['soon'], 1790000200, 'Asked the camp' ),
		);

		foreach ( $notes as list( $post_id, $time, $text ) ) {
			add_post_meta(
				$post_id,
				'_sv_note',
				array(
					'user_id' => 0,
					'time'    => $time,
					'text'    => $text,
				)
			);
		}

		foreach ( array( 'approval', 'compliance' ) as $type ) {
			add_post_meta(
				$company_id,
				'_sv_conversation',
				array(
					'id'   => 7,
					'url'  => 'https://freescout.example.test/conversation/' . $type,
					'type' => $type,
					'time' => 1790000000,
				)
			);
		}

		$data = $this->request( '/companies/' . $company_id )->get_data();
		// An event's name ends with its year.
		$name  = static fn( string $title, string $start ): string => $title . ' ' . gmdate( 'Y', strtotime( $start ) );
		$event = static fn( int $id, string $title, string $start, string $status ): array => array(
			'id'       => $id,
			'name'     => $name( $title, $start ),
			'date'     => gmdate( 'Y-m-d', strtotime( $start ) ),
			'status'   => $status,
			'approved' => '',
			'edit_url' => get_edit_post_link( $id, 'raw' ),
		);
		$note  = '<p class="sv-log-head"><strong class="sv-log-system">System</strong> added a note</p><div class="sv-log-text">%s</div>';

		$expected = array(
			'contact'       => array(
				'first_name' => 'Dana',
				'last_name'  => 'Whitfield',
				'email'      => 'dana@acme.test',
			),
			'gpl_findings'  => '- All **good**.',
			'events'        => array(
				$event( $events['soon'], 'Camp Soon', '+10 days', 'svs-pending' ),
				$event( $events['later'], 'Camp Later', '+20 days', 'svs-pending' ),
				$event( $events['past'], 'Camp Past', '-10 days', 'svs-canceled' ),
			),
			'conversations' => array(
				array(
					'url'  => 'https://freescout.example.test/conversation/compliance',
					'time' => gmdate( 'c', 1790000000 ),
				),
			),
			'log'           => array(
				array(
					'time'  => gmdate( 'c', 1790000200 ),
					'event' => $name( 'Camp Soon', '+10 days' ),
					'html'  => sprintf( $note, 'Asked the camp' ),
				),
				array(
					'time'  => gmdate( 'c', 1790000100 ),
					'event' => '',
					'html'  => sprintf( $note, 'Checked the &lt;b&gt;site&lt;/b&gt;' ),
				),
			),
		);

		$this->assertSame( $expected, array_intersect_key( $data, $expected ) );
	}

	/**
	 * Gives a company a sponsorship for each of three events: a past one, canceled, and two still to come, pending.
	 *
	 * @param int $company_id The company.
	 * @return int[] The sponsorships, keyed by `past`, `later` and `soon`.
	 */
	private function create_events( int $company_id ): array {
		$events = array(
			'past'  => array( self::$year_dot_2018_site_id, '-10 days', 'svs-canceled' ),
			'later' => array( self::$year_dot_2019_site_id, '+20 days', 'svs-pending' ),
			'soon'  => array( self::$slash_year_2016_site_id, '+10 days', 'svs-pending' ),
		);
		$ids    = array();

		foreach ( $events as $name => list( $blog_id, $start, $status ) ) {
			self::factory()->post->create(
				array(
					'post_type'  => 'wordcamp',
					'post_title' => 'Camp ' . ucfirst( $name ),
					'meta_input' => array(
						'_site_id'                => $blog_id,
						'Start Date (YYYY-mm-dd)' => strtotime( $start ),
					),
				)
			);

			$ids[ $name ] = $this->create_sponsorship( $blog_id, $status, $company_id );
		}

		return $ids;
	}

	/**
	 * Creates a sponsorship for a camp site.
	 *
	 * @param int    $blog_id    The camp site.
	 * @param string $status     Its status.
	 * @param int    $company_id Its company.
	 * @return int
	 */
	private function create_sponsorship( int $blog_id, string $status, int $company_id ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => SPONSORSHIP_POST_TYPE,
				'post_status' => $status,
				'post_parent' => $company_id,
				'meta_input'  => array( '_sv_blog_id' => $blog_id ),
			)
		);
	}

	/**
	 * Asks a route on Central, as a user with a subrole.
	 *
	 * @param string $path    The route, after the namespace.
	 * @param string $subrole Optional. The user's subrole.
	 * @return \WP_REST_Response
	 */
	private function request( string $path, string $subrole = 'sponsor_vetter' ): \WP_REST_Response {
		$user_id                   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$GLOBALS['wcorg_subroles'] = array( $user_id => array( $subrole ) );
		wp_set_current_user( $user_id );

		$GLOBALS['wp_rest_server'] = new \WP_REST_Server();
		do_action( 'rest_api_init', $GLOBALS['wp_rest_server'] );

		return rest_do_request( new \WP_REST_Request( 'GET', '/sponsor-vetting/v1' . $path ) );
	}
}
