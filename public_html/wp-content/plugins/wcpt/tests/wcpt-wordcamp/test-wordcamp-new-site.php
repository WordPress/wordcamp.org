<?php

namespace WordCamp\WCPT\Tests;

use WordCamp\Tests\Database_TestCase;
use WordCamp_New_Site;
use WPDieException;

defined( 'WPINC' ) || die();

/**
 * Tests for `WordCamp_New_Site`.
 *
 * Extends `Database_TestCase` because most of the class resolves real sites, so it needs the mock network.
 *
 * @group wcpt
 */
class Test_WordCamp_New_Site extends Database_TestCase {
	/**
	 * The instance under test.
	 *
	 * @var WordCamp_New_Site
	 */
	protected $new_site;

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		$this->new_site = new WordCamp_New_Site();

		// Turn `wp_die()` into a catchable exception, so that rejected saves can be asserted on.
		add_filter(
			'wp_die_handler',
			static function () {
				return static function ( $message ) {
					throw new WPDieException( esc_html( $message ) );
				};
			}
		);
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		global $wcorg_subroles;

		unset( $_POST['wcpt_url'], $_POST['wcpt_secondary_site'] );
		$wcorg_subroles = array();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Create an event post with the given meta.
	 *
	 * `publish` stands in for a `wcpt-` status because those aren't registered here, and the status is irrelevant.
	 *
	 * @param array $meta
	 *
	 * @return int
	 */
	protected function create_event( array $meta = array() ) {
		$post_id = self::factory()->post->create( array(
			'post_type'   => WCPT_POST_TYPE_ID,
			'post_status' => 'publish',
		) );

		foreach ( $meta as $key => $value ) {
			add_post_meta( $post_id, $key, $value );
		}

		return $post_id;
	}

	/**
	 * Move an event to the trash, which `WordCamp_Status_Guard` only lets a wrangler do.
	 *
	 * Asserts the move landed, because an untrashed fixture still claims its URL and still
	 * counts, so a test named for trashed behaviour would pass without testing any.
	 *
	 * @param int $post_id
	 *
	 * @return int The same post ID.
	 */
	protected function trash_as_wrangler( $post_id ) {
		global $wcorg_subroles;

		$previous_user     = get_current_user_id();
		$previous_subroles = $wcorg_subroles;

		$this->become_wrangler();
		wp_trash_post( $post_id );

		$wcorg_subroles = $previous_subroles;
		wp_set_current_user( $previous_user );

		$this->assertSame( 'trash', get_post_status( $post_id ), 'The fixture was not trashed.' );

		return $post_id;
	}

	/**
	 * Switch the current user to a WordCamp wrangler.
	 *
	 * The capability has to come through the subroles system: `omit_usermeta_caps()` deliberately strips
	 * anything granted with `WP_User::add_cap()`.
	 */
	protected function become_wrangler() {
		global $wcorg_subroles;

		$wrangler = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$wcorg_subroles = array( $wrangler => array( 'wordcamp_wrangler' ) );
		wp_set_current_user( $wrangler );
	}

	/**
	 * Save the primary URL field, reporting whether it was rejected.
	 *
	 * @param int    $wordcamp_id
	 * @param string $url
	 *
	 * @return bool `true` if the save was rejected.
	 */
	protected function save_url( $wordcamp_id, $url ) {
		$_POST['wcpt_url'] = $url;

		try {
			$this->new_site->save_site_url_field( 'URL', 'wc-url', $wordcamp_id );
		} catch ( WPDieException $exception ) {
			return true;
		}

		return false;
	}

	/**
	 * @covers WordCamp_New_Site::url_matches_expected_format
	 *
	 * @dataProvider data_url_matches_expected_format
	 */
	public function test_url_matches_expected_format( $domain, $path, $wordcamp_id, $expected ) {
		$actual = WordCamp_New_Site::url_matches_expected_format( $domain, $path, $wordcamp_id );

		$this->assertSame( $expected, $actual );
	}

