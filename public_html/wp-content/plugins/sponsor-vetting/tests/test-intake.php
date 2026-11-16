<?php
/**
 * Tests for intake: requesting vetting from camp sites, invoices and spreadsheets, and what organizers see after.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting\Tests;

use WordCamp\Tests\Database_TestCase;
use function WordCamp\Sponsor_Vetting\add_notice;
use function WordCamp\Sponsor_Vetting\find_companies_by_domain;
use function WordCamp\Sponsor_Vetting\get_company_domains;
use function WordCamp\Sponsor_Vetting\get_contact_info_messages;
use function WordCamp\Sponsor_Vetting\get_contact_info_problem;
use function WordCamp\Sponsor_Vetting\get_log;
use function WordCamp\Sponsor_Vetting\get_organizer_statuses;
use function WordCamp\Sponsor_Vetting\import_row;
use function WordCamp\Sponsor_Vetting\normalize_domain;
use function WordCamp\Sponsor_Vetting\parse_csv;
use function WordCamp\Sponsor_Vetting\render_vetting_reminder;
use function WordCamp\Sponsor_Vetting\request_vetting;
use function WordCamp\Sponsor_Vetting\set_company_domains;
use function WordCamp\Sponsor_Vetting\take_notices;
use const WordCamp\Sponsor_Vetting\COMPANY_POST_TYPE;
use const WordCamp\Sponsor_Vetting\SPONSORSHIP_POST_TYPE;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/trait-submits-invoices.php';

/**
 * Extends `Database_TestCase` for its Central and camp sites.
 *
 * @group sponsor-vetting
 */
class Test_Intake extends Database_TestCase {
	use Submits_Invoices;

	/**
	 * Creates a sponsor on the camp site.
	 *
	 * @param string $website Its website.
	 * @param array  $meta    Other meta.
	 * @return int The sponsor's ID.
	 */
	protected function create_camp_sponsor( string $website, array $meta = array() ): int {
		switch_to_blog( self::$year_dot_2018_site_id );

		$sponsor_id = self::factory()->post->create(
			array(
				'post_type'   => 'wcb_sponsor',
				'post_status' => 'publish',
				'post_title'  => 'Acme',
				'meta_input'  => $meta + array(
					'_wcpt_sponsor_company_name'  => 'Acme Inc.',
					'_wcpt_sponsor_website'       => $website,
					'_wcpt_sponsor_email_address' => 'pat@acme.example',
				),
			)
		);

		restore_current_blog();

		return $sponsor_id;
	}

	/**
	 * Creates a company on Central using `acme.example`, linked to a Multi-Event Sponsor.
	 *
	 * @param string   $status      Its status.
	 * @param int|null $last_vetted When it was last vetted, if not when it got its status.
	 * @return int[] The company's and the `mes` post's IDs.
	 */
	protected function create_mes_company( string $status, ?int $last_vetted = null ): array {
		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );

		$company_id = self::factory()->post->create(
			array(
				'post_type'   => COMPANY_POST_TYPE,
				'post_status' => $status,
				'post_title'  => 'Acme',
			)
		);
		$mes_id     = self::factory()->post->create( array( 'post_type' => 'mes' ) );

		set_company_domains( $company_id, array( 'acme.example' ) );
		update_post_meta( $mes_id, 'mes_website', 'https://www.acme.example/' );
		update_post_meta( $mes_id, '_sv_company_id', $company_id );

		if ( null !== $last_vetted ) {
			update_post_meta( $company_id, '_sv_last_vetted', $last_vetted );
		}

		restore_current_blog();

