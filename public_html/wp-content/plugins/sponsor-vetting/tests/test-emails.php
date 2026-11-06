<?php
/**
 * Tests for the compliance request and approval emails, and reading replies to them.
 *
 * Nothing here sends: the builders return the message, and the conversations FreeScout would return are given as
 * input.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting\Tests;

use WordCamp\Tests\Database_TestCase;
use function WordCamp\Sponsor_Vetting\build_compliance_email;
use function WordCamp\Sponsor_Vetting\get_conversations;
use function WordCamp\Sponsor_Vetting\record_conversation;
use function WordCamp\Sponsor_Vetting\request_vetting;
use function WordCamp\Sponsor_Vetting\send_compliance_request;
use function WordCamp\Sponsor_Vetting\sponsor_wrote_last;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/trait-submits-invoices.php';

/**
 * Extends `Database_TestCase` for its Central and camp sites: emails name and copy in the camp's event.
 *
 * @group sponsor-vetting
 */
class Test_Emails extends Database_TestCase {
	use Submits_Invoices;

	/**
	 * The camp's sponsor.
	 *
	 * @var int
	 */
	protected int $sponsor_id;

	/**
	 * The sponsor's sponsorship, on Central.
	 *
	 * @var int
	 */
	protected int $sponsorship_id;

	/**
	 * The sponsorship's company, on Central.
	 *
	 * @var int
	 */
	protected int $company_id;

	/**
	 * Creates the camp's event, a sponsor, and its vetting request.
	 */
	public function set_up(): void {
		parent::set_up();

		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
		self::factory()->post->create(
			array(
				'post_type'   => 'wordcamp',
				'post_status' => 'publish',
				'post_title'  => 'WordCamp Seattle',
				'meta_input'  => array(
					'_site_id'                => self::$year_dot_2018_site_id,
					'E-mail Address'          => 'seattle@wordcamp.test',
					'Start Date (YYYY-mm-dd)' => strtotime( '2026-06-01' ),
				),
			)
		);
		restore_current_blog();

		switch_to_blog( self::$year_dot_2018_site_id );
		$this->sponsor_id = self::factory()->post->create(
			array(
				'post_type'   => 'wcb_sponsor',
				'post_status' => 'publish',
				'post_title'  => 'Acme',
				'meta_input'  => array( '_wcpt_sponsor_website' => 'https://acme.example' ) + $this->get_invoiceable_sponsor_meta(),
			)
		);
		restore_current_blog();

		$this->sponsorship_id = request_vetting( self::$year_dot_2018_site_id, $this->sponsor_id );

		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
		$this->company_id = (int) get_post_field( 'post_parent', $this->sponsorship_id );
	}

	/**
	 * Leaves Central.
	 */
	public function tear_down(): void {
		restore_current_blog();

		parent::tear_down();
	}