	/**
	 * Test cases for test_url_matches_expected_format().
	 *
	 * @return array
	 */
	public function data_url_matches_expected_format() {
		return array(
			'old sites can have external domains' => array(
				'wordcampchicago.com',
				'/',
				590,
				true,
			),

			'newer exceptions can have external domains' => array(
				'2012.vancouver.buddypress.org',
				'/',
				169459,
				true,
			),

			"newer sites can't have external domains" => array(
				'wordcampsingapore2011.org',
				'/',
				2342,
				false,
			),

			'old internal sites should not have the year.city format' => array(
				'2011.jabalpur.wordcamp.test',
				'/',
				2340,
				false,
			),

			'newer internal sites should not have the year.city format' => array(
				'2011.jabalpur.wordcamp.test',
				'/',
				2342,
				false,
			),

			'old internal sites should have the city/year format' => array(
				'jabalpur.wordcamp.test',
				'/2011/',
				2340,
				true,
			),

			'newer internal sites should have the city/year format' => array(
				'jabalpur.wordcamp.test',
				'/2011/',
				2342,
				true,
			),

			'events.wordpress.org url are also valid' => array(
				'events.wordpress.test',
				'/rome/2023/training/',
				2342,
				true,
			),
		);
	}

	/**
	 * A URL for a site that doesn't exist yet is accepted.
	 *
	 * Events are always given a URL before their site is created, so this is the normal case.
	 *
	 * @covers WordCamp_New_Site::save_site_url_field
	 *
	 * @dataProvider data_save_site_url_field_accepts_url_for_a_site_that_does_not_exist_yet
	 *
	 * @param string $url
	 */
	public function test_save_site_url_field_accepts_url_for_a_site_that_does_not_exist_yet( $url ) {
		$event = $this->create_event();

		$rejected = $this->save_url( $event, $url );

		$this->assertFalse( $rejected );
		$this->assertSame( $url, get_post_meta( $event, 'URL', true ) );
		$this->assertSame( '', get_post_meta( $event, '_site_id', true ) );
	}

	/**
	 * Test cases for `test_save_site_url_field_accepts_url_for_a_site_that_does_not_exist_yet()`.
	 *
	 * The last two share a domain with an existing site, which no check may treat as a reason to reject them.
	 *
	 * @return array
	 */
	public function data_save_site_url_field_accepts_url_for_a_site_that_does_not_exist_yet() {
		return array(
			'unused domain'         => array( 'https://barcelona.wordcamp.test/2026/' ),
			'events network'        => array( 'https://events.wordpress.test/paris/2027/training/' ),
			'domain with root site' => array( 'https://japan.wordcamp.test/2099/' ),
		);
	}

	/**
	 * A wrangler can point an event at a site that already exists, and both meta values are stored.
	 *
	 * Also a canary: if the handler were bailing out early, the rejection tests would pass for the wrong reason.
	 *
	 * @covers WordCamp_New_Site::save_site_url_field
	 */
	public function test_save_site_url_field_accepts_existing_site_for_a_wrangler() {
		$this->become_wrangler();

		$event    = $this->create_event();
		$rejected = $this->save_url( $event, 'https://vancouver.wordcamp.test/2020/' );

		$this->assertFalse( $rejected );
		$this->assertSame( 'https://vancouver.wordcamp.test/2020/', get_post_meta( $event, 'URL', true ) );
		$this->assertEquals( self::$slash_year_2020_site_id, get_post_meta( $event, '_site_id', true ) );
	}

	/**
	 * A URL that doesn't match the expected format is rejected.
	 *
	 * @covers WordCamp_New_Site::save_site_url_field
	 */
	public function test_save_site_url_field_rejects_malformed_url() {
		$event = $this->create_event();

		$this->assertTrue( $this->save_url( $event, 'https://2020.vancouver.wordcamp.test/' ) );
		$this->assertSame( '', get_post_meta( $event, 'URL', true ) );
	}

	/**
	 * A site that exists is off limits even when no event post claims it.
	 *
	 * Plenty of sites on the network aren't claimed, so checking for another event's claim isn't enough.
	 *
	 * @covers WordCamp_New_Site::save_site_url_field
	 */
	public function test_save_site_url_field_rejects_an_existing_site_that_no_event_claims() {
		$event = $this->create_event();

		$this->assertTrue( $this->save_url( $event, 'https://vancouver.wordcamp.test/2020/' ) );
		$this->assertSame( '', get_post_meta( $event, 'URL', true ) );
		$this->assertSame( '', get_post_meta( $event, '_site_id', true ) );
	}

