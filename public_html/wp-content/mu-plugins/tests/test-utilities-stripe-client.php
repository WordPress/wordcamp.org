<?php
/**
 * Tests for the lean sponsor-payment Stripe client.
 */

declare( strict_types = 1 );

namespace WordCamp\Utilities\Tests;

use WP_UnitTestCase;
use WordCamp\Utilities\Stripe_Client;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/utilities/class-stripe-client.php';

/**
 * Every request the client sends must carry the pinned API version.
 *
 * Without the header Stripe falls back to the account's default version, which it rate-limits once it is
 * more than seven years old.
 *
 * @group mu-plugins
 * @group utilities
 */
class Test_Utilities_Stripe_Client extends WP_UnitTestCase {
	/** @var array The arguments of every request the stubbed HTTP layer received. */
	protected array $requests = array();

	/** @var callable The `pre_http_request` stub, kept so exactly this callback is removed again. */
	protected $http_stub;

	/**
	 * Capture every outgoing request instead of letting it reach Stripe.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->requests  = array();
		$this->http_stub = function ( $preempt, array $parsed_args, string $url ): array {
			$this->requests[] = array_merge( $parsed_args, array( 'url' => $url ) );

			return array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'body'     => wp_json_encode( array( 'id' => 'cs_test' ) ),
				'headers'  => array(),
			);
		};

		add_filter( 'pre_http_request', $this->http_stub, 10, 3 );
	}

	/**
	 * Stop capturing requests.
	 */
	public function tear_down(): void {
		remove_filter( 'pre_http_request', $this->http_stub, 10 );

		parent::tear_down();
	}

	/**
	 * @covers \WordCamp\Utilities\Stripe_Client::create_session
	 */
	public function test_create_session_pins_the_api_version(): void {
		$client = new Stripe_Client( 'sk_test_secret' );

		$client->create_session(
			array(
				'mode'       => 'payment',
				'line_items' => array(
					array(
						'quantity'   => 1,
						'price_data' => array(
							'currency'    => 'usd',
							'unit_amount' => 1000,
						),
					),
				),
			)
		);

		$this->assertCount( 1, $this->requests );
		$this->assertSame( Stripe_Client::API_URL . '/v1/checkout/sessions', $this->requests[0]['url'] );
		$this->assertSame( Stripe_Client::API_VERSION, $this->requests[0]['headers']['Stripe-Version'] );
	}

	/**
	 * @covers \WordCamp\Utilities\Stripe_Client::retrieve_session
	 */
	public function test_retrieve_session_pins_the_api_version(): void {
		$client = new Stripe_Client( 'sk_test_secret' );

		$client->retrieve_session( 'cs_test' );

		$this->assertCount( 1, $this->requests );
		$this->assertSame( Stripe_Client::API_URL . '/v1/checkout/sessions/cs_test', $this->requests[0]['url'] );
		$this->assertSame( Stripe_Client::API_VERSION, $this->requests[0]['headers']['Stripe-Version'] );
	}
}
