<?php
/**
 * Tests for companies and sponsorships over core's REST endpoints, which the vetting screen reads.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting\Tests;

use WordCamp\Tests\Database_TestCase;
use function WordCamp\Sponsor_Vetting\register_company_meta;
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
	 * The company statuses, which a list has to name: the endpoint lists published posts otherwise.
	 */
	private const COMPANY_STATUSES = 'sv-needs-vetting,sv-in-progress,sv-compliance-req,sv-compliant,sv-not-eligible,sv-inactive';

	/**
	 * The sponsorship statuses.
	 */
	private const SPONSORSHIP_STATUSES = 'svs-pending,svs-approved,svs-not-approved,svs-canceled';

	/**
	 * Registers the post types and their meta on Central, the only site where they're in REST.
	 */
	public function set_up(): void {
		parent::set_up();

		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
		register_types();
		// Registered again, since an earlier test may have registered them from another site.
		register_post_types();
		register_company_meta();
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
	 * Only sponsor vetters read companies and sponsorships.
	 *
	 * @dataProvider data_readers
	 *
	 * @param string $subrole The user's subrole.
	 * @param string $route   The route, with `%d` for the company's ID.
	 * @param array  $params  The request's parameters.
	 * @param int    $status  The response's status.
	 */
	public function test_only_vetters_read_the_records( string $subrole, string $route, array $params, int $status ): void {
		$company_id = self::factory()->post->create(
			array(
				'post_type'   => COMPANY_POST_TYPE,
				'post_status' => 'sv-needs-vetting',
			)
		);

		$this->create_sponsorship( self::$year_dot_2018_site_id, 'svs-pending', $company_id );

		$this->assertSame( $status, $this->request( 'GET', sprintf( $route, $company_id ), $params, $subrole )->get_status() );
	}

	/**
	 * Who asks for what, and how the endpoints answer.
	 *
	 * @return array[]
	 */
	public function data_readers(): array {
		$companies    = array( 'status' => self::COMPANY_STATUSES );
		$sponsorships = array( 'status' => self::SPONSORSHIP_STATUSES );

		return array(
			'a vetter lists companies'      => array( 'sponsor_vetter', '/sv-companies', $companies, 200 ),
			'a vetter opens a company'      => array( 'sponsor_vetter', '/sv-companies/%d', array(), 200 ),
			'a vetter lists sponsorships'   => array( 'sponsor_vetter', '/sv-sponsorships', $sponsorships, 200 ),
			// A status the user may not list fails the parameter, hence 400.
			'a wrangler lists companies'    => array( 'wordcamp_wrangler', '/sv-companies', $companies, 400 ),
			'a wrangler opens a company'    => array( 'wordcamp_wrangler', '/sv-companies/%d', array(), 403 ),
			'a wrangler lists sponsorships' => array( 'wordcamp_wrangler', '/sv-sponsorships', $sponsorships, 400 ),
		);
	}

	/**
	 * A list that names its fields gets, for each company, its name, websites, status and flags, its last vetting,
	 * the soonest event still to come among its pending sponsorships, and how many sponsorships it has.
	 */
	public function test_list_describes_each_company(): void {
		$due    = gmdate( 'Y-m-d', strtotime( '-10 days' ) );
		$acme   = self::factory()->post->create(
			array(
				'post_type'   => COMPANY_POST_TYPE,
				'post_status' => 'sv-compliance-req',
				'post_title'  => 'Acme',
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

		$response = $this->request(
			'GET',
			'/sv-companies',
			array(
				'status'  => self::COMPANY_STATUSES,
				'orderby' => 'title',
				'order'   => 'asc',
				'_fields' => 'id,status,domains,flags,next_event,sponsorships,last_vetted',
			)
		);

		$this->assertSame(
			array(
				array(
					'id'           => $acme,
					'status'       => 'sv-compliance-req',
					'domains'      => array( 'acme.test' ),
					'flags'        => array(
						array(
							'type'    => 'good_faith',
							'text'    => 'Good Faith overdue for 10 days',
							'date'    => $due,
							'overdue' => true,
						),
					),
					'next_event'   => gmdate( 'Y-m-d', strtotime( '+10 days' ) ),
					'sponsorships' => 3,
					'last_vetted'  => '2026-05-06',
				),
				array(
					'id'           => $zenith,
					'status'       => 'sv-needs-vetting',
					'domains'      => array(),
					'flags'        => array(),
					'next_event'   => '',
					'sponsorships' => 0,
					'last_vetted'  => '',
				),
			),
			// Without the links, which the fields named leave in.
			array_map( static fn( array $company ): array => array_diff_key( $company, array( '_links' => true ) ), rest_get_server()->response_to_data( $response, false ) )
		);
	}

	/**
	 * A company opens with its contact and findings, its emails and its own notes; its sponsorships are listed by
	 * company, each with its event and its own notes.
	 */
	public function test_company_opens_with_its_details_and_sponsorships(): void {
		$company_id = self::factory()->post->create(
			array(
				'post_type'   => COMPANY_POST_TYPE,
				'post_status' => 'sv-in-progress',
				'meta_input'  => array(
					'_sv_contact_email' => 'dana@acme.test',
					'_sv_gpl_findings'  => '- All **good**.',
				),
			)
		);
		$events     = $this->create_events( $company_id );

		// Another company's sponsorship, which the list by company leaves out.
		$this->create_sponsorship( self::$year_dot_2018_site_id, 'svs-pending', self::factory()->post->create( array( 'post_type' => COMPANY_POST_TYPE ) ) );

		// Only the notes below, so the logs are certain.
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

		add_post_meta(
			$company_id,
			'_sv_conversation',
			array(
				'id'   => 7,
				'url'  => 'https://freescout.example.test/conversation/7',
				'type' => 'compliance',
				'time' => 1790000000,
			)
		);

		$company      = $this->request( 'GET', '/sv-companies/' . $company_id )->get_data();
		$sponsorships = $this->request(
			'GET',
			'/sv-sponsorships',
			array(
				'parent'  => array( $company_id ),
				'status'  => self::SPONSORSHIP_STATUSES,
				'orderby' => 'id',
				'order'   => 'asc',
				'_fields' => 'id,status,event,approved,log',
			)
		);
		$note         = '<p class="sv-log-head"><strong class="sv-log-system">System</strong> added a note</p><div class="sv-log-text">%s</div>';
		// An event's name ends with its year.
		$event = static fn( string $title, string $start ): array => array(
			'name' => $title . ' ' . gmdate( 'Y', strtotime( $start ) ),
			'date' => gmdate( 'Y-m-d', strtotime( $start ) ),
		);

		$this->assertSame(
			array(
				'contact'       => 'dana@acme.test',
				'findings'      => '- All **good**.',
				'conversations' => array(
					array(
						'type' => 'compliance',
						'url'  => 'https://freescout.example.test/conversation/7',
						'time' => gmdate( 'c', 1790000000 ),
					),
				),
				'log'           => array(
					array(
						'time' => gmdate( 'c', 1790000100 ),
						'html' => sprintf( $note, 'Checked the &lt;b&gt;site&lt;/b&gt;' ),
					),
				),
				'sponsorships'  => array(
					array(
						'id'       => $events['past'],
						'status'   => 'svs-canceled',
						'event'    => $event( 'Camp Past', '-10 days' ),
						'approved' => '',
						'log'      => array(),
					),
					array(
						'id'       => $events['later'],
						'status'   => 'svs-pending',
						'event'    => $event( 'Camp Later', '+20 days' ),
						'approved' => '',
						'log'      => array(),
					),
					array(
						'id'       => $events['soon'],
						'status'   => 'svs-pending',
						'event'    => $event( 'Camp Soon', '+10 days' ),
						'approved' => '',
						'log'      => array(
							array(
								'time' => gmdate( 'c', 1790000200 ),
								'html' => sprintf( $note, 'Asked the camp' ),
							),
						),
					),
				),
			),
			array(
				'contact'       => $company['meta']['_sv_contact_email'],
				'findings'      => $company['meta']['_sv_gpl_findings'],
				'conversations' => $company['conversations'],
				'log'           => $company['log'],
				'sponsorships'  => array_map( static fn( array $sponsorship ): array => array_diff_key( $sponsorship, array( '_links' => true ) ), rest_get_server()->response_to_data( $sponsorships, false ) ),
			)
		);
	}

	/**
	 * A change the workflow refuses is answered with an error and leaves the record as it was, and vetters can't
	 * write a company's meta yet.
	 *
	 * @dataProvider data_writes
	 *
	 * @param string $record The record written to: `company` or `sponsorship`.
	 * @param array  $params What's written.
	 * @param int    $status The response's status.
	 * @param string $stored The record's status afterwards.
	 */
	public function test_refused_changes_are_errors( string $record, array $params, int $status, string $stored ): void {
		$company_id     = self::factory()->post->create(
			array(
				'post_type'   => COMPANY_POST_TYPE,
				'post_status' => 'sv-in-progress',
			)
		);
		$sponsorship_id = $this->create_sponsorship( self::$year_dot_2018_site_id, 'svs-pending', $company_id );
		$routes         = array(
			'company'     => '/sv-companies/' . $company_id,
			'sponsorship' => '/sv-sponsorships/' . $sponsorship_id,
		);

		$response = $this->request( 'POST', $routes[ $record ], $params );

		$this->assertSame(
			array( $status, $stored, '' ),
			array(
				$response->get_status(),
				get_post_status( 'company' === $record ? $company_id : $sponsorship_id ),
				get_post_meta( $company_id, '_sv_gpl_findings', true ),
			)
		);
	}

	/**
	 * What a vetter writes, and what becomes of it.
	 *
	 * @return array[]
	 */
	public function data_writes(): array {
		return array(
			'a status the company can move to'         => array( 'company', array( 'status' => 'sv-compliance-req' ), 200, 'sv-compliance-req' ),
			'a status the company can’t move to'       => array( 'company', array( 'status' => 'sv-needs-vetting' ), 400, 'sv-in-progress' ),
			'approval while the company isn’t vetted'  => array( 'sponsorship', array( 'status' => 'svs-approved' ), 400, 'svs-pending' ),
			'findings, which aren’t writable yet'      => array( 'company', array( 'meta' => array( '_sv_gpl_findings' => 'All good.' ) ), 403, 'sv-in-progress' ),
		);
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
	 * Asks one of core's endpoints on Central, in the context the screen reads in, as a user with a subrole.
	 *
	 * @param string $method  The request's method.
	 * @param string $route   The route, after `wp/v2`.
	 * @param array  $params  Optional. The request's parameters.
	 * @param string $subrole Optional. The user's subrole.
	 * @return \WP_REST_Response
	 */
	private function request( string $method, string $route, array $params = array(), string $subrole = 'sponsor_vetter' ): \WP_REST_Response {
		$user_id                   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$GLOBALS['wcorg_subroles'] = array( $user_id => array( $subrole ) );
		wp_set_current_user( $user_id );

		$GLOBALS['wp_rest_server'] = new \WP_REST_Server();
		do_action( 'rest_api_init', $GLOBALS['wp_rest_server'] );

		$request = new \WP_REST_Request( $method, '/wp/v2' . $route );
		$request->set_param( 'context', 'edit' );

		foreach ( $params as $name => $value ) {
			$request->set_param( $name, $value );
		}

		return rest_do_request( $request );
	}
}