		return array( $company_id, $mes_id );
	}

	/**
	 * Runs a callback on a site.
	 *
	 * @param int      $blog_id The site.
	 * @param callable $run     The callback.
	 * @return mixed What it returns.
	 */
	protected function on_site( int $blog_id, callable $run ): mixed {
		switch_to_blog( $blog_id );
		$value = $run();
		restore_current_blog();

		return $value;
	}

	/**
	 * Requesting vetting twice, as an organizer and then an invoice would, gives one sponsorship, which the camp's
	 * sponsor points at; a website no company uses gets a new company to vet.
	 */
	public function test_request_vetting_is_idempotent_per_camp_sponsor(): void {
		$sponsor_id = $this->create_camp_sponsor( 'https://www.acme.example/' );

		$first  = request_vetting( self::$year_dot_2018_site_id, $sponsor_id );
		$second = request_vetting( self::$year_dot_2018_site_id, $sponsor_id );

		$this->assertIsInt( $first );
		$this->assertSame( $first, $second );
		$this->assertSame( (string) $first, $this->on_site( self::$year_dot_2018_site_id, static fn(): string => get_post_meta( $sponsor_id, '_sv_sponsorship_id', true ) ) );

		$central = $this->on_site(
			WORDCAMP_ROOT_BLOG_ID,
			static function () use ( $first ): array {
				$company_id = (int) get_post_field( 'post_parent', $first );

				return array(
					'sponsorships' => get_posts(
						array(
							'post_type'   => SPONSORSHIP_POST_TYPE,
							'post_status' => 'svs-pending',
							'fields'      => 'ids',
						)
					),
					'domains'      => get_company_domains( $company_id ),
					'status'       => get_post_status( $company_id ),
				);
			}
		);

		$this->assertSame( array( $first ), $central['sponsorships'] );
		$this->assertSame( array( 'acme.example' ), $central['domains'] );
		$this->assertSame( 'sv-needs-vetting', $central['status'] );
	}

	/**
	 * A Multi-Event Sponsor's copy links to its company even after organizers edit it, as long as its website is
	 * still the company's; a copy pointed at another site, or a website a company already uses, is only suggested,
	 * for a reviewer to confirm; one without a website waits for a reviewer.
	 *
	 * @dataProvider data_company_resolution
	 *
	 * @param string $website  The camp sponsor's website.
	 * @param bool   $is_mes   Whether it's a Multi-Event Sponsor's copy.
	 * @param string $expected `linked` to the existing company, `suggested`, `new` company, or `none`.
	 */
	public function test_request_vetting_resolves_the_company( string $website, bool $is_mes, string $expected ): void {
		list( $existing_id, $mes_id ) = $this->create_mes_company( 'sv-needs-vetting' );

		$sponsor_id     = $this->create_camp_sponsor( $website, $is_mes ? array( '_mes_id' => $mes_id ) : array() );
		$sponsorship_id = request_vetting( self::$year_dot_2018_site_id, $sponsor_id );

		$result = $this->on_site(
			WORDCAMP_ROOT_BLOG_ID,
			static fn(): array => array(
				'parent'    => (int) get_post_field( 'post_parent', $sponsorship_id ),
				'suggested' => (int) get_post_meta( $sponsorship_id, '_sv_suggested_company', true ),
				'new'       => find_companies_by_domain( normalize_domain( $website ) ),
			)
		);

		$expectations = array(
			'linked'    => array( $existing_id, 0 ),
			'suggested' => array( 0, $existing_id ),
			'none'      => array( 0, 0 ),
		);

		if ( 'new' === $expected ) {
			$this->assertSame( array( $result['parent'] ), $result['new'] );
			$this->assertNotSame( $existing_id, $result['parent'] );
		} else {
			$this->assertSame( $expectations[ $expected ], array( $result['parent'], $result['suggested'] ) );
		}
	}

	/**
	 * Camp sponsors, and how they find their company.
	 *
	 * @return array[]
	 */
	public function data_company_resolution(): array {
		return array(
			'multi-event copy, details edited' => array( 'https://www.acme.example/sponsors', true, 'linked' ),
			'multi-event copy, other website'  => array( 'https://edited.example', true, 'suggested' ),
			'website a company uses'           => array( 'https://www.acme.example/', false, 'suggested' ),
			'website no company uses'          => array( 'https://new.example', false, 'new' ),
			'no website'                       => array( '', false, 'none' ),
		);
	}

	/**
	 * A sponsor of a vetted company is approved as soon as vetting is requested, as the system: a website only that
	 * company uses links to it, as a Multi-Event Sponsor's copy does. A website two companies use, or a company still
	 * being vetted, waits for a reviewer.
	 *
	 * @dataProvider data_auto_approvals
	 *
	 * @param string $status   The company's status.
	 * @param bool   $is_mes   Whether the sponsor is a Multi-Event Sponsor's copy.
	 * @param bool   $shared   Whether another company uses the website too.
	 * @param bool   $approved Whether the sponsorship is approved.
	 */
	public function test_request_vetting_approves_a_vetted_company( string $status, bool $is_mes, bool $shared, bool $approved ): void {
		list( $company_id, $mes_id ) = $this->create_mes_company( $status );

		if ( $shared ) {
			$this->on_site( WORDCAMP_ROOT_BLOG_ID, static fn() => set_company_domains( self::factory()->post->create( array( 'post_type' => COMPANY_POST_TYPE ) ), array( 'acme.example' ) ) );
		}

		$sponsor_id = $this->create_camp_sponsor( 'https://acme.example', $is_mes ? array( '_mes_id' => $mes_id ) : array() );

		wp_set_current_user( self::factory()->user->create() );
		$sponsorship_id = request_vetting( self::$year_dot_2018_site_id, $sponsor_id );

		$result = $this->on_site(
			WORDCAMP_ROOT_BLOG_ID,
			static fn(): array => array(
				'status'      => get_post_status( $sponsorship_id ),
				'approved_by' => get_post_meta( $sponsorship_id, '_sv_approved_by', true ),
				'parent'      => (int) get_post_field( 'post_parent', $sponsorship_id ),
				// What the approval logged, leaving out the organizer's request and the link it made.
				'logged_by'   => array_values(
					array_column(
						array_filter(
							get_log( $sponsorship_id ),
							static fn( array $entry ): bool => 'new' !== ( $entry['from'] ?? '' ) && ! str_starts_with( $entry['text'] ?? '', 'Linked to' )
						),
						'user_id'
					)
				),
			)
		);

		$this->assertSame( $approved ? 'svs-approved' : 'svs-pending', $result['status'] );
		$this->assertSame( $approved ? '0' : '', $result['approved_by'] );

		if ( $approved ) {
			$this->assertSame( $company_id, $result['parent'] );
			$this->assertSame( array( 0, 0, 0 ), $result['logged_by'] );
		}
	}

	/**
	 * Requesting vetting again, as an invoice does, leaves a pending sponsorship alone even when its company is
	 * vetted: a vetter may have reopened it to move it to another company.
	 */
	public function test_request_vetting_again_doesnt_approve(): void {
		$this->create_mes_company( 'sv-compliant' );

		$sponsor_id     = $this->create_camp_sponsor( 'https://acme.example' );
		$sponsorship_id = request_vetting( self::$year_dot_2018_site_id, $sponsor_id );

		$status = $this->on_site(
			WORDCAMP_ROOT_BLOG_ID,
			static function () use ( $sponsorship_id, $sponsor_id ): string {
				foreach ( array( 'svs-canceled', 'svs-pending' ) as $reopen ) {
					wp_update_post(
						array(
							'ID'          => $sponsorship_id,
							'post_status' => $reopen,
						)
					);
				}

				request_vetting( self::$year_dot_2018_site_id, $sponsor_id );

				return (string) get_post_status( $sponsorship_id );
			}
		);

		$this->assertSame( 'svs-pending', $status );
	}

	/**
	 * Companies a camp sponsor's website leads to, and whether requesting vetting approves it.
	 *
	 * @return array[]
	 */
	public function data_auto_approvals(): array {
		return array(
			'website a vetted company uses'        => array( 'sv-compliant', false, false, true ),
			'multi-event copy of a vetted company' => array( 'sv-compliant', true, false, true ),
			'website two companies use'            => array( 'sv-compliant', false, true, false ),
			'website a company being vetted uses'  => array( 'sv-in-progress', false, false, false ),
		);
	}

	/**
	 * A company whose Compliant vetting has lapsed, or that's Inactive, goes back in the queue when a new
	 * sponsorship comes in.
	 *
	 * @dataProvider data_requeued_companies
	 *
	 * @param string   $status      The company's status.
	 * @param int|null $last_vetted When it was last vetted, if not when it got its status.
	 */
	public function test_request_vetting_requeues_a_company( string $status, ?int $last_vetted ): void {
		list( $company_id, $mes_id ) = $this->create_mes_company( $status, $last_vetted );

		request_vetting( self::$year_dot_2018_site_id, $this->create_camp_sponsor( 'https://acme.example', array( '_mes_id' => $mes_id ) ) );

		$this->assertSame( 'sv-needs-vetting', $this->on_site( WORDCAMP_ROOT_BLOG_ID, static fn(): string => (string) get_post_status( $company_id ) ) );
	}

	/**
	 * Companies a new sponsorship puts back in the queue.
	 *
	 * @return array[]
	 */
	public function data_requeued_companies(): array {
		return array(
			'compliant, vetted 13 months ago' => array( 'sv-compliant', (int) strtotime( '-13 months' ) ),
			'inactive'                        => array( 'sv-inactive', null ),
		);
	}

	/**
	 * Submitting a sponsor invoice requests vetting for its sponsor. Without a website, or with an email address the
	 * vetting team can't write to, the invoice stays a draft and asks for nothing, as it does for other missing info.
	 *
	 * @dataProvider data_submitting_an_invoice_requests_vetting
	 *
	 * @param string $website   The sponsor's website.
	 * @param string $email     The contact's email address.
	 * @param bool   $submitted Whether the invoice is submitted, requesting vetting.
	 */
	public function test_submitting_an_invoice_requests_vetting( string $website, string $email, bool $submitted ): void {
		$sponsor_id = $this->create_camp_sponsor(
			$website,
			array( '_wcpt_sponsor_email_address' => $email ) + $this->get_invoiceable_sponsor_meta()
		);

		list( $status, $pointer ) = $this->on_site(
			self::$year_dot_2018_site_id,
			function () use ( $sponsor_id ): array {
				$invoice_id = $this->invoice_sponsor( $sponsor_id, 'wcbsi_submitted' );

				return array( get_post_status( $invoice_id ), (string) get_post_meta( $sponsor_id, '_sv_sponsorship_id', true ) );
			}
		);

		$this->assertSame( $submitted ? 'wcbsi_submitted' : 'draft', $status );
		$this->assertSame( $submitted, '' !== $pointer );
	}

	/**
	 * Sponsors' websites and email addresses, and whether their invoice is submitted.
	 *
	 * @return array[]
	 */
	public function data_submitting_an_invoice_requests_vetting(): array {
		return array(
			'complete'                       => array( 'https://acme.example', 'pat@acme.example', true ),
			'no website'                     => array( '', 'pat@acme.example', false ),
			'a website without a domain'     => array( 'TBD', 'pat@acme.example', false ),
			'an email with no dot in domain' => array( 'https://acme.example', 'pat@acme', false ),
		);
	}

	/**
	 * A spreadsheet's rows request vetting for the camp's sponsors, adding ones the camp doesn't have as drafts;
	 * importing it again matches the sponsors it added instead of adding them twice. A URL below a camp's address
	 * isn't that camp.
	 */
	public function test_csv_import_links_existing_and_creates_draft_sponsors(): void {
		$existing_id = $this->create_camp_sponsor( 'https://acme.example' );
		$file        = wp_tempnam( 'sponsors.csv' );

		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A test fixture.
			$file,
			"\xEF\xBB\xBFEvent_URL,Company_Name,Website,First_Name,Last_Name,Email\n"
			. "https://2018.seattle.wordcamp.test/,Acme Inc.,acme.example,Pat,Lee,pat@acme.example\n"
			. "https://2018.seattle.wordcamp.test/,Newco,https://www.newco.example/,Sam,Roe,sam@newco.example\n"
			. "https://2018.seattle.wordcamp.test/2019/,Initech,initech.example,Kim,Day,kim@initech.example\n"
		);

		$rows = parse_csv( $file, array( 'event_url', 'company_name', 'website' ) );
		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- A test fixture.

		$this->assertIsArray( $rows );

		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
		$first  = array_map( static fn( array $row ): array => import_row( $row, false ), $rows );
		$second = array_map( static fn( array $row ): array => import_row( $row, false ), $rows );
		restore_current_blog();

		$this->assertSame( array( 'existing', 'new', 'error' ), array_column( $first, 'result' ) );
		$this->assertSame( array( 'existing', 'existing', 'error' ), array_column( $second, 'result' ) );
		$this->assertSame( $existing_id, $first[0]['sponsor_id'] );
		$this->assertSame( array_column( $first, 'sponsorship_id' ), array_column( $second, 'sponsorship_id' ) );

		$new_status = $this->on_site( self::$year_dot_2018_site_id, static fn(): string => (string) get_post_status( $first[1]['sponsor_id'] ) );

		$this->assertSame( 'draft', $new_status );
	}

	/**
	 * When a company turns Compliant and its pending sponsorships are approved, a vetter in wp-admin is told so. A
	 * change from anywhere else, like WP-CLI or cron, queues nothing: nobody would see it where it belongs.
	 *
	 * @dataProvider data_auto_approval_notice
	 *
	 * @param bool $in_admin Whether the company turns Compliant in wp-admin.
	 */
	public function test_auto_approval_notice( bool $in_admin ): void {
		$sponsor_id     = $this->create_camp_sponsor( 'https://acme.example' );
		$sponsorship_id = request_vetting( self::$year_dot_2018_site_id, $sponsor_id );

		if ( $in_admin ) {
			set_current_screen( 'edit' );
		}

		$messages = $this->on_site(
			WORDCAMP_ROOT_BLOG_ID,
			static function () use ( $sponsorship_id ): array {
				wp_update_post(
					array(
						'ID'          => (int) get_post_field( 'post_parent', $sponsorship_id ),
						'post_status' => 'sv-compliant',
					)
				);

				return array( get_post_status( $sponsorship_id ), array_column( take_notices(), 'message' ) );
			}
		);

		// Back out of wp-admin, so later tests don't run in it.
		set_current_screen( 'front' );

		$this->assertSame( 'svs-approved', $messages[0] );
		$this->assertSame( $in_admin, in_array( '1 pending sponsorship was approved automatically.', $messages[1], true ) );
	}

	/**
	 * Where the company turns Compliant.
	 *
	 * @return array[]
	 */
	public function data_auto_approval_notice(): array {
		return array(
			'in wp-admin'  => array( true ),
			'anywhere else' => array( false ),
		);
	}

	/**
	 * Organizers see their own sponsors' vetting: a pointer copied along with a sponsor from another site reads as
	 * not requested. A Multi-Event Sponsor's copy is the Community Team's, with nothing to request, under any of its
	 * company's domains, until organizers repurpose it with another website or none.
	 */
	public function test_organizer_statuses(): void {
		list( $company_id, $mes_id ) = $this->create_mes_company( 'sv-compliant' );

		$this->on_site( WORDCAMP_ROOT_BLOG_ID, static fn() => set_company_domains( $company_id, array( 'acme.example', 'acme-labs.example' ) ) );

		$requested_id = $this->create_camp_sponsor( 'https://requested.example' );
		$sponsorship  = request_vetting( self::$year_dot_2018_site_id, $requested_id );
		$copied_id    = $this->create_camp_sponsor( 'https://copied.example', array( '_sv_sponsorship_id' => $sponsorship ) );
		$mes_copy_id  = $this->create_camp_sponsor( 'https://acme.example', array( '_mes_id' => $mes_id ) );
		$alias_id     = $this->create_camp_sponsor( 'https://acme-labs.example', array( '_mes_id' => $mes_id ) );
		$reused_id    = $this->create_camp_sponsor( 'https://other.example', array( '_mes_id' => $mes_id ) );
		$cleared_id   = $this->create_camp_sponsor( '', array( '_mes_id' => $mes_id ) );

		$statuses = $this->on_site(
			self::$year_dot_2018_site_id,
			static fn(): array => get_organizer_statuses( array( $requested_id, $copied_id, $mes_copy_id, $alias_id, $reused_id, $cleared_id ) )
		);

		$this->assertSame(
			array(
				$requested_id => array(
					'status'      => 'in-review',
					'label'       => 'In review',
					'can_request' => false,
				),
				$copied_id    => array(
					'status'      => 'not-requested',
					'label'       => 'Not requested',
					'can_request' => true,
				),
				$mes_copy_id  => array(
					'status'      => 'multi-event',
					'label'       => 'Multi-event sponsor',
					'can_request' => false,
				),
				$alias_id     => array(
					'status'      => 'multi-event',
					'label'       => 'Multi-event sponsor',
					'can_request' => false,
				),
				$reused_id    => array(
					'status'      => 'not-requested',
					'label'       => 'Not requested',
					'can_request' => true,
				),
				$cleared_id   => array(
					'status'      => 'not-requested',
					'label'       => 'Not requested',
					'can_request' => true,
				),
			),
			$statuses
		);
	}

	/**
	 * Organizers can narrow their sponsor list to a group of vetting statuses, where a Multi-Event Sponsor's copy
	 * counts as approved, and sort it in the order vetting goes through the statuses.
	 *
	 * @dataProvider data_filter_sponsors_by_vetting
	 *
	 * @param string $picked   The status group picked from the filter.
	 * @param string $orderby  The column sorted by.
	 * @param string $order    The sort direction.
	 * @param array  $expected The sponsors listed, in order: `unrequested`, `in_review`, or `mes_copy`.
	 */
	public function test_filter_sponsors_by_vetting( string $picked, string $orderby, string $order, array $expected ): void {
		list( , $mes_id ) = $this->create_mes_company( 'sv-compliant' );

		$sponsors = array(
			'unrequested' => $this->create_camp_sponsor( 'https://unrequested.example' ),
			'in_review'   => $this->create_camp_sponsor( 'https://in-review.example' ),
			'mes_copy'    => $this->create_camp_sponsor( 'https://acme.example', array( '_mes_id' => $mes_id ) ),
		);
		request_vetting( self::$year_dot_2018_site_id, $sponsors['in_review'] );

		$main_query = $GLOBALS['wp_the_query'];
		$list_query = new \WP_Query();
		$get        = $_GET;
		$hook       = 'WordCamp\Sponsor_Vetting\filter_sponsors_by_vetting';

		$_GET['sv_vetting']      = $picked;
		$GLOBALS['wp_the_query'] = $list_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The list's query is the main query.
		add_action( 'pre_get_posts', $hook );

		$listed = $this->on_site(
			self::$year_dot_2018_site_id,
			static fn(): array => $list_query->query(
				array(
					'post_type' => 'wcb_sponsor',
					'orderby'   => $orderby,
					'order'     => $order,
					'fields'    => 'ids',
				)
			)
		);

		remove_action( 'pre_get_posts', $hook );
		$GLOBALS['wp_the_query'] = $main_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the main query.
		$_GET                    = $get;

		$this->assertSame( array_map( static fn( string $key ): int => $sponsors[ $key ], $expected ), $listed );
	}

	/**
	 * Data provider for test_filter_sponsors_by_vetting().
	 *
	 * @return array[]
	 */
	public function data_filter_sponsors_by_vetting(): array {
		return array(
			'a copy under approved'    => array( 'approved', '', '', array( 'mes_copy' ) ),
			'a group no sponsor is in' => array( 'not-approved', '', '', array() ),
			'sorted in vetting order'  => array( '', 'sv_vetting', 'ASC', array( 'unrequested', 'in_review', 'mes_copy' ) ),
			'sorted in reverse'        => array( '', 'sv_vetting', 'DESC', array( 'mes_copy', 'in_review', 'unrequested' ) ),
		);
	}

	/**
	 * Requesting vetting from the sponsor's edit screen saves what the organizer entered with it first, so a website
	 * typed just before the request still gets its company. A save without the request, with a forged one, or with an
	 * email address the vetting team can't write to asks for nothing. No phone number is needed.
	 *
	 * @dataProvider data_request_from_edit_screen
	 *
	 * @param bool   $clicked     Whether the save carries the request.
	 * @param bool   $valid_nonce Whether the request's nonce is the box's.
	 * @param string $email       The contact's email address.
	 * @param bool   $requested   Whether vetting is requested.
	 */
	public function test_request_from_edit_screen( bool $clicked, bool $valid_nonce, string $email, bool $requested ): void {
		$sponsor_id = $this->create_camp_sponsor( '' );
		$user_id    = self::factory()->user->create();

		add_user_to_blog( self::$year_dot_2018_site_id, $user_id, 'editor' );
		wp_set_current_user( $user_id );
		set_current_screen( 'post' );

		$sponsorship_id = $this->on_site(
			self::$year_dot_2018_site_id,
			static function () use ( $sponsor_id, $clicked, $valid_nonce, $email ): int {
				$_POST = array(
					'wcpt-meta-sponsor-info'      => wp_create_nonce( 'edit-sponsor-info' ),
					'_wcpt_sponsor_company_name'  => 'Acme Inc.',
					'_wcpt_sponsor_website'       => 'https://typed.example',
					'_wcpt_sponsor_first_name'    => 'Pat',
					'_wcpt_sponsor_last_name'     => 'Doe',
					'_wcpt_sponsor_email_address' => $email,
					'sv_request_vetting_nonce'    => $valid_nonce ? wp_create_nonce( 'sv_request_vetting_' . $sponsor_id ) : 'forged',
				);

				if ( $clicked ) {
					$_POST['sv_request_vetting'] = '1';
				}

				wp_update_post( array( 'ID' => $sponsor_id ) );

				$_POST = array();

				return (int) get_post_meta( $sponsor_id, '_sv_sponsorship_id', true );
			}
		);

		$domains = $sponsorship_id ? $this->on_site(
			WORDCAMP_ROOT_BLOG_ID,
			static fn(): array => get_company_domains( (int) get_post_field( 'post_parent', $sponsorship_id ) )
		) : null;

		$this->assertSame( $requested ? array( 'typed.example' ) : null, $domains );
	}

	/**
	 * Saves of a camp's sponsor, and whether they request vetting.
	 *
	 * @return array[]
	 */
	public function data_request_from_edit_screen(): array {
		return array(
			'saved with the request' => array( true, true, 'pat@acme.example', true ),
			'a plain save'           => array( false, true, 'pat@acme.example', false ),
			'a forged request'       => array( true, false, 'pat@acme.example', false ),
			'an email placeholder'   => array( true, true, 'TBD', false ),
		);
	}

	/**
	 * Organizers are told what in the Contact Information keeps vetting from being requested: an empty field, a
	 * website without a domain, or an email address the vetting team can't write to, even one the browser accepts.
	 *
	 * @dataProvider data_contact_info_problem
	 *
	 * @param string $website   The sponsor's website.
	 * @param string $email     The contact's email address.
	 * @param string $last_name The contact's last name.
	 * @param string $problem   The problem organizers are told about, or an empty string for none.
	 */
	public function test_contact_info_problem( string $website, string $email, string $last_name, string $problem ): void {
		$sponsor_id = $this->create_camp_sponsor(
			$website,
			array(
				'_wcpt_sponsor_email_address' => $email,
				'_wcpt_sponsor_first_name'    => 'Frank',
				'_wcpt_sponsor_last_name'     => $last_name,
			)
		);

		$message = $this->on_site( self::$year_dot_2018_site_id, static fn(): string => get_contact_info_problem( $sponsor_id ) );

		$this->assertSame( '' === $problem ? '' : get_contact_info_messages()[ $problem ], $message );
	}

	/**
	 * Contact Information, and what organizers are told about it.
	 *
	 * @return array[]
	 */
	public function data_contact_info_problem(): array {
		return array(
			'complete'                       => array( 'hybrid.example', 'frank@hybrid.example', 'Lloyd', '' ),
			'a name missing'                 => array( 'hybrid.example', 'frank@hybrid.example', '', 'missing' ),
			'a website without a domain'     => array( 'hybrid', 'frank@hybrid.example', 'Lloyd', 'website' ),
			'an email with no dot in domain' => array( 'hybrid.example', 'frank@gmail', 'Lloyd', 'email' ),
		);
	}

	/**
	 * The sponsor list reminds organizers to request vetting while a published sponsor hasn't been submitted,
	 * counting only those, and says nothing once every one has been. Drafts, like the ones the Call for Sponsors form
	 * makes of every application, don't count.
	 *
	 * @dataProvider data_vetting_reminder
	 *
	 * @param bool   $all_requested Whether vetting was requested for every sponsor.
	 * @param string $expected      The reminder's text, or an empty string for none.
	 */
	public function test_vetting_reminder( bool $all_requested, string $expected ): void {
		$requested_id = $this->create_camp_sponsor( 'https://requested.example' );
		$other_id     = $this->create_camp_sponsor( 'https://other.example' );
		$applicant_id = $this->create_camp_sponsor( 'https://applicant.example' );

		$this->on_site(
			self::$year_dot_2018_site_id,
			static fn(): int => wp_update_post(
				array(
					'ID'          => $applicant_id,
					'post_status' => 'draft',
				)
			)
		);
		request_vetting( self::$year_dot_2018_site_id, $requested_id );

		if ( $all_requested ) {
			request_vetting( self::$year_dot_2018_site_id, $other_id );
		}

		$reminder = $this->on_site(
			self::$year_dot_2018_site_id,
			static function (): string {
				ob_start();
				render_vetting_reminder();

				return wp_strip_all_tags( (string) ob_get_clean() );
			}
		);

		$this->assertSame( $expected, trim( $reminder ) );
	}

	/**
	 * Whether every sponsor was submitted, and the reminder the list shows.
	 *
	 * @return array[]
	 */
	public function data_vetting_reminder(): array {
		return array(
			'one not submitted' => array( false, '1 sponsor hasn’t been submitted for vetting yet. Open it and click “Request vetting” in its Sponsor Vetting box. Show it' ),
			'all submitted'     => array( true, '' ),
		);
	}

	/**
	 * The route the block editor redraws the vetting box from serves only people who can edit the camp's sponsor, and
	 * hands them the notices their save queued, once, since the block editor doesn't load a screen to show them on.
	 *
	 * @dataProvider data_vetting_box_route_access
	 *
	 * @param string $role       The user's role on the camp site.
	 * @param int    $status     The expected response status.
	 * @param bool   $serves_box Whether the response holds the box.
	 */
	public function test_vetting_box_route_access( string $role, int $status, bool $serves_box ): void {
		$sponsor_id = $this->create_camp_sponsor( 'https://acme.example' );
		$user_id    = self::factory()->user->create();

		add_user_to_blog( self::$year_dot_2018_site_id, $user_id, $role );
		wp_set_current_user( $user_id );

		$response = $this->on_site(
			self::$year_dot_2018_site_id,
			static function () use ( $sponsor_id ): array {
				add_notice( 'error', 'Vetting wasn’t requested.' );

				$GLOBALS['wp_rest_server'] = new \WP_REST_Server();
				do_action( 'rest_api_init', $GLOBALS['wp_rest_server'] );

				$response = rest_do_request( new \WP_REST_Request( 'GET', '/sponsor-vetting/v1/vetting-box/' . $sponsor_id ) );

				$GLOBALS['wp_rest_server'] = null;

				return array( $response, take_notices() );
			}
		);

		list( $response, $still_queued ) = $response;

		$notice = array(
			'type'    => 'error',
			'message' => 'Vetting wasn’t requested.',
		);

		$this->assertSame( $status, $response->get_status() );
		$this->assertSame( $serves_box, str_contains( (string) ( $response->get_data()['html'] ?? '' ), 'Request vetting' ) );
		$this->assertSame( $serves_box ? array( $notice ) : array(), $response->get_data()['notices'] ?? array() );
		$this->assertSame( $serves_box ? array() : array( $notice ), $still_queued );
	}

	/**
	 * Roles on the camp site, and what the route answers them.
	 *
	 * @return array[]
	 */
	public function data_vetting_box_route_access(): array {
		return array(
			'organizer who can edit the sponsor' => array( 'editor', 200, true ),
			'subscriber'                         => array( 'subscriber', 403, false ),
		);
	}
}
