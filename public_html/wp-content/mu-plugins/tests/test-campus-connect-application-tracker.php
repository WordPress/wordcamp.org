<?php

namespace WordCamp\Tests;

use WordCamp\Tests\Database_TestCase;

defined( 'WPINC' ) || die();

/**
 * Tests for Campus Connect application tracker creation.
 *
 * @group mu-plugins
 * @group campus-connect
 */
class Test_Campus_Connect_Application_Tracker extends Database_TestCase {
	/**
	 * Set up before class.
	 *
	 * @param \WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		clean_network_cache( WORDCAMP_NETWORK_ID );
		clean_network_cache( EVENTS_NETWORK_ID );
		parent::wpSetUpBeforeClass( $factory );
	}

	/**
	 * Original REMOTE_ADDR value before test.
	 *
	 * @var string|null
	 */
	protected $original_remote_addr;

	/**
	 * Set up test environment before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->original_remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;
		$_SERVER['REMOTE_ADDR']     = '127.0.0.1';

		switch_to_blog( EVENTS_ROOT_BLOG_ID );
		require_once dirname( __DIR__ ) . '/events/jetpack-form-to-wcpt-application.php';
		restore_current_blog();
	}

	/**
	 * Tear down after each test.
	 */
	public function tear_down(): void {
		if ( is_null( $this->original_remote_addr ) ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->original_remote_addr;
		}

		parent::tear_down();
	}

	/**
	 * Build mock field object for Jetpack form field submission.
	 *
	 * @param string $label Field label.
	 * @param mixed  $value Field value.
	 * @return object
	 */
	protected function build_mock_field( $label, $value ) {
		return (object) array(
			'attributes' => array(
				'label' => $label,
			),
			'value'      => $value,
		);
	}

	/**
	 * Test that create_campus_connect_tracker() sets 'Running money through WPCS PBC' to true.
	 */
	public function test_create_campus_connect_tracker_sets_running_money_to_true() {
		switch_to_blog( EVENTS_ROOT_BLOG_ID );

		$fields = array(
			'name'      => $this->build_mock_field( 'Name', 'Jane Doe' ),
			'email'     => $this->build_mock_field( 'Email', 'jane@example.com' ),
			'username'  => $this->build_mock_field( 'Username', 'janedoe' ),
			'campus'    => $this->build_mock_field( 'Campus', 'Pacific University' ),
			'city'      => $this->build_mock_field( 'City', 'Forest Grove' ),
			'country'   => $this->build_mock_field( 'Country', 'United States' ),
			'date'      => $this->build_mock_field( 'Date', '2027-05-01' ),
			'attendees' => $this->build_mock_field( 'Number of Attendees', '100' ),
		);

		\WordPress_Community\Applications\JetpackIntegration\create_campus_connect_tracker(
			12345,
			$fields,
			false,
			array( 'entry_permalink' => 'https://events.wordpress.org/campusconnect/organize/' )
		);

		restore_current_blog();

		// Verify on WordCamp Central site where tracker posts live.
		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );

		$posts = get_posts(
			array(
				'post_type'   => 'wordcamp',
				'post_status' => 'any',
				's'           => 'Pacific University',
			)
		);

		$this->assertNotEmpty( $posts, 'Tracker post should be created for Campus Connect application.' );

		$tracker_id = $posts[0]->ID;

		$this->assertSame( 'campusconnect', get_post_meta( $tracker_id, 'event_subtype', true ) );
		$this->assertTrue(
			(bool) get_post_meta( $tracker_id, 'Running money through WPCS PBC', true ),
			'New Campus Connect application tracker must have "Running money through WPCS PBC" set to true.'
		);

		restore_current_blog();
	}
}
