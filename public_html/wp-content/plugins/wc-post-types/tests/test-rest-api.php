<?php

namespace WordCamp\WC_Post_Types\Tests;
use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * @group wc-post-types
 * @group rest-api
 */
class Test_Favorite_Sessions_Email_Validation extends WP_UnitTestCase {
	/**
	 * Ensure the REST route is registered before tests run.
	 */
	public static function wpSetUpBeforeClass(): void {
		\WordCamp\Post_Types\REST_API\register_fav_sessions_email();
	}

	/**
	 * Helper to run only the email-address validate_callback via a REST request.
	 *
	 * @param string $email The email value to validate.
	 *
	 * @return bool Whether the email passed validation.
	 */
	private function validate_email_param( string $email ): bool {
		$routes   = rest_get_server()->get_routes();
		$route    = $routes['/wc-post-types/v1/email-fav-sessions'] ?? array();
		$args     = $route[0]['args'] ?? array();
		$callback = $args['email-address']['validate_callback'] ?? null;

		if ( ! $callback ) {
			$this->fail( 'Could not find validate_callback for email-address parameter.' );
		}

		return (bool) call_user_func( $callback, $email, null, 'email-address' );
	}

	/**
	 * Test that an email with leading and trailing whitespace passes validation.
	 */
	public function test_email_with_whitespace_passes_validation(): void {
		$this->assertTrue( $this->validate_email_param( '  user@example.com  ' ) );
	}

	/**
	 * Test that an email with a trailing space passes validation.
	 */
	public function test_email_with_trailing_space_passes_validation(): void {
		$this->assertTrue( $this->validate_email_param( 'user@example.com ' ) );
	}

	/**
	 * Test that a valid email without whitespace passes validation.
	 */
	public function test_valid_email_passes_validation(): void {
		$this->assertTrue( $this->validate_email_param( 'user@example.com' ) );
	}

	/**
	 * Test that an invalid email fails validation.
	 */
	public function test_invalid_email_fails_validation(): void {
		$this->assertFalse( $this->validate_email_param( 'not-an-email' ) );
	}

	/**
	 * Test that an empty string fails validation.
	 */
	public function test_empty_string_fails_validation(): void {
		$this->assertFalse( $this->validate_email_param( '' ) );
	}

	/**
	 * Test that a string of only whitespace fails validation.
	 */
	public function test_whitespace_only_fails_validation(): void {
		$this->assertFalse( $this->validate_email_param( '   ' ) );
	}
}
