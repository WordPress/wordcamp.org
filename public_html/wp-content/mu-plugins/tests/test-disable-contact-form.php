<?php

namespace WordCamp\Latest_Site_Hints\Tests;
use WP_UnitTest_Factory;
use WordCamp\Tests\Database_TestCase;

use function WordCamp\Latest_Site_Hints\maybe_disable_contact_form;

defined( 'WPINC' ) || die();

/**
 * @group mu-plugins
 * @group latest-site-hints
 * @group disable-contact-form
 */
class Test_Disable_Contact_Form extends Database_TestCase {
	/**
	 * WordCamp post IDs created during setup, keyed by site ID.
	 *
	 * @var array
	 */
	protected static $wordcamp_post_ids = array();

	/**
	 * Sample contact form HTML used for testing.
	 *
	 * @var string
	 */
	protected static $sample_form_html = '<form><input type="text" name="name" /><input type="submit" /></form>';

	/**
	 * Create WordCamp posts on the central site for our test sites.
	 *
	 * @param WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		parent::wpSetUpBeforeClass( $factory );

		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );

		// Register the `wordcamp` post type if not already registered.
		if ( ! post_type_exists( 'wordcamp' ) ) {
			register_post_type( 'wordcamp' );
		}

		// Past event (2018 Seattle) - has a newer site (2019), ended long ago.
		$past_event_id = wp_insert_post( array(
			'post_type'   => 'wordcamp',
			'post_title'  => 'WordCamp Seattle 2018',
			'post_status' => 'publish',
		) );
		update_post_meta( $past_event_id, '_site_id', self::$year_dot_2018_site_id );
		update_post_meta( $past_event_id, 'End Date (YYYY-mm-dd)', strtotime( '2018-06-15' ) );
		self::$wordcamp_post_ids[ self::$year_dot_2018_site_id ] = $past_event_id;

		// Current/future event (2019 Seattle) - newest site, end date in the future.
		$current_event_id = wp_insert_post( array(
			'post_type'   => 'wordcamp',
			'post_title'  => 'WordCamp Seattle 2019',
			'post_status' => 'publish',
		) );
		update_post_meta( $current_event_id, '_site_id', self::$year_dot_2019_site_id );
		update_post_meta( $current_event_id, 'End Date (YYYY-mm-dd)', time() + YEAR_IN_SECONDS );
		self::$wordcamp_post_ids[ self::$year_dot_2019_site_id ] = $current_event_id;

		// Recent past event (Vancouver 2020) - newest site for city, ended 6 months ago.
		$recent_past_id = wp_insert_post( array(
			'post_type'   => 'wordcamp',
			'post_title'  => 'WordCamp Vancouver 2020',
			'post_status' => 'publish',
		) );
		update_post_meta( $recent_past_id, '_site_id', self::$slash_year_2020_site_id );
		update_post_meta( $recent_past_id, 'End Date (YYYY-mm-dd)', time() - ( 6 * MONTH_IN_SECONDS ) );
		self::$wordcamp_post_ids[ self::$slash_year_2020_site_id ] = $recent_past_id;

		// Old event with no newer site (Vancouver 2016) - ended more than 18 months ago.
		$old_event_id = wp_insert_post( array(
			'post_type'   => 'wordcamp',
			'post_title'  => 'WordCamp Vancouver 2016',
			'post_status' => 'publish',
		) );
		update_post_meta( $old_event_id, '_site_id', self::$slash_year_2016_site_id );
		update_post_meta( $old_event_id, 'End Date (YYYY-mm-dd)', strtotime( '2016-06-15' ) );
		self::$wordcamp_post_ids[ self::$slash_year_2016_site_id ] = $old_event_id;

		// Event with no end date (Japan yearless site).
		$no_date_id = wp_insert_post( array(
			'post_type'   => 'wordcamp',
			'post_title'  => 'WordCamp Japan',
			'post_status' => 'publish',
		) );
		update_post_meta( $no_date_id, '_site_id', self::$yearless_site_id );
		self::$wordcamp_post_ids[ self::$yearless_site_id ] = $no_date_id;

		restore_current_blog();
	}

	/**
	 * Clean up WordCamp posts created during setup.
	 */
	public static function wpTearDownAfterClass() {
		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );

		foreach ( self::$wordcamp_post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		restore_current_blog();

		parent::wpTearDownAfterClass();
	}