	/**
	 * Fakes the FreeScout API calls `FreeScout::send()` makes: creating the conversation (`POST /api/conversations`)
	 * and tagging it (`PUT /api/conversations/{id}/tags`). Clients older than WordPress/wporg-mu-plugins#797 also look
	 * up the sending agent first (`GET /api/users`), and local checkouts may still have one, so that call is answered
	 * but not required. The responses follow the API's shape.
	 *
	 * @param array $requests Collects each request and its decoded body.
	 * @return \Closure A `pre_http_request` callback.
	 */
	protected function fake_freescout( array &$requests ): \Closure {
		return static function ( mixed $response, array $args, string $url ) use ( &$requests ): mixed {
			$api = 'https://freescout.wordpress.net/api/';

			if ( ! str_starts_with( $url, $api ) ) {
				return $response;
			}

			$request    = $args['method'] . ' ' . strtok( substr( $url, strlen( $api ) ), '?' );
			$requests[] = array(
				'request' => $request,
				'body'    => json_decode( (string) ( $args['body'] ?? '' ), true ),
			);

			$responses = array(
				'GET users'                   => array(
					200,
					array(
						'_embedded' => array(
							'users' => array(
								array(
									'id'        => 84,
									'firstName' => 'Community',
									'lastName'  => 'Team',
									'email'     => 'vetter@wordcamp.test',
								),
							),
						),
					),
				),
				'POST conversations'          => array(
					201,
					array(
						'id'           => 12345,
						'number'       => 6789,
						'type'         => 'email',
						'status'       => 'active',
						'state'        => 'published',
						'subject'      => 'Action Required: Compliance for WordPress Event Sponsorship',
						'mailboxId'    => 7,
						'threadsCount' => 1,
						'createdAt'    => '2026-10-27T09:00:00Z',
					),
				),
				'PUT conversations/12345/tags' => array( 204, null ),
			);

			list( $code, $body ) = $responses[ $request ] ?? array( 404, array( 'message' => 'Not found' ) );

			return array(
				'headers'  => array(),
				'body'     => null === $body ? '' : wp_json_encode( $body ),
				'response' => array(
					'code'    => $code,
					'message' => '',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
	}

	/**
	 * The compliance request carries Legal's wording and the rendered findings to the sponsor, copies the event in
	 * and is tagged, which the Zap it replaces didn't do.
	 */
	public function test_compliance_email_follows_the_template(): void {
		update_post_meta( $this->company_id, '_sv_gpl_findings', 'The **theme shop** sells themes under a non-GPL license.' );

		$email = build_compliance_email( $this->company_id, $this->sponsorship_id );

		$this->assertIsArray( $email );
		$this->assertSame(
			array(
				'mailbox' => 'community',
				'to'      => array(
					'email'      => 'pat@acme.example',
					'first_name' => 'Pat',
					'last_name'  => 'Lee',
				),
				'subject' => 'Action Required: Compliance for WordPress Event Sponsorship',
				'cc'      => array( 'seattle@wordcamp.test' ),
				'tags'    => array( 'sponsor-vetting', 'sponsor-infringing' ),
			),
			array_diff_key( $email, array( 'body' => true ) )
		);
		$this->assertStringContainsString( '<p>Thanks so much for offering to sponsor WordCamp Seattle 2026! We’re excited to have you on board.</p>', $email['body'] );
		$this->assertStringContainsString( '<p><strong>GPL:</strong></p><p>The <strong>theme shop</strong> sells themes under a non-GPL license.</p>', $email['body'] );
		$this->assertStringNotContainsString( 'Trademark:', $email['body'] );
	}

	/**
	 * Sending the compliance request emails the sponsor with the event copied in, moves the company to Compliance
	 * Requested, and records the conversation.
	 *
	 * Fakes FreeScout's API through `pre_http_request`: see fake_freescout().
	 */
	public function test_sending_the_compliance_request_records_the_conversation(): void {
		update_post_meta( $this->company_id, '_sv_gpl_findings', 'The **theme shop** sells themes under a non-GPL license.' );
		wp_set_current_user( self::factory()->user->create( array( 'user_email' => 'vetter@wordcamp.test' ) ) );

		$requests = array();
		$fake     = $this->fake_freescout( $requests );

		add_filter( 'pre_http_request', $fake, 10, 3 );
		$conversation = send_compliance_request( $this->company_id, $this->sponsorship_id );
		remove_filter( 'pre_http_request', $fake, 10 );

		// Older FreeScout clients look the sending agent up first. Order and repeats still count.
		$sent = array_values(
			array_filter(
				$requests,
				static fn ( array $request ): bool => 'GET users' !== $request['request']
			)
		);

		$this->assertIsArray( $conversation );
		$this->assertSame( array( 'POST conversations', 'PUT conversations/12345/tags' ), array_column( $sent, 'request' ) );
		$this->assertSame( array( 'seattle@wordcamp.test' ), $sent[0]['body']['threads'][0]['cc'] );
		$this->assertSame( array( 'sponsor-vetting', 'sponsor-infringing' ), $sent[1]['body']['tags'] );
		$this->assertSame( 'sv-compliance-req', get_post_status( $this->company_id ) );
		$this->assertSame(
			array(
				'id'             => 12345,
				'url'            => 'https://freescout.wordpress.net/conversation/12345',
				'type'           => 'compliance',
				'sponsorship_id' => $this->sponsorship_id,
			),
			array_intersect_key( get_conversations( $this->company_id, 'compliance' )[0], array_flip( array( 'id', 'url', 'type', 'sponsorship_id' ) ) )
		);
	}

	/**
	 * Marking a company Compliant approves its pending sponsorship, as the system, and emails the event unless it has
	 * invoiced the sponsor, since approving the invoice tells them. A sponsor whose website isn't one the company was
	 * vetted for waits for a vetter.
	 *
	 * Fakes FreeScout's API through `pre_http_request`: see fake_freescout().
	 *
	 * @dataProvider data_auto_approvals
	 *
	 * @param string $website        The camp sponsor's website.
	 * @param string $invoice_status The camp's invoice for the sponsor, or '' for none.
	 * @param bool   $approved       Whether the sponsorship is approved.
	 * @param bool   $emailed        Whether the event is emailed.
	 */
	public function test_a_compliant_company_approves_its_sponsorships( string $website, string $invoice_status, bool $approved, bool $emailed ): void {
		switch_to_blog( self::$year_dot_2018_site_id );
		update_post_meta( $this->sponsor_id, '_wcpt_sponsor_website', $website );

		if ( '' !== $invoice_status ) {
			$this->assertSame( $invoice_status, get_post_status( $this->invoice_sponsor( $this->sponsor_id, $invoice_status ) ) );
		}

		restore_current_blog();

		wp_set_current_user( self::factory()->user->create( array( 'user_email' => 'vetter@wordcamp.test' ) ) );

		$requests = array();
		$fake     = $this->fake_freescout( $requests );

		add_filter( 'pre_http_request', $fake, 10, 3 );
		wp_update_post(
			array(
				'ID'          => $this->company_id,
				'post_status' => 'sv-compliant',
			)
		);
		remove_filter( 'pre_http_request', $fake, 10 );

		$this->assertSame( $approved ? 'svs-approved' : 'svs-pending', get_post_status( $this->sponsorship_id ) );
		$this->assertSame( $approved ? '0' : '', get_post_meta( $this->sponsorship_id, '_sv_approved_by', true ) );
		$this->assertCount( $emailed ? 1 : 0, get_conversations( $this->sponsorship_id, 'approval' ) );
	}

	/**
	 * Camp sponsors, and whether marking their company Compliant approves them and emails the event.
	 *
	 * @return array[]
	 */
	public function data_auto_approvals(): array {
		return array(
			'no invoice'        => array( 'https://acme.example', '', true, true ),
			'draft invoice'     => array( 'https://acme.example', 'draft', true, true ),
			'submitted invoice' => array( 'https://acme.example', 'wcbsi_submitted', true, false ),
			'another website'   => array( 'https://other.example', '', false, false ),
		);
	}

	/**
	 * Every conversation is kept: a second compliance request adds to the list instead of replacing the first,
	 * which the Zap did.
	 */
	public function test_a_second_conversation_is_added_not_replaced(): void {
		record_conversation( $this->company_id, array( 'id' => 101, 'url' => 'https://freescout.wordpress.net/conversation/101' ), 'compliance', $this->sponsorship_id ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- A short fixture.
		record_conversation( $this->company_id, array( 'id' => 102, 'url' => 'https://freescout.wordpress.net/conversation/102' ), 'compliance', $this->sponsorship_id ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- A short fixture.

		$this->assertSame( array( 101, 102 ), array_column( get_conversations( $this->company_id, 'compliance' ), 'id' ) );
	}

	/**
	 * The reply flag goes up when the sponsor wrote last, whatever order the API lists threads in, and notes don't
	 * count as replies.
	 *
	 * @dataProvider data_conversations
	 *
	 * @param array[] $threads  The conversation's threads, as FreeScout's API returns them.
	 * @param bool    $expected Whether the sponsor wrote last.
	 */
	public function test_sponsor_wrote_last( array $threads, bool $expected ): void {
		$this->assertSame( $expected, sponsor_wrote_last( array( '_embedded' => array( 'threads' => $threads ) ) ) );
	}

	/**
	 * Conversations, and whether the sponsor wrote last.
	 *
	 * @return array[]
	 */
	public function data_conversations(): array {
		$request = array(
			'id'        => 1,
			'type'      => 'message',
			'createdAt' => '2026-10-01T09:00:00Z',
		);
		$reply   = array(
			'id'        => 2,
			'type'      => 'customer',
			'createdAt' => '2026-10-02T09:00:00Z',
		);

		return array(
			'sponsor replied'           => array( array( $request, $reply ), true ),
			'listed newest first'       => array( array( $reply, $request ), true ),
			'agent answered the reply'  => array(
				array(
					$request,
					$reply,
					array(
						'id'        => 3,
						'type'      => 'message',
						'createdAt' => '2026-10-03T09:00:00Z',
					),
				),
				false,
			),
			'note after the reply'      => array(
				array(
					$request,
					$reply,
					array(
						'id'        => 3,
						'type'      => 'note',
						'createdAt' => '2026-10-03T09:00:00Z',
					),
				),
				true,
			),
		);
	}
}
