<?php

namespace WordCamp\Tests;

use WP_UnitTestCase;
use function WordCamp\Theme_Templates\get_offline_page;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/theme-templates/bootstrap.php';

/**
 * Tests for the page the offline template is built from.
 *
 * Unlike a listing, this page is pre-cached by the service worker and shown
 * with no connection, so a password-protected one is passed over in favour of
 * the default offline message.
 *
 * @group blocks
 */
class Test_Theme_Templates_Offline extends WP_UnitTestCase {
	/**
	 * Create a published page flagged as the offline page.
	 *
	 * @param array $args Extra arguments for the page.
	 * @return int The new page's ID.
	 */
	private function create_offline_page( array $args = array() ): int {
		$page_id = self::factory()->post->create(
			array_merge(
				array(
					'post_type'   => 'page',
					'post_status' => 'publish',
					'post_title'  => 'Offline',
				),
				$args
			)
		);

		update_post_meta( $page_id, 'wc_page_offline', 'yes' );

		return $page_id;
	}

	/**
	 * The ordinary case still resolves.
	 */
	public function test_returns_the_offline_page() {
		$page_id = $this->create_offline_page();

		$this->assertSame( $page_id, get_offline_page()->ID );
	}

	/**
	 * A password-protected offline page is not used, so `get_offline_content()`
	 * falls back to the default message rather than pre-caching a password
	 * form that could not be submitted with no connection.
	 */
	public function test_skips_a_password_protected_offline_page() {
		$this->create_offline_page( array( 'post_password' => 'hunter2' ) );

		$this->assertFalse( get_offline_page() );
	}

	/**
	 * With both, the usable one is chosen rather than the protected one that
	 * happens to be older.
	 */
	public function test_prefers_an_unprotected_offline_page() {
		$this->create_offline_page( array(
			'post_password' => 'hunter2',
			'post_date'     => '2020-01-01 00:00:00',
		) );
		$usable_id = $this->create_offline_page( array( 'post_date' => '2021-01-01 00:00:00' ) );

		$this->assertSame( $usable_id, get_offline_page()->ID );
	}
}
