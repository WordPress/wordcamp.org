<?php

namespace WordCamp\QBO\Tests;

use ReflectionMethod;
use ReflectionProperty;
use WordCamp_QBO;
use WP_Error;
use WP_UnitTestCase;
use WP_UnitTest_Factory;

defined( 'WPINC' ) || die();

/**
 * Tests for the payload sent to QuickBooks when a sponsor invoice is approved.
 *
 * Every HTTP call is stubbed, so nothing reaches Intuit.
 *
 * @covers WordCamp_QBO::build_qbo_create_invoice_request
 *
 * @group wordcamp-qbo
 */
class Test_Invoice_Payload extends WP_UnitTestCase {
	/**
	 * The sponsor's billing address. QuickBooks emails the invoice here.
	 */
	const SPONSOR_EMAIL = 'accounts@sponsor.example.org';

	/**
	 * The event's own address, from its WordCamp post on Central.
	 */
	const EVENT_EMAIL = 'testville@wordcamp.example.org';

	/**
	 * The customer ID the stubbed QuickBooks company returns.
	 */
	const QBO_CUSTOMER_ID = 42;

	/**
	 * The current site's WordCamp post on Central.
	 *
	 * @var int
	 */
	protected $wordcamp_id;

	/**
	 * The ID of the root blog, if this class created it.
	 *
	 * @var int|null
	 */
	protected static $created_root_blog = null;

	/**
	 * Create Central, where `get_wordcamp_post()` looks for the event's WordCamp post.
	 *
	 * The multisite test install only creates the default blog. This runs once per class, because creating a
	 * blog isn't transactional.
	 *
	 * @param WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		if ( ! get_site( WORDCAMP_ROOT_BLOG_ID ) ) {
			self::$created_root_blog = $factory->blog->create( array(
				'blog_id'    => WORDCAMP_ROOT_BLOG_ID,
				'network_id' => defined( 'WORDCAMP_NETWORK_ID' ) ? WORDCAMP_NETWORK_ID : 1,
			) );
		}
	}

	/**
	 * Remove the root blog this class created.
	 */
	public static function wpTearDownAfterClass() {
		if ( self::$created_root_blog ) {
			wp_delete_site( self::$created_root_blog );
			self::$created_root_blog = null;
		}
	}

	/**
	 * Set up a WordCamp post for the current site, intercept HTTP, and leave sandbox mode.
	 */
	public function set_up() {
		parent::set_up();

		$this->wordcamp_id = $this->make_wordcamp( self::EVENT_EMAIL );

		add_filter( 'pre_http_request', array( $this, 'stub_quickbooks_http' ), 10, 3 );

		WordCamp_QBO::plugins_loaded();
		$this->set_sandbox_mode( false );
	}

	/**
	 * Remove the HTTP stub and go back to sandbox mode, which is what the test environment runs in.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'stub_quickbooks_http' ), 10 );
		$this->set_sandbox_mode( true );

		parent::tear_down();
	}

	/**
	 * Answer the QuickBooks calls made while building the payload.
	 *
	 * Anything else gets an error, so a new outbound request can't escape the tests.
	 *
	 * @param false|array|WP_Error $preempt
	 * @param array                $args
	 * @param string               $url
	 *
	 * @return array|WP_Error
	 */
	public function stub_quickbooks_http( $preempt, $args, $url ) {
		if ( false !== strpos( $url, '/query' ) ) {
			return $this->http_ok( array(
				'QueryResponse' => array(
					'Customer' => array(
						array(
							'Id'          => (string) self::QBO_CUSTOMER_ID,
							'DisplayName' => 'Test Sponsor Ltd',
							'SyncToken'   => '3',
						),
					),
				),
			) );
		}

		if ( false !== strpos( $url, '/exchangerate' ) ) {
			return $this->http_ok( array( 'ExchangeRate' => array( 'Rate' => 0.9125 ) ) );
		}

		if ( false !== strpos( $url, '/customer' ) ) {
			return $this->http_ok( array( 'Customer' => array( 'Id' => (string) self::QBO_CUSTOMER_ID ) ) );
		}

		return new WP_Error( 'unstubbed_request', "Unstubbed HTTP request: $url" );
	}