	/**
	 * A legacy stored URL still counts as unchanged once normalised.
	 *
	 * Otherwise it would look like an edit, and an event that shares a site couldn't save at all.
	 *
	 * @covers WordCamp_New_Site::save_site_url_field
	 */
	public function test_save_site_url_field_treats_a_legacy_stored_url_as_unchanged() {
		$this->create_event( array(
			'URL'      => 'https://vancouver.wordcamp.test/2016/',
			'_site_id' => self::$slash_year_2016_site_id,
		) );

		$event = $this->create_event( array(
			'URL'      => 'http://vancouver.wordcamp.test/2016',
			'_site_id' => self::$slash_year_2016_site_id,
		) );

		$rejected = $this->save_url( $event, 'https://vancouver.wordcamp.test/2016/' );

		$this->assertFalse( $rejected );
		$this->assertEquals( self::$slash_year_2016_site_id, get_post_meta( $event, '_site_id', true ) );
	}

	/**
	 * Clearing the field clears both meta values.
	 *
	 * @covers WordCamp_New_Site::save_site_url_field
	 */
	public function test_save_site_url_field_clears_meta_for_empty_url() {
		$event = $this->create_event( array(
			'URL'      => 'https://vancouver.wordcamp.test/2016/',
			'_site_id' => self::$slash_year_2016_site_id,
		) );

		$rejected = $this->save_url( $event, '' );

		$this->assertFalse( $rejected );
		$this->assertSame( '', get_post_meta( $event, 'URL', true ) );
		$this->assertSame( '', get_post_meta( $event, '_site_id', true ) );
	}

	/**
	 * An event can't point itself at a site another event owns.
	 *
	 * @covers WordCamp_New_Site::save_site_url_field
	 */
	public function test_save_site_url_field_rejects_site_owned_by_another_event() {
		$other_event = $this->create_event( array(
			'URL'      => 'https://vancouver.wordcamp.test/2016/',
			'_site_id' => self::$slash_year_2016_site_id,
		) );

		$event    = $this->create_event();
		$rejected = $this->save_url( $event, 'https://vancouver.wordcamp.test/2016/' );

		$this->assertTrue( $rejected );
		$this->assertSame( '', get_post_meta( $event, '_site_id', true ) );

		// The URL meta matters as much, because `get_wordcamp_site_id()` falls back to it.
		$this->assertSame( '', get_post_meta( $event, 'URL', true ) );

		// The other event's mapping is untouched.
		$this->assertEquals( self::$slash_year_2016_site_id, get_post_meta( $other_event, '_site_id', true ) );
	}

	/**
	 * A site claimed as another event's secondary site is protected too.
	 *
	 * @covers WordCamp_New_Site::save_site_url_field
	 */
	public function test_save_site_url_field_rejects_site_owned_as_another_events_secondary_site() {
		$this->create_event( array(
			'Secondary Site'     => 'https://vancouver.wordcamp.test/2018-developers/',
			'_secondary_site_id' => self::$slash_year_2018_dev_site_id,
		) );

		$event = $this->create_event();

		$this->assertTrue( $this->save_url( $event, 'https://vancouver.wordcamp.test/2018-developers/' ) );
		$this->assertSame( '', get_post_meta( $event, '_site_id', true ) );
	}

	/**
	 * The multi-valued `Secondary Site` field is guarded as well as `URL`.
	 *
	 * It takes a different branch through `save_site_url_field()`.
	 *
	 * @covers WordCamp_New_Site::save_site_url_field
	 */
	public function test_save_site_url_field_rejects_claimed_site_in_secondary_site_field() {
		$this->create_event( array(
			'URL'      => 'https://vancouver.wordcamp.test/2016/',
			'_site_id' => self::$slash_year_2016_site_id,
		) );

		$event    = $this->create_event();
		$rejected = false;

		$_POST['wcpt_secondary_site'] = array( 'https://vancouver.wordcamp.test/2016/' );

		try {
			$this->new_site->save_site_url_field( 'Secondary Site', 'wc-url', $event );
		} catch ( WPDieException $exception ) {
			$rejected = true;
		}

		$this->assertTrue( $rejected );
		$this->assertSame( array(), get_post_meta( $event, 'Secondary Site', false ) );
		$this->assertSame( array(), get_post_meta( $event, '_secondary_site_id', false ) );
	}

