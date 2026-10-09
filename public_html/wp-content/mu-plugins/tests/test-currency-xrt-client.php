<?php

namespace WordCamp\Tests;

use WP_Error;
use WP_UnitTestCase;
use WordCamp\Utilities\Currency_XRT_Client;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/utilities/class-currency-xrt-client.php'; // The mu-plugins autoloader isn't loaded in tests.

/**
 * Tests for the errors `Currency_XRT_Client` returns.
 *
 * The reports keep one client for a whole run and convert currency after currency, skipping
 * `unknown_currency` and surfacing anything else. That only works if each call's error describes
 * that call alone.
 *
 * @group mu-plugins
 * @group utilities
 *
 * @package WordCamp\Tests
 */
class Test_Currency_XRT_Client extends WP_UnitTestCase {
	/**
	 * What the rates API answers for each historical date: an array of rates, or `false` for a
	 * response without rates.
	 *
	 * @var array
	 */
	protected $answers = array();

	/**
	 * Answer the rates API locally.
	 */
	public function set_up() {
		parent::set_up();

		$this->answers = array();
		add_filter( 'pre_http_request', array( $this, 'answer_rates_request' ), 10, 3 );
	}

	/**
	 * Stop answering the rates API.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'answer_rates_request' ), 10 );

		parent::tear_down();
	}

	/**
	 * Answer an Open Exchange Rates request from `$this->answers`.
	 *
	 * Every answer is a 200, so `wcorg_redundant_remote_get()` never sleeps between retries. A
	 * date mapped to `false` gets a body without rates, which the client treats as a failure.
	 *
	 * @param false|array $preempt Whether to preempt the request.
	 * @param array       $args    Request arguments.
	 * @param string      $url     Request URL.
	 *
	 * @return false|array
	 */
	public function answer_rates_request( $preempt, $args, $url ) {
		if ( ! preg_match( '#openexchangerates\.org/api/historical/(\d{4}-\d{2}-\d{2})\.json#', $url, $match ) ) {
			return $preempt;
		}

		$rates = $this->answers[ $match[1] ] ?? false;
		$body  = false === $rates ? array( 'unexpected' => true ) : array( 'rates' => $rates );

		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * An unknown currency is reported as `unknown_currency`, which the reports skip.
	 */
	public function test_unknown_currency_is_reported_as_unknown_currency() {
		$this->answers['2024-06-01'] = array( 'EUR' => 0.8 );

		$result = ( new Currency_XRT_Client() )->convert( 10, 'XYZ', '2024-06-01' );

		$this->assertWPError( $result );
		$this->assertSame( 'unknown_currency', $result->get_error_code() );
	}

	/**
	 * A known currency converts to the base currency.
	 */
	public function test_convert_returns_the_amount_in_the_base_currency() {
		$this->answers['2024-06-01'] = array( 'EUR' => 0.8 );

		$result = ( new Currency_XRT_Client() )->convert( 100, 'EUR', '2024-06-01' );

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the client keys its result by currency code.
		$this->assertEqualsWithDelta( 125.0, $result->USD, 0.001 );
	}

	/**
	 * Rates fetched for a new date are returned, even after an earlier unknown currency.
	 *
	 * The client kept one error object and returned it from every later fetch once it held any
	 * message, so one unsupported currency made every later date fail too.
	 */
	public function test_a_successful_fetch_after_an_unknown_currency_returns_rates() {
		$this->answers['2024-06-01'] = array( 'EUR' => 0.8 );
		$this->answers['2024-09-01'] = array( 'EUR' => 0.9 );
		$client                      = new Currency_XRT_Client();

		$client->convert( 10, 'XYZ', '2024-06-01' );
		$rates = $client->get_rates( '2024-09-01' );

		$this->assertSame( array( 'EUR' => 0.9 ), $rates );
	}

	/**
	 * Rates fetched for a new date are returned, even after an earlier failed fetch.
	 */
	public function test_a_successful_fetch_after_a_failed_fetch_returns_rates() {
		$this->answers['2024-06-01'] = false;
		$this->answers['2024-09-01'] = array( 'EUR' => 0.9 );
		$client                      = new Currency_XRT_Client();

		$this->assertWPError( $client->get_rates( '2024-06-01' ) );
		$this->assertSame( array( 'EUR' => 0.9 ), $client->get_rates( '2024-09-01' ) );
	}

	/**
	 * A failure is reported under its own code, not under an earlier error's.
	 *
	 * The reports skip `unknown_currency` and surface anything else. With one shared error object,
	 * a failed fetch after an unknown currency came back with `unknown_currency` as its first code,
	 * so the reports silently dropped it.
	 */
	public function test_a_failure_is_reported_under_its_own_code() {
		$this->answers['2024-06-01'] = array( 'EUR' => 0.8 );
		$this->answers['2024-09-01'] = false;
		$client                      = new Currency_XRT_Client();

		$client->convert( 10, 'XYZ', '2024-06-01' );
		$result = $client->convert( 10, 'EUR', '2024-09-01' );

		$this->assertWPError( $result );
		$this->assertSame( 'unexpected_response_data', $result->get_error_code() );
	}

	/**
	 * Each error holds only its own message, so a report merging them doesn't repeat earlier ones.
	 */
	public function test_each_error_holds_only_its_own_message() {
		$this->answers['2024-06-01'] = array( 'EUR' => 0.8 );
		$client                      = new Currency_XRT_Client();

		$client->convert( 10, 'XYZ', '2024-06-01' );
		$result = $client->convert( 10, 'ABC', '2024-06-01' );

		$this->assertSame( array( 'ABC is not an available currency to convert from.' ), $result->get_error_messages() );
	}

	/**
	 * Guard: the public `$error` property still collects every error the client met.
	 */
	public function test_the_client_still_collects_every_error() {
		$this->answers['2024-06-01'] = array( 'EUR' => 0.8 );
		$this->answers['2024-09-01'] = false;
		$client                      = new Currency_XRT_Client();

		$client->convert( 10, 'XYZ', '2024-06-01' );
		$client->get_rates( '2024-09-01' );

		$this->assertInstanceOf( WP_Error::class, $client->error );
		$this->assertSame( array( 'unknown_currency', 'unexpected_response_data' ), $client->error->get_error_codes() );
	}
}