	/**
	 * Switch to the given blog and update the $current_blog global.
	 *
	 * `switch_to_blog()` does not update `$current_blog`, but
	 * `maybe_disable_contact_form()` reads from it directly.
	 *
	 * @param int $blog_id The blog ID to switch to.
	 */
	protected function switch_to_blog_with_globals( $blog_id ) {
		global $current_blog;

		switch_to_blog( $blog_id );

		$current_blog = get_site( $blog_id );
	}

	/**
	 * Restore the previous blog and its $current_blog global.
	 */
	protected function restore_blog_with_globals() {
		global $current_blog;

		restore_current_blog();

		$current_blog = get_site( get_current_blog_id() );
	}

	/**
	 * Test that contact forms are disabled when the event has a newer site.
	 *
	 * @covers WordCamp\Latest_Site_Hints\maybe_disable_contact_form
	 */
	public function test_past_event_with_newer_site_disables_form() {
		$this->switch_to_blog_with_globals( self::$year_dot_2018_site_id );

		$result = maybe_disable_contact_form( self::$sample_form_html );

		$this->assertStringContainsString( 'wordcamp-contact-form-disabled', $result );
		$this->assertStringContainsString( 'is over', $result );
		$this->assertStringContainsString( 'the next edition', $result );
		$this->assertStringNotContainsString( '<form', $result );

		$this->restore_blog_with_globals();
	}

	/**
	 * Test that contact forms are not disabled for current/future events.
	 *
	 * @covers WordCamp\Latest_Site_Hints\maybe_disable_contact_form
	 */
	public function test_current_event_keeps_form() {
		$this->switch_to_blog_with_globals( self::$year_dot_2019_site_id );

		$result = maybe_disable_contact_form( self::$sample_form_html );

		$this->assertSame( self::$sample_form_html, $result );

		$this->restore_blog_with_globals();
	}

	/**
	 * Test that contact forms remain active for recent past events without a newer site.
	 *
	 * The Vancouver 2020 site is the newest for that city and ended only 6 months ago,
	 * so neither the newer-site nor the 18-month expiry condition is met.
	 *
	 * @covers WordCamp\Latest_Site_Hints\maybe_disable_contact_form
	 */
	public function test_recent_past_event_without_newer_site_keeps_form() {
		$this->switch_to_blog_with_globals( self::$slash_year_2020_site_id );

		$result = maybe_disable_contact_form( self::$sample_form_html );

		$this->assertSame( self::$sample_form_html, $result );

		$this->restore_blog_with_globals();
	}

	/**
	 * Test that contact forms are disabled when event ended more than 18 months ago.
	 *
	 * Vancouver 2016 has newer sites (2018-developers, 2020), so it will be disabled
	 * via the newer-site path regardless, but the 18-month expiry also applies.
	 *
	 * @covers WordCamp\Latest_Site_Hints\maybe_disable_contact_form
	 */
	public function test_old_past_event_disables_form() {
		$this->switch_to_blog_with_globals( self::$slash_year_2016_site_id );

		$result = maybe_disable_contact_form( self::$sample_form_html );

		$this->assertStringContainsString( 'wordcamp-contact-form-disabled', $result );
		$this->assertStringNotContainsString( '<form', $result );

		$this->restore_blog_with_globals();
	}

	/**
	 * Test that the disabled message includes a Code of Conduct reporting link.
	 *
	 * @covers WordCamp\Latest_Site_Hints\maybe_disable_contact_form
	 */
	public function test_disabled_form_includes_coc_message() {
		$this->switch_to_blog_with_globals( self::$year_dot_2018_site_id );

		$result = maybe_disable_contact_form( self::$sample_form_html );

		$this->assertStringContainsString( 'Code of Conduct', $result );
		$this->assertStringContainsString( 'reports@wordpress.org', $result );

		$this->restore_blog_with_globals();
	}

	/**
	 * Test that contact forms are not modified when there is no end date set.
	 *
	 * When the end date is missing, the event is not treated as expired, and
	 * the Japan yearless site has no newer site via domain pattern matching.
	 *
	 * @covers WordCamp\Latest_Site_Hints\maybe_disable_contact_form
	 */
	public function test_no_end_date_keeps_form() {
		$this->switch_to_blog_with_globals( self::$yearless_site_id );

		$result = maybe_disable_contact_form( self::$sample_form_html );

		$this->assertSame( self::$sample_form_html, $result );

		$this->restore_blog_with_globals();
	}
}
