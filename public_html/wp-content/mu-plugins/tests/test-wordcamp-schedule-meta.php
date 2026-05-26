<?php

namespace WordCamp\Schedule_Meta\Tests;
use WP_UnitTest_Factory;
use WordCamp\Tests\Database_TestCase;

use function WordCamp\Schedule_Meta\sync_wordcamp_schedule_meta;

defined( 'WPINC' ) || die();

/**
 * @group mu-plugins
 * @group schedule-meta
 */
class Test_WordCamp_Schedule_Meta extends Database_TestCase {
	/**
	 * Create a `wordcamp` post on the central blog, linked to the given site, with the given dates/status.
	 *
	 * @return int The new post ID.
	 */
	protected function create_wordcamp( $site_id, $status, $start_date, $end_date = 0 ) {
		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );

		$meta = array(
			'_site_id' => $site_id,

			// `meta_input` is applied before the status transition fires, so this short-circuits wcpt's
			// "WordCamp scheduled" Slack notification (which would otherwise run during the test).
			'sent_scheduled_notification' => true,
		);

		if ( $start_date ) {
			$meta['Start Date (YYYY-mm-dd)'] = $start_date;
		}

		if ( $end_date ) {
			$meta['End Date (YYYY-mm-dd)'] = $end_date;
		}

		$post_id = wp_insert_post( array(
			'post_type'   => 'wordcamp',
			'post_title'  => 'WordCamp Test',
			'post_status' => $status,
			'meta_input'  => $meta,
		) );

		restore_current_blog();

		return $post_id;
	}

	/**
	 * Remove a `wordcamp` post and the schedule meta on a site, so tests don't leak into each other.
	 */
	protected function cleanup( $post_id, $site_id ) {
		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
		wp_delete_post( $post_id, true );
		restore_current_blog();

		delete_site_meta( $site_id, '_wc_event_start' );
		delete_site_meta( $site_id, '_wc_event_end' );
		delete_site_meta( $site_id, '_wc_event_status' );
	}

	/**
	 * @covers WordCamp\Schedule_Meta\sync_wordcamp_schedule_meta
	 */
	public function test_writes_dates_for_live_camp() {
		$site_id = self::$year_dot_2019_site_id;
		$start   = strtotime( '2025-06-01' );
		$end     = strtotime( '2025-06-02' );
		$post_id = $this->create_wordcamp( $site_id, 'wcpt-scheduled', $start, $end );

		sync_wordcamp_schedule_meta( $post_id );

		$this->assertSame( $start, (int) get_site_meta( $site_id, '_wc_event_start', true ) );
		$this->assertSame( $end, (int) get_site_meta( $site_id, '_wc_event_end', true ) );
		$this->assertSame( 'wcpt-scheduled', get_site_meta( $site_id, '_wc_event_status', true ) );

		$this->cleanup( $post_id, $site_id );
	}

	/**
	 * @covers WordCamp\Schedule_Meta\sync_wordcamp_schedule_meta
	 */
	public function test_end_date_falls_back_to_start_date() {
		$site_id = self::$year_dot_2019_site_id;
		$start   = strtotime( '2025-06-01' );
		$post_id = $this->create_wordcamp( $site_id, 'wcpt-scheduled', $start );

		sync_wordcamp_schedule_meta( $post_id );

		$this->assertSame( $start, (int) get_site_meta( $site_id, '_wc_event_end', true ) );

		$this->cleanup( $post_id, $site_id );
	}

	/**
	 * @covers WordCamp\Schedule_Meta\sync_wordcamp_schedule_meta
	 *
	 * A camp that's scheduled and then cancelled keeps its dates on the post, but must not be advertised as a
	 * live edition, so its date meta is removed.
	 */
	public function test_removes_dates_for_cancelled_camp() {
		$site_id = self::$year_dot_2019_site_id;
		$start   = strtotime( '2025-06-01' );
		$post_id = $this->create_wordcamp( $site_id, 'wcpt-scheduled', $start, $start );

		sync_wordcamp_schedule_meta( $post_id );
		$this->assertNotEmpty( get_site_meta( $site_id, '_wc_event_end', true ) );

		// Cancel it (dates remain on the post).
		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
		wp_update_post( array(
			'ID'          => $post_id,
			'post_status' => 'wcpt-cancelled',
		) );
		restore_current_blog();

		sync_wordcamp_schedule_meta( $post_id );

		$this->assertSame( '', get_site_meta( $site_id, '_wc_event_start', true ) );
		$this->assertSame( '', get_site_meta( $site_id, '_wc_event_end', true ) );
		$this->assertSame( 'wcpt-cancelled', get_site_meta( $site_id, '_wc_event_status', true ) );

		$this->cleanup( $post_id, $site_id );
	}

	/**
	 * @covers WordCamp\Schedule_Meta\sync_wordcamp_schedule_meta
	 *
	 * Pre-planning camps can have a tentative Start Date saved but aren't on the official schedule yet, so
	 * their dates must not be advertised as a live edition.
	 */
	public function test_does_not_write_dates_for_unscheduled_camp() {
		$site_id = self::$year_dot_2019_site_id;
		$start   = strtotime( '2025-06-01' );
		$post_id = $this->create_wordcamp( $site_id, 'wcpt-needs-schedule', $start, $start );

		sync_wordcamp_schedule_meta( $post_id );

		$this->assertSame( '', get_site_meta( $site_id, '_wc_event_start', true ) );
		$this->assertSame( '', get_site_meta( $site_id, '_wc_event_end', true ) );
		$this->assertSame( 'wcpt-needs-schedule', get_site_meta( $site_id, '_wc_event_status', true ) );

		$this->cleanup( $post_id, $site_id );
	}

	/**
	 * @covers WordCamp\Schedule_Meta\sync_wordcamp_schedule_meta
	 */
	public function test_skips_camp_without_linked_site() {
		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
		$post_id = wp_insert_post( array(
			'post_type'   => 'wordcamp',
			'post_title'  => 'Unlinked Camp',
			'post_status' => 'wcpt-needs-site',
		) );
		update_post_meta( $post_id, 'Start Date (YYYY-mm-dd)', strtotime( '2025-06-01' ) );
		restore_current_blog();

		// Nothing to write to, and crucially no fatal/warning.
		sync_wordcamp_schedule_meta( $post_id );

		$this->assertSame( '', get_site_meta( self::$year_dot_2019_site_id, '_wc_event_start', true ) );

		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
		wp_delete_post( $post_id, true );
		restore_current_blog();
	}
}
