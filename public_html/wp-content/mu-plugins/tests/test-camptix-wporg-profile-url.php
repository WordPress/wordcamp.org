<?php

namespace WordCamp\Tests;

defined( 'WPINC' ) || die();

// The addon loads on CampTix's `camptix_load_addons`, which this suite never fires: CampTix isn't loaded here.
require_once dirname( __DIR__ ) . '/camptix-tweaks/addons/wporg-profile-url.php';

/**
 * Tests for the WordPress.org Profile URL column in the CampTix attendee export.
 *
 * @group mu-plugins
 * @group camptix
 *
 * @package WordCamp\Tests
 */
class Test_CampTix_WPorg_Profile_URL extends Database_TestCase {
	/*
	 * `Database_TestCase` rather than `WP_UnitTestCase` because saving a `tix_attendee` post makes
	 * CampTix look up the WordCamp post on central, and only the former provisions that site.
	 */

	/**
	 * Create an attendee with the given stored username.
	 *
	 * @param string|null $username The value to store in `tix_username`, or null to store nothing.
	 *
	 * @return \WP_Post
	 */
	protected function create_attendee( $username = null ) {
		$attendee = self::factory()->post->create_and_get(
			array( 'post_type' => 'tix_attendee' )
		);

		if ( null !== $username ) {
			update_post_meta( $attendee->ID, 'tix_username', $username );
		}

		return $attendee;
	}

	/**
	 * Get the export value that the column would render for an attendee.
	 *
	 * This mirrors how `CampTix_Plugin::generate_attendee_report()` collects each cell.
	 *
	 * @param \WP_Post $attendee
	 *
	 * @return string
	 */
	protected function get_column_value( $attendee ) {
		return apply_filters( 'camptix_attendee_report_column_value_wporg_profile_url', '', $attendee );
	}

	/**
	 * The column should be offered to the attendee export.
	 */
	public function test_column_is_registered_for_the_export() {
		$columns = apply_filters( 'camptix_attendee_report_extra_columns', array() );

		$this->assertArrayHasKey( 'wporg_profile_url', $columns );
		$this->assertNotEmpty( $columns['wporg_profile_url'] );
	}

	/**
	 * A confirmed attendee should get their profile URL.
	 */
	public function test_returns_profile_url_for_a_confirmed_attendee() {
		self::factory()->user->create( array( 'user_login' => 'ticketholder' ) );

		$attendee = $this->create_attendee( 'ticketholder' );

		$this->assertSame( 'https://wordpress.org/@ticketholder', $this->get_column_value( $attendee ) );
	}

	/**
	 * Profile URLs are keyed on `user_nicename`, which is not always the same as `user_login`.
	 *
	 * @see wc-post-types.php, which builds profile links from `user_nicename`.
	 */
	public function test_uses_nicename_rather_than_login() {
		wp_insert_user(
			array(
				'user_login'    => 'Some_Attendee',
				'user_nicename' => 'some-attendee',
				'user_pass'     => 'irrelevant',
				'user_email'    => 'some-attendee@example.org',
			)
		);

		$attendee = $this->create_attendee( 'Some_Attendee' );

		$this->assertSame( 'https://wordpress.org/@some-attendee', $this->get_column_value( $attendee ) );
	}

	/**
	 * Profile URLs are lowercase.
	 */
	public function test_lowercases_the_url() {
		wp_insert_user(
			array(
				'user_login'    => 'shouty',
				'user_nicename' => 'SHOUTY',
				'user_pass'     => 'irrelevant',
				'user_email'    => 'shouty@example.org',
			)
		);

		$attendee = $this->create_attendee( 'shouty' );

		$this->assertSame( 'https://wordpress.org/@shouty', $this->get_column_value( $attendee ) );
	}

	/**
	 * Tickets bought for someone who hasn't claimed them yet have no known username.
	 *
	 * `CampTix_Require_Login` stores the `[[ unconfirmed ]]` sentinel until the attendee confirms.
	 * That is not a username, so there is no profile to link to.
	 */
	public function test_returns_empty_for_an_unconfirmed_attendee() {
		$attendee = $this->create_attendee( '[[ unconfirmed ]]' );

		$this->assertSame( '', $this->get_column_value( $attendee ) );
	}

	/**
	 * An attendee with no stored username should not produce a URL.
	 */
	public function test_returns_empty_when_no_username_is_stored() {
		$attendee = $this->create_attendee();

		$this->assertSame( '', $this->get_column_value( $attendee ) );
	}

	/**
	 * A username that no longer resolves to an account should not produce a URL.
	 */
	public function test_returns_empty_when_the_user_does_not_exist() {
		$attendee = $this->create_attendee( 'no-such-account-here' );

		$this->assertSame( '', $this->get_column_value( $attendee ) );
	}
}