	/**
	 * An event can't squat on the URL of a site that doesn't exist yet.
	 *
	 * @covers WordCamp_New_Site::save_site_url_field
	 */
	public function test_save_site_url_field_rejects_url_claimed_before_the_site_exists() {
		$url = 'https://vancouver.wordcamp.test/2099/';

		$this->assertEmpty( domain_exists( 'vancouver.wordcamp.test', '/2099/', WORDCAMP_NETWORK_ID ) );

		$this->create_event( array( 'URL' => $url ) );

		$event = $this->create_event();

		$this->assertTrue( $this->save_url( $event, $url ) );
		$this->assertSame( '', get_post_meta( $event, 'URL', true ) );
	}

	/**
	 * A claim stored in a legacy spelling still blocks, even though the site doesn't exist yet.
	 *
	 * Legacy values don't match the normalised candidate, so `url_is_claimed()` looks for those spellings too.
	 *
	 * @covers WordCamp_New_Site::save_site_url_field
	 */
	public function test_save_site_url_field_rejects_a_url_claimed_in_a_legacy_spelling() {
		$this->assertEmpty( domain_exists( 'vancouver.wordcamp.test', '/2099/', WORDCAMP_NETWORK_ID ) );

		$this->create_event( array( 'URL' => 'http://vancouver.wordcamp.test/2099' ) );

		$event = $this->create_event();

		$this->assertTrue( $this->save_url( $event, 'https://vancouver.wordcamp.test/2099/' ) );
		$this->assertSame( '', get_post_meta( $event, 'URL', true ) );
	}

	/**
	 * A trashed event still holds on to its URL.
	 *
	 * Trashing the post doesn't delete the site, so releasing the URL would point the next claimant at a live one.
	 *
	 * @covers WordCamp_New_Site::save_site_url_field
	 */
	public function test_save_site_url_field_rejects_a_url_claimed_by_a_trashed_event() {
		$url = 'https://vancouver.wordcamp.test/2099/';

		$this->trash_as_wrangler( $this->create_event( array( 'URL' => $url ) ) );

		$event = $this->create_event();

		$this->assertTrue( $this->save_url( $event, $url ) );
		$this->assertSame( '', get_post_meta( $event, 'URL', true ) );
	}

	/**
	 * Wranglers can re-map an event onto an existing site, which is a normal part of their workflow.
	 *
	 * @covers WordCamp_New_Site::save_site_url_field
	 */
	public function test_save_site_url_field_lets_wranglers_adopt_a_claimed_site() {
		$this->create_event( array(
			'URL'      => 'https://vancouver.wordcamp.test/2016/',
			'_site_id' => self::$slash_year_2016_site_id,
		) );

		$this->become_wrangler();

		$event    = $this->create_event();
		$rejected = $this->save_url( $event, 'https://vancouver.wordcamp.test/2016/' );

		$this->assertFalse( $rejected );
		$this->assertEquals( self::$slash_year_2016_site_id, get_post_meta( $event, '_site_id', true ) );
	}

	/**
	 * A duplicate claim that predates these checks doesn't block saves that leave the URL alone.
	 *
	 * Without this, an organiser whose event already shares a site couldn't save their post at all.
	 *
	 * @covers WordCamp_New_Site::save_site_url_field
	 */
	public function test_save_site_url_field_allows_resaving_a_url_the_event_already_stores() {
		$url = 'https://vancouver.wordcamp.test/2016/';

		$this->create_event( array(
			'URL'      => $url,
			'_site_id' => self::$slash_year_2016_site_id,
		) );

		$event = $this->create_event( array(
			'URL'      => $url,
			'_site_id' => self::$slash_year_2016_site_id,
		) );

		$rejected = $this->save_url( $event, $url );

		$this->assertFalse( $rejected );
		$this->assertEquals( self::$slash_year_2016_site_id, get_post_meta( $event, '_site_id', true ) );
	}
}