	/**
	 * A 200 response in the shape `wp_remote_*()` returns.
	 *
	 * @param array $body
	 *
	 * @return array
	 */
	protected function http_ok( array $body ) {
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Switch the plugin between sandbox and production mode.
	 *
	 * The API host is derived from the same flag, so both are set together.
	 *
	 * @param bool $sandbox
	 */
	protected function set_sandbox_mode( $sandbox ) {
		$mode = new ReflectionProperty( WordCamp_QBO::class, 'sandbox_mode' );
		$mode->setValue( null, (bool) $sandbox );

		$base_url = new ReflectionProperty( WordCamp_QBO::class, 'api_base_url' );
		$base_url->setValue( null, sprintf( 'https://%squickbooks.api.intuit.com', $sandbox ? 'sandbox-' : '' ) );
	}

	/**
	 * Create the current site's WordCamp post on Central.
	 *
	 * @param string $email The event's `E-mail Address`.
	 *
	 * @return int
	 */
	protected function make_wordcamp( $email ) {
		$site_id = get_current_blog_id();

		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
		$wordcamp_id = self::factory()->post->create( array(
			'post_type'   => 'wordcamp',
			'post_status' => 'wcpt-scheduled',
			'post_title'  => 'WordCamp Testville',
		) );
		update_post_meta( $wordcamp_id, '_site_id', $site_id );
		update_post_meta( $wordcamp_id, 'E-mail Address', $email );
		update_post_meta( $wordcamp_id, 'Start Date (YYYY-mm-dd)', strtotime( '2027-03-01' ) );
		restore_current_blog();

		return $wordcamp_id;
	}

	/**
	 * Change the `E-mail Address` on the current site's WordCamp post.
	 *
	 * @param string $email
	 */
	protected function set_event_email( $email ) {
		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
		update_post_meta( $this->wordcamp_id, 'E-mail Address', $email );
		restore_current_blog();
	}

	/**
	 * Create a sponsor with a complete set of billing details.
	 *
	 * @param array $overrides Meta values to replace.
	 *
	 * @return int
	 */
	protected function make_sponsor( array $overrides = array() ) {
		$sponsor_id = self::factory()->post->create( array(
			'post_type'  => 'wcb_sponsor',
			'post_title' => 'Test Sponsor Ltd',
		) );

		$meta = array_merge(
			array(
				'_wcpt_sponsor_company_name'    => 'Test Sponsor Ltd',
				'_wcpt_sponsor_first_name'      => 'Ada',
				'_wcpt_sponsor_last_name'       => 'Example',
				'_wcpt_sponsor_email_address'   => self::SPONSOR_EMAIL,
				'_wcpt_sponsor_phone_number'    => '+1 555 0100',
				'_wcpt_sponsor_street_address1' => '1 Example Way',
				'_wcpt_sponsor_city'            => 'Testville',
				'_wcpt_sponsor_state'           => 'CA',
				'_wcpt_sponsor_zip_code'        => '94107',
				// A full name rather than a code, so `wcorg_get_country_name_from_code()` doesn't need `wp-cldr`.
				'_wcpt_sponsor_country'         => 'United States',
				'_wcpt_sponsor_vat_number'      => 'VAT-TEST-0001',
			),
			$overrides
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $sponsor_id, $key, $value );
		}

		return $sponsor_id;
	}

	/**
	 * Create a sponsor invoice.
	 *
	 * @param int $sponsor_id
	 *
	 * @return int
	 */
	protected function make_invoice( $sponsor_id ) {
		$invoice_id = self::factory()->post->create( array(
			'post_type'  => 'wcb_sponsor_invoice',
			'post_title' => 'Test invoice',
		) );

		$meta = array(
			'_wcbsi_sponsor_id'   => $sponsor_id,
			'_wcbsi_qbo_class_id' => '77',
			'_wcbsi_currency'     => 'USD',
			'_wcbsi_amount'       => '2500.00',
			'_wcbsi_description'  => 'Gold sponsorship for WordCamp Testville',
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $invoice_id, $key, $value );
		}

