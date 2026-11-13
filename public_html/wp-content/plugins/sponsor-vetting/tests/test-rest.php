<?php
/**
 * Tests for companies and sponsorships over core's REST endpoints, which the vetting screen reads and edits through,
 * and for the routes that send their emails.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting\Tests;

use WordCamp\Tests\Database_TestCase;
use function WordCamp\Sponsor_Vetting\approve_sponsorship;
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

		unregister_post_type( 'mes' );

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
						'log'      => array(),
						'event'    => $event( 'Camp Past', '-10 days' ),
						'approved' => '',
					),
					array(
						'id'       => $events['later'],
						'status'   => 'svs-pending',
						'log'      => array(),
						'event'    => $event( 'Camp Later', '+20 days' ),
						'approved' => '',
					),
					array(
						'id'       => $events['soon'],
						'status'   => 'svs-pending',
						'log'      => array(
							array(
								'time' => gmdate( 'c', 1790000200 ),
								'html' => sprintf( $note, 'Asked the camp' ),
							),
						),
						'event'    => $event( 'Camp Soon', '+10 days' ),
						'approved' => '',
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
	 * A status change the workflow refuses is answered with an error and leaves the company as it was.
	 *
	 * @dataProvider data_status_changes
	 *
	 * @param string $to     The status asked for.
	 * @param int    $status The response's status.
	 * @param string $stored The company's status afterwards.
	 */
	public function test_refused_status_changes_are_errors( string $to, int $status, string $stored ): void {
		$company_id = self::factory()->post->create(
			array(
				'post_type'   => COMPANY_POST_TYPE,
				'post_status' => 'sv-in-progress',
			)
		);

		$response = $this->request( 'POST', '/sv-companies/' . $company_id, array( 'status' => $to ) );

		$this->assertSame( array( $status, $stored ), array( $response->get_status(), get_post_status( $company_id ) ) );
	}

	/**
	 * The statuses a vetter asks for, and what becomes of them.
	 *
	 * @return array[]
	 */
	public function data_status_changes(): array {
		return array(
			'a status the company can move to'   => array( 'sv-compliance-req', 200, 'sv-compliance-req' ),
			'a status the company can’t move to' => array( 'sv-needs-vetting', 400, 'sv-in-progress' ),
		);
	}

	/**
	 * A vetter edits a company's name, websites, contact, findings and dates and adds a note in one request, each
	 * cleaned as it's stored and an emptied value removed. Making the company Compliant then clears its Good Faith
	 * date, even one sent with that request.
	 */
	public function test_vetter_edits_a_company(): void {
		$company_id = self::factory()->post->create(
			array(
				'post_type'   => COMPANY_POST_TYPE,
				'post_status' => 'sv-compliance-req',
				'meta_input'  => array( '_sv_contact_first_name' => 'Dana' ),
			)
		);

		$edited = $this->request(
			'POST',
			'/sv-companies/' . $company_id,
			array(
				'title'          => 'Acme Hosting',
				'domains'        => array( 'https://www.Acme.test/plans', 'acme-two.test' ),
				'meta'           => array(
					'_sv_contact_first_name' => '',
					'_sv_contact_email'      => 'Dana@Acme.TEST',
					'_sv_gpl_findings'       => '- Bundles a **non-GPL** theme.',
					'_sv_not_eligible_until' => 'soon',
				),
				'good_faith_due' => '2027-01-15',
				'note'           => 'Asked <b>Dana</b>',
			)
		);
		$stored = array(
			$edited->get_status(),
			get_the_title( $company_id ),
			$edited->get_data()['domains'],
			get_post_meta( $company_id, '_sv_contact_email', true ),
			get_post_meta( $company_id, '_sv_gpl_findings', true ),
			metadata_exists( 'post', $company_id, '_sv_contact_first_name' ),
			metadata_exists( 'post', $company_id, '_sv_not_eligible_until' ),
			get_post_meta( $company_id, '_sv_good_faith_due', true ),
			wp_list_pluck( get_post_meta( $company_id, '_sv_note', false ), 'text' ),
		);

		// The panel sends the date along when the vetter changed it before picking Compliant.
		$compliant = $this->request(
			'POST',
			'/sv-companies/' . $company_id,
			array(
				'status'         => 'sv-compliant',
				'good_faith_due' => '2027-02-01',
			)
		);

		$this->assertSame(
			array( 200, 'Acme Hosting', array( 'acme.test', 'acme-two.test' ), 'dana@acme.test', '- Bundles a **non-GPL** theme.', false, false, '2027-01-15', array( 'Asked Dana' ) ),
			$stored
		);
		$this->assertSame( array( 200, false ), array( $compliant->get_status(), metadata_exists( 'post', $company_id, '_sv_good_faith_due' ) ) );
	}

	/**
	 * A company offers the Multi-Event Sponsors no other company is linked to, and links to one of those only.
	 */
	public function test_company_links_a_free_multi_event_sponsor(): void {
		register_post_type( 'mes' );

		$company_id = self::factory()->post->create(
			array(
				'post_type'   => COMPANY_POST_TYPE,
				'post_status' => 'sv-in-progress',
			)
		);
		$other_id   = self::factory()->post->create(
			array(
				'post_type'   => COMPANY_POST_TYPE,
				'post_status' => 'sv-in-progress',
			)
		);
		$free       = self::factory()->post->create(
			array(
				'post_type'  => 'mes',
				'post_title' => 'Free',
			)
		);
		$taken      = self::factory()->post->create(
			array(
				'post_type'  => 'mes',
				'post_title' => 'Taken',
				'meta_input' => array( '_sv_company_id' => $other_id ),
			)
		);

		$offered = $this->request( 'GET', '/sv-companies/' . $company_id )->get_data()['mes_options'];
		$linked  = $this->request( 'POST', '/sv-companies/' . $company_id, array( 'mes' => $free ) );
		$refused = $this->request( 'POST', '/sv-companies/' . $company_id, array( 'mes' => $taken ) );

		$this->assertSame(
			array(
				array(
					array(
						'id'   => $free,
						'name' => 'Free',
					),
				),
				$free,
				400,
				$other_id,
			),
			array( $offered, $linked->get_data()['mes'], $refused->get_status(), (int) get_post_meta( $taken, '_sv_company_id', true ) )
		);
	}

	/**
	 * Setting a sponsorship's status to Approved approves it only as the workflow allows: for a vetted company, or
	 * in good faith for one asked for changes, which then has a date to finish by, and for the website the vetter
	 * was shown.
	 *
	 * @dataProvider data_approvals
	 *
	 * @param string $company_status The company's status.
	 * @param array  $params         What's sent with the status.
	 * @param int    $status         The response's status.
	 * @param string $stored         The sponsorship's status afterwards.
	 * @param bool   $has_due_date   Whether the company has a Good Faith date afterwards.
	 */
	public function test_vetter_approves_a_sponsorship( string $company_status, array $params, int $status, string $stored, bool $has_due_date ): void {
		$company_id     = self::factory()->post->create(
			array(
				'post_type'   => COMPANY_POST_TYPE,
				'post_status' => $company_status,
			)
		);
		$sponsorship_id = $this->create_sponsorship( self::$year_dot_2018_site_id, 'svs-pending', $company_id );

		// The event was told some other way, so approving sends it no email.
		update_post_meta( $sponsorship_id, '_sv_approval_notified', 1 );

		$response = $this->request( 'POST', '/sv-sponsorships/' . $sponsorship_id, array( 'status' => 'svs-approved' ) + $params );

		$this->assertSame(
			array( $status, $stored, $has_due_date ),
			array( $response->get_status(), get_post_status( $sponsorship_id ), metadata_exists( 'post', $company_id, '_sv_good_faith_due' ) )
		);
	}

	/**
	 * Approvals a vetter asks for, and what becomes of them.
	 *
	 * @return array[]
	 */
	public function data_approvals(): array {
		return array(
			'a vetted company'                         => array( 'sv-compliant', array(), 200, 'svs-approved', false ),
			'a company that isn’t vetted'              => array( 'sv-in-progress', array(), 400, 'svs-pending', false ),
			'in good faith, a company asked to change' => array( 'sv-compliance-req', array( 'good_faith' => true ), 200, 'svs-approved', true ),
			'in good faith, a company not asked yet'   => array( 'sv-in-progress', array( 'good_faith' => true ), 400, 'svs-pending', false ),
			'another website than the vetter saw'      => array( 'sv-compliant', array( 'shown_domain' => 'other.test' ), 400, 'svs-pending', false ),
		);
	}

	/**
	 * A sponsorship waiting for a company names the one suggested for it, and is linked by writing its company. Only
	 * a company will do.
	 *
	 * @dataProvider data_links
	 *
	 * @param string $post_type The post type of the post it's linked to.
	 * @param int    $status    The response's status.
	 * @param bool   $is_linked Whether it's linked afterwards.
	 */
	public function test_vetter_links_a_sponsorship( string $post_type, int $status, bool $is_linked ): void {
		$suggested_id   = self::factory()->post->create(
			array(
				'post_type'   => COMPANY_POST_TYPE,
				'post_status' => 'sv-in-progress',
			)
		);
		$target_id      = COMPANY_POST_TYPE === $post_type ? $suggested_id : self::factory()->post->create( array( 'post_type' => $post_type ) );
		$sponsorship_id = $this->create_sponsorship( self::$year_dot_2018_site_id, 'svs-pending', 0 );

		update_post_meta( $sponsorship_id, '_sv_suggested_company', $suggested_id );

		$suggestion = $this->request( 'GET', '/sv-sponsorships/' . $sponsorship_id )->get_data()['suggestion'];
		$response   = $this->request( 'POST', '/sv-sponsorships/' . $sponsorship_id, array( 'company' => $target_id ) );

		$this->assertSame(
			array( $suggested_id, $status, $is_linked ? $suggested_id : 0 ),
			array( $suggestion['id'], $response->get_status(), (int) get_post_field( 'post_parent', $sponsorship_id ) )
		);
	}

	/**
	 * What a sponsorship is linked to, and what becomes of it.
	 *
	 * @return array[]
	 */
	public function data_links(): array {
		return array(
			'the suggested company'      => array( COMPANY_POST_TYPE, 200, true ),
			'a post that isn’t a company' => array( 'post', 400, false ),
		);
	}

	/**
	 * A sponsorship shows the website the camp entered for its sponsor, and links it only when it's a web address:
	 * organizers enter it, and vetters click it.
	 *
	 * @dataProvider data_websites
	 *
	 * @param string $website What the camp entered.
	 * @param string $url     The address the screen links to.
	 */
	public function test_sponsor_website_links_only_to_the_web( string $website, string $url ): void {
		switch_to_blog( self::$year_dot_2018_site_id );
		$sponsor_id = self::factory()->post->create(
			array(
				'post_type'  => 'wcb_sponsor',
				'meta_input' => array( '_wcpt_sponsor_website' => $website ),
			)
		);
		restore_current_blog();

		$sponsorship_id = $this->create_sponsorship( self::$year_dot_2018_site_id, 'svs-pending', 0 );
		update_post_meta( $sponsorship_id, '_sv_sponsor_id', $sponsor_id );

		$sponsor = $this->request( 'GET', '/sv-sponsorships/' . $sponsorship_id )->get_data()['sponsor'];

		$this->assertSame( array( $website, $url ), array( $sponsor['website'], $sponsor['website_url'] ) );
	}

	/**
	 * Websites a camp enters, and where each leads.
	 *
	 * @return array[]
	 */
	public function data_websites(): array {
		return array(
			'a web address' => array( 'https://acme.test/hosting', 'https://acme.test/hosting' ),
			'a script'      => array( 'javascript:alert(document.domain)', '' ),
		);
	}

	/**
	 * A trashed sponsorship says which status it had, and writing that status restores it: an approved one comes
	 * back approved, without its company being checked again.
	 */
	public function test_restoring_from_the_trash_returns_the_status(): void {
		$company_id     = self::factory()->post->create(
			array(
				'post_type'   => COMPANY_POST_TYPE,
				'post_status' => 'sv-compliant',
			)
		);
		$sponsorship_id = $this->create_sponsorship( self::$year_dot_2018_site_id, 'svs-pending', $company_id );

		approve_sponsorship( $sponsorship_id );
		wp_update_post(
			array(
				'ID'          => $company_id,
				'post_status' => 'sv-in-progress',
			)
		);

		$trashed  = $this->request( 'DELETE', '/sv-sponsorships/' . $sponsorship_id );
		$had      = $this->request( 'GET', '/sv-sponsorships/' . $sponsorship_id )->get_data()['trashed_status'];
		$restored = $this->request( 'POST', '/sv-sponsorships/' . $sponsorship_id, array( 'status' => $had ) );

		$this->assertSame(
			array( 'trash', 'svs-approved', 200, 'svs-approved' ),
			array( $trashed->get_data()['status'], $had, $restored->get_status(), get_post_status( $sponsorship_id ) )
		);
	}

	/**
	 * Only sponsor vetters reach the routes that send the emails, and only for a record of the route's kind. The
	 * company here has no contact to email and the sponsorship isn't approved, so nothing is sent.
	 *
	 * @dataProvider data_senders
	 *
	 * @param string $subrole The user's subrole.
	 * @param string $route   The route, with `%1$d` for the company's ID and `%2$d` for the sponsorship's.
	 * @param int    $status  The response's status.
	 */
	public function test_only_vetters_send_the_emails( string $subrole, string $route, int $status ): void {
		$company_id     = self::factory()->post->create(
			array(
				'post_type'   => COMPANY_POST_TYPE,
				'post_status' => 'sv-in-progress',
			)
		);
		$sponsorship_id = $this->create_sponsorship( self::$year_dot_2018_site_id, 'svs-pending', $company_id );

		$response = $this->request( 'POST', sprintf( '/sponsor-vetting/v1' . $route, $company_id, $sponsorship_id ), array( 'sponsorship' => $sponsorship_id ), $subrole );

		$this->assertSame( $status, $response->get_status() );
	}

	/**
	 * Who asks for which email, and how the routes answer.
	 *
	 * @return array[]
	 */
	public function data_senders(): array {
		return array(
			// Let through, then refused for the missing contact.
			'a vetter, a compliance request'          => array( 'sponsor_vetter', '/companies/%1$d/compliance-request', 400 ),
			'a wrangler, a compliance request'        => array( 'wordcamp_wrangler', '/companies/%1$d/compliance-request', 403 ),
			'a vetter, a sponsorship as the company'  => array( 'sponsor_vetter', '/companies/%2$d/compliance-request', 403 ),
			'a vetter, an approval email'             => array( 'sponsor_vetter', '/sponsorships/%2$d/approval-email', 200 ),
			'a wrangler, an approval email'           => array( 'wordcamp_wrangler', '/sponsorships/%2$d/approval-email', 403 ),
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
	 * Asks an endpoint on Central, in the context the screen reads in, as a user with a subrole.
	 *
	 * @param string $method  The request's method.
	 * @param string $route   The route: one of core's, after `wp/v2`, or one of the plugin's in full.
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

		$request = new \WP_REST_Request( $method, str_starts_with( $route, '/sponsor-vetting/' ) ? $route : '/wp/v2' . $route );
		$request->set_param( 'context', 'edit' );

		foreach ( $params as $name => $value ) {
			$request->set_param( $name, $value );
		}

		return rest_do_request( $request );
	}
}
