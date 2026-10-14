<?php

namespace WordCamp\Tests;

use WP_UnitTestCase;

defined( 'WPINC' ) || die();

if ( ! function_exists( 'wcorg_login_message' ) ) {
	require_once dirname( __DIR__ ) . '/wcorg-login-message.php';
}

/**
 * Tests for the "log in with your WordPress.org account" message.
 *
 * @group mu-plugins
 *
 * @package WordCamp\Tests
 */
class Test_WCOrg_Login_Message extends WP_UnitTestCase {
	/**
	 * Get the `redirect_to` the WordPress.org login link in the message sends the visitor back to.
	 *
	 * @param string $message The rendered message.
	 *
	 * @return string|null Null when the link has no `redirect_to`.
	 */
	protected function get_wporg_login_redirect( $message ) {
		$this->assertSame( 1, preg_match( '#<a href="(https://login\.wordpress\.org[^"]*)">the official WordPress\.org website#', $message, $matches ) );

		wp_parse_str( (string) wp_parse_url( html_entity_decode( $matches[1] ), PHP_URL_QUERY ), $query );

		return $query['redirect_to'] ?? null;
	}

	/**
	 * A return URL with its own query string survives whole, not just up to its first `&`.
	 *
	 * @covers ::wcorg_login_message
	 */
	public function test_wporg_login_link_keeps_the_whole_return_url() {
		$return_url = home_url( '/tickets/?tix_action=attendee_info&tix_tickets_selected%5B12%5D=2&tix_coupon=SAVE10' );

		$this->assertSame( $return_url, $this->get_wporg_login_redirect( wcorg_login_message( '', $return_url ) ) );
	}

	/**
	 * Without a return URL, the link doesn't get an empty `redirect_to`.
	 *
	 * @covers ::wcorg_login_message
	 */
	public function test_wporg_login_link_has_no_redirect_without_a_return_url() {
		$this->assertNull( $this->get_wporg_login_redirect( wcorg_login_message( '' ) ) );
	}
}