		return $invoice_id;
	}

	/**
	 * Build the create-invoice request for an invoice.
	 *
	 * @param int $invoice_id
	 *
	 * @return array|WP_Error
	 */
	protected function build_request( $invoice_id ) {
		$method = new ReflectionMethod( WordCamp_QBO::class, 'build_qbo_create_invoice_request' );

		return $method->invoke( null, $invoice_id );
	}

	/**
	 * Build the create-invoice request and return its decoded body.
	 *
	 * @param int $invoice_id
	 *
	 * @return array
	 */
	protected function build_payload( $invoice_id ) {
		$request = $this->build_request( $invoice_id );

		$this->assertNotWPError( $request );

		return json_decode( $request['args']['body'], true );
	}

	/**
	 * The invoice is emailed to the sponsor's billing address.
	 */
	public function test_bill_email_is_the_sponsor_address() {
		$payload = $this->build_payload( $this->make_invoice( $this->make_sponsor() ) );

		$this->assertSame( self::SPONSOR_EMAIL, $payload['BillEmail']['Address'] );
	}

	/**
	 * QBO sandboxes really send email, so sandbox mode replaces the sponsor's address.
	 */
	public function test_bill_email_is_a_placeholder_in_sandbox_mode() {
		$this->set_sandbox_mode( true );

		$payload = $this->build_payload( $this->make_invoice( $this->make_sponsor() ) );

		$this->assertSame( 'jane.doe@example.org', $payload['BillEmail']['Address'] );
	}

	/**
	 * The event's own address is copied on the invoice, so the organizers see what the sponsor got.
	 */
	public function test_event_email_is_copied_on_the_invoice() {
		$payload = $this->build_payload( $this->make_invoice( $this->make_sponsor() ) );

		$this->assertSame( self::EVENT_EMAIL, $payload['BillEmailCc']['Address'] );
		$this->assertArrayNotHasKey( 'BillEmailBcc', $payload );
	}

	/**
	 * Sandbox mode doesn't copy the real organizing team either.
	 */
	public function test_cc_is_a_placeholder_in_sandbox_mode() {
		$this->set_sandbox_mode( true );

		$request = $this->build_request( $this->make_invoice( $this->make_sponsor() ) );
		$this->assertNotWPError( $request );
		$payload = json_decode( $request['args']['body'], true );

		$this->assertSame( 'john.doe@example.org', $payload['BillEmailCc']['Address'] );
		$this->assertStringNotContainsString( self::EVENT_EMAIL, $request['args']['body'] );
	}

	/**
	 * An event without an address still gets its invoice, just without a copy.
	 */
	public function test_no_cc_when_the_event_has_no_email() {
		$this->set_event_email( '' );

		$payload = $this->build_payload( $this->make_invoice( $this->make_sponsor() ) );

		$this->assertSame( self::SPONSOR_EMAIL, $payload['BillEmail']['Address'] );
		$this->assertArrayNotHasKey( 'BillEmailCc', $payload );
	}

	/**
	 * A malformed address is left out rather than sent to QuickBooks, which would reject the invoice.
	 */
	public function test_no_cc_when_the_event_email_is_invalid() {
		$this->set_event_email( 'not an email' );

		$payload = $this->build_payload( $this->make_invoice( $this->make_sponsor() ) );

		$this->assertArrayNotHasKey( 'BillEmailCc', $payload );
	}

	/**
	 * A site with no WordCamp post still gets its invoice, just without a copy.
	 */
	public function test_no_cc_when_the_site_has_no_wordcamp_post() {
		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
		wp_delete_post( $this->wordcamp_id, true );
		restore_current_blog();

		$payload = $this->build_payload( $this->make_invoice( $this->make_sponsor() ) );

		$this->assertSame( self::SPONSOR_EMAIL, $payload['BillEmail']['Address'] );
		$this->assertArrayNotHasKey( 'BillEmailCc', $payload );
	}

	/**
	 * When the sponsor's billing address is the event's address, it isn't copied to itself.
	 */
	public function test_no_cc_when_the_event_email_is_the_sponsor_email() {
		$this->set_event_email( strtoupper( self::SPONSOR_EMAIL ) );

		$payload = $this->build_payload( $this->make_invoice( $this->make_sponsor() ) );

		$this->assertArrayNotHasKey( 'BillEmailCc', $payload );
	}

	/**
	 * The rest of the invoice is unchanged.
	 */
	public function test_payload_carries_the_core_invoice_fields() {
		$payload = $this->build_payload( $this->make_invoice( $this->make_sponsor() ) );

		$this->assertEquals( 2500.00, $payload['Line'][0]['Amount'] );
		$this->assertSame( '77', $payload['Line'][0]['SalesItemLineDetail']['ClassRef']['value'] );
		$this->assertSame( 'WordCamp Testville 2027', $payload['Line'][0]['Description'] );
		$this->assertSame( self::QBO_CUSTOMER_ID, $payload['CustomerRef']['value'] );
		$this->assertSame( 3, $payload['SalesTermRef']['value'] );
		$this->assertSame( 'VAT-TEST-0001', $payload['CustomField'][1]['StringValue'] );
		$this->assertStringContainsString( 'For International Wire Transfers', $payload['CustomerMemo']['value'] );
	}
}
