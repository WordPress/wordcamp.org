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
use function WordCamp\Sponsor_Vetting\freescout_available;
use function WordCamp\Sponsor_Vetting\get_conversations;
use function WordCamp\Sponsor_Vetting\needs_approval_email;
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
	 * Fakes, through `pre_http_request`, the three FreeScout API calls `FreeScout::send()` makes: looking up the
	 * sending agent (`GET /api/users`), creating the conversation (`POST /api/conversations`), and tagging it
	 * (`PUT /api/conversations/{id}/tags`). The responses follow the API's shape.
	 */
	public function test_sending_the_compliance_request_records_the_conversation(): void {
		// CI installs the client, so missing it there is a broken setup, not a reason to lose this test quietly.
		if ( ! freescout_available() && getenv( 'GITHUB_ACTIONS' ) ) {
			$this->fail( 'The FreeScout client from wporg-mu-plugins didn’t load: check that `composer install` put pub-sync/utilities/class-freescout.php in place.' );
		}

		if ( ! freescout_available() ) {
			$this->markTestSkipped( 'The FreeScout client from wporg-mu-plugins isn’t installed.' );
		}

		update_post_meta( $this->company_id, '_sv_gpl_findings', 'The **theme shop** sells themes under a non-GPL license.' );
		wp_set_current_user( self::factory()->user->create( array( 'user_email' => 'vetter@wordcamp.test' ) ) );

		$requests = array();
		$fake     = static function ( mixed $response, array $args, string $url ) use ( &$requests ): mixed {
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

		add_filter( 'pre_http_request', $fake, 10, 3 );
		$conversation = send_compliance_request( $this->company_id, $this->sponsorship_id );
		remove_filter( 'pre_http_request', $fake, 10 );

		$this->assertIsArray( $conversation );
		$this->assertSame( array( 'GET users', 'POST conversations', 'PUT conversations/12345/tags' ), array_column( $requests, 'request' ) );
		$this->assertSame( array( 'seattle@wordcamp.test' ), $requests[1]['body']['threads'][0]['cc'] );
		$this->assertSame( array( 'sponsor-vetting', 'sponsor-infringing' ), $requests[2]['body']['tags'] );
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
	 * The approval email goes to events that haven't invoiced the sponsor; approving an invoice already tells them.
	 *
	 * @dataProvider data_invoice_statuses
	 *
	 * @param string $invoice_status The camp's invoice for the sponsor, or '' for none.
	 * @param bool   $expected       Whether the approval email is needed.
	 */
	public function test_approval_email_only_without_an_invoice( string $invoice_status, bool $expected ): void {
		wp_update_post(
			array(
				'ID'          => $this->company_id,
				'post_status' => 'sv-compliant',
			)
		);
		wp_update_post(
			array(
				'ID'          => $this->sponsorship_id,
				'post_status' => 'svs-approved',
			)
		);

		if ( '' !== $invoice_status ) {
			switch_to_blog( self::$year_dot_2018_site_id );
			$invoice_id = $this->invoice_sponsor( $this->sponsor_id, $invoice_status );
			$this->assertSame( $invoice_status, get_post_status( $invoice_id ) );
			restore_current_blog();
		}

		$this->assertSame( 'svs-approved', get_post_status( $this->sponsorship_id ) );
		$this->assertSame( $expected, needs_approval_email( $this->sponsorship_id ) );
	}

	/**
	 * Invoice statuses, and whether the approval email is needed.
	 *
	 * @return array[]
	 */
	public function data_invoice_statuses(): array {
		return array(
			'no invoice'        => array( '', true ),
			'draft invoice'     => array( 'draft', true ),
			'submitted invoice' => array( 'wcbsi_submitted', false ),
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
