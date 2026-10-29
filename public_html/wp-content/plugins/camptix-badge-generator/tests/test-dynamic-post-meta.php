<?php

defined( 'WPINC' ) || die();

/**
 * Tests for the QR code values that add_dynamic_post_meta() adds to attendees.
 *
 * @group camptix-badge-generator
 */
class Test_Badge_Dynamic_Post_Meta extends WP_UnitTestCase {
	use CampTix_Root_Blog_Fixture;

	/**
	 * Provision central when the harness points at a blog the test install lacks.
	 *
	 * @param WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		if ( ! get_site( WORDCAMP_ROOT_BLOG_ID ) ) {
			self::create_wordcamp_root_blog( $factory );
		}
	}

	/**
	 * Remove the root blog this class provisioned.
	 */
	public static function wpTearDownAfterClass() {
		if ( self::$wordcamp_root_blog_id ) {
			self::delete_wordcamp_root_blog();

			self::$wordcamp_root_blog_id = null;
		}
	}

	/**
	 * Create an attendee.
	 *
	 * @param string $username Their tix_username, if any.
	 *
	 * @return WP_Post
	 */
	protected function make_attendee( $username = '' ) {
		$attendee_id = self::factory()->post->create( array(
			'post_type'   => 'tix_attendee',
			'post_status' => 'publish',
		) );

		update_post_meta( $attendee_id, 'tix_email', ' Jane@Example.org ' );

		if ( $username ) {
			update_post_meta( $attendee_id, 'tix_username', $username );
		}

		return get_post( $attendee_id );
	}

	/**
	 * @covers \CampTix\Badge_Generator\add_dynamic_post_meta
	 */
	public function test_gravatar_profile_url_uses_normalized_email_hash() {
		$attendee = $this->make_attendee();

		$this->assertSame( 'https://gravatar.com/' . md5( 'jane@example.org' ), $attendee->gravatar_profile_url );
	}

	/**
	 * Profile URLs use the nicename, which differs from the login for some accounts.
	 *
	 * @covers \CampTix\Badge_Generator\add_dynamic_post_meta
	 */
	public function test_wporg_profile_url_uses_nicename() {
		self::factory()->user->create( array(
			'user_login'    => 'Jane Doe',
			'user_nicename' => 'janedoe-1',
		) );

		$attendee = $this->make_attendee( 'Jane Doe' );

		$this->assertSame( 'https://w.org/@janedoe-1', $attendee->wporg_profile_url );
	}

	/**
	 * Data provider for test_wporg_profile_url_empty().
	 *
	 * @return array
	 */
	public function data_wporg_profile_url_empty() {
		return array(
			'no username'          => array( '' ),
			'unconfirmed username' => array( '[[ unconfirmed ]]' ),
			'unknown username'     => array( 'nobody-has-this-login' ),
		);
	}

	/**
	 * @covers \CampTix\Badge_Generator\add_dynamic_post_meta
	 * @dataProvider data_wporg_profile_url_empty
	 *
	 * @param string $username
	 */
	public function test_wporg_profile_url_empty( $username ) {
		$this->assertSame( '', $this->make_attendee( $username )->wporg_profile_url );
	}

	/**
	 * @covers \CampTix\Badge_Generator\add_dynamic_post_meta
	 */
	public function test_qr_code_follows_the_setting() {
		$attendee = $this->make_attendee();

		update_option( 'cbg_qr_code_type', 'none' );
		$this->assertEmpty( $attendee->qr_code_target_url );
		$this->assertSame( '', $attendee->qr_code_svg );

		update_option( 'cbg_qr_code_type', 'gravatar' );
		$this->assertSame( $attendee->gravatar_profile_url, $attendee->qr_code_target_url );
		$this->assertStringStartsWith( '<svg ', $attendee->qr_code_svg );
	}
}
