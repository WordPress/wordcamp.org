<?php

defined( 'WPINC' ) || die();

/**
 * Tests for the add-only network propagation of Multi-Event Sponsors.
 *
 * @group multi-event-sponsors
 * @group sponsor-groups
 */
class Test_MES_Propagator extends WP_UnitTestCase {
	/**
	 * The root blog this class provisioned, if it wasn't already present.
	 *
	 * @var int|null
	 */
	protected static $created_root_blog;

	/**
	 * The lead organizer `make_propagation_setup()` gives its camp.
	 *
	 * @var int
	 */
	protected $organizer_id;

	/**
	 * The group `make_propagation_setup()` puts its camp in.
	 *
	 * @var int
	 */
	protected $group_id;

	/**
	 * Provision central (the root blog) for this class.
	 *
	 * In production the `mes` posts live on central and `wp mes propagate` runs
	 * there, so `WordCamp_New_Site::get_stub_me_sponsors_meta()`'s
	 * `switch_to_blog( WORDCAMP_ROOT_BLOG_ID )` is a no-op. The multisite test
	 * install only creates the default blog, and `switch_to_blog()` doesn't check
	 * that a blog exists — so without this the stub's copied meta reads back empty
	 * from tables that aren't there. Provisioned per class, not per test, because
	 * creating a blog isn't transactional.
	 *
	 * @param WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		if ( ! get_site( WORDCAMP_ROOT_BLOG_ID ) ) {
			self::$created_root_blog = $factory->blog->create( array(
				'blog_id'    => WORDCAMP_ROOT_BLOG_ID,
				'network_id' => defined( 'WORDCAMP_NETWORK_ID' ) ? WORDCAMP_NETWORK_ID : 1,
			) );
		}
	}

	/**
	 * Remove the root blog this class created.
	 */
	public static function wpTearDownAfterClass() {
		if ( self::$created_root_blog ) {
			wp_delete_site( self::$created_root_blog );
			self::$created_root_blog = null;
		}
	}

	/**
	 * Run every test on central, the way the CLI command does, with groups switched on.
	 *
	 * Groups only count while the flag is on. Tests of the flag-off state switch it back off.
	 */
	public function set_up() {
		parent::set_up();

		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
		add_filter( 'mes_sponsor_groups_enabled', '__return_true' );
	}

	/**
	 * Return to the blog the test started on.
	 */
	public function tear_down() {
		restore_current_blog();

		parent::tear_down();
	}

	/**
	 * Missing normalizes and diffs.
	 */
	public function test_missing_normalizes_and_diffs() {
		$this->assertSame( array( 1 ), MES_Propagator::missing( array( 1, 2, 3 ), array( 2, 3, 4 ) ) );
		$this->assertSame( array(), MES_Propagator::missing( array( 2, 3 ), array( 2, 3 ) ) );
		$this->assertSame( array( 7 ), MES_Propagator::missing( array( '7', 7, 0 ), array() ) );
	}

	/**
	 * Create a scheduled, upcoming camp with a live subsite and a lead organizer, in a group
	 * with two mapped sponsors, one of which is already pushed to the subsite.
	 *
	 * @return array [ $wordcamp_id, $site_id, $sponsor_a, $sponsor_b, $pushed_a_id ]
	 */
	protected function make_propagation_setup() {
		$group_id       = self::factory()->term->create( array( 'taxonomy' => MES_Sponsor_Group::TAXONOMY_SLUG ) );
		$this->group_id = $group_id;
		$level_id       = self::factory()->post->create( array(
			'post_type'  => MES_Sponsorship_Level::POST_TYPE_SLUG,
			'post_title' => 'Gold',
			'post_name'  => 'gold',
		) );

		$sponsor_a = self::factory()->post->create( array(
			'post_type'  => MES_Sponsor::POST_TYPE_SLUG,
			'post_title' => 'Sponsor A',
		) );
		$sponsor_b = self::factory()->post->create( array(
			'post_type'   => MES_Sponsor::POST_TYPE_SLUG,
			'post_title'  => 'Sponsor B',
			'post_content' => 'About Sponsor B.',
		) );

		update_post_meta( $sponsor_a, 'mes_group_sponsorships', array( $group_id => $level_id ) );
		update_post_meta( $sponsor_b, 'mes_group_sponsorships', array( $group_id => $level_id ) );
		update_post_meta( $sponsor_b, 'mes_website', 'https://sponsor-b.example' );

		$site_id            = self::factory()->blog->create();
		$wordcamp_id        = $this->make_camp_with_site( $site_id, $group_id, 'wcpt-scheduled', strtotime( '+30 days' ) );
		$this->organizer_id = self::factory()->user->create( array( 'user_login' => 'lead-organizer-' . $wordcamp_id ) );

		update_post_meta( $wordcamp_id, 'WordPress.org Username', 'lead-organizer-' . $wordcamp_id );

		// Pre-push sponsor A only.
		switch_to_blog( $site_id );

		$pushed_a_id = self::factory()->post->create( array(
			'post_type'   => 'wcb_sponsor',
			'post_status' => 'publish',
			'post_title'  => 'Sponsor A',
		) );
		update_post_meta( $pushed_a_id, '_mes_id', $sponsor_a );

		restore_current_blog();

		return array( $wordcamp_id, $site_id, $sponsor_a, $sponsor_b, $pushed_a_id );
	}

	/**
	 * Create a camp with a site, in a group, with the given status and end date.
	 *
	 * @param int    $site_id
	 * @param int    $group_id
	 * @param string $status   A wcpt post status.
	 * @param int    $end_date End date as a timestamp, or 0 for none.
	 *
	 * @return int WordCamp post ID.
	 */
	protected function make_camp_with_site( $site_id, $group_id, $status, $end_date ) {
		$wordcamp_id = self::factory()->post->create( array(
			'post_type'   => 'wordcamp',
			'post_status' => $status,
		) );

		update_post_meta( $wordcamp_id, 'mes_sponsor_groups', array( $group_id ) );
		update_post_meta( $wordcamp_id, '_site_id', $site_id );

		if ( $end_date ) {
			update_post_meta( $wordcamp_id, 'Start Date (YYYY-mm-dd)', $end_date );
			update_post_meta( $wordcamp_id, 'End Date (YYYY-mm-dd)', $end_date );
		}

		return $wordcamp_id;
	}

	/**
	 * Get the subsite's wcb_sponsor posts keyed by _mes_id.
	 *
	 * @param int $site_id
	 *
	 * @return array { mes_id => WP_Post }
	 */
	protected function get_site_sponsors_by_mes_id( $site_id ) {
		switch_to_blog( $site_id );

		$by_mes_id = array();

		foreach ( get_posts( array(
			'post_type' => 'wcb_sponsor', 'post_status' => 'any', 'posts_per_page' => -1,
		) ) as $post ) {
			$by_mes_id[ absint( get_post_meta( $post->ID, '_mes_id', true ) ) ] = $post;
		}

		restore_current_blog();

		return $by_mes_id;
	}

	/**
	 * Run adds only missing sponsors.
	 */
	public function test_run_adds_only_missing_sponsors() {
		list( , $site_id, $sponsor_a, $sponsor_b, $pushed_a_id ) = $this->make_propagation_setup();

		$summary = MES_Propagator::run();

		$this->assertSame( 1, $summary['camps_scanned'] );
		$this->assertSame( 1, $summary['camps_updated'] );
		$this->assertSame( 1, $summary['sponsors_added'] );

		$site_sponsors = $this->get_site_sponsors_by_mes_id( $site_id );

		// B was created as a draft stub with the copied meta.
		$this->assertArrayHasKey( $sponsor_b, $site_sponsors );
		$stub = $site_sponsors[ $sponsor_b ];
		$this->assertSame( 'draft', $stub->post_status );
		$this->assertSame( 'Sponsor B', $stub->post_title );
		$this->assertSame( 'About Sponsor B.', $stub->post_content );

		// Authored by the lead organizer, and at the level it has for this camp, the way site creation does it.
		$this->assertSame( $this->organizer_id, (int) $stub->post_author );

		switch_to_blog( $site_id );
		$this->assertSame( 'https://sponsor-b.example', get_post_meta( $stub->ID, '_wcpt_sponsor_website', true ) );
		$this->assertSame( 'no', get_post_meta( $stub->ID, '_wcb_sponsor_first_time', true ) );
		$this->assertSame( array( 'gold' ), wp_get_object_terms( $stub->ID, 'wcb_sponsor_level', array( 'fields' => 'slugs' ) ) );
		$this->assertSame( 'Gold', get_term_by( 'slug', 'gold', 'wcb_sponsor_level' )->name );
		restore_current_blog();

		// A is untouched: same post, same status.
		$this->assertArrayHasKey( $sponsor_a, $site_sponsors );
		$this->assertSame( $pushed_a_id, $site_sponsors[ $sponsor_a ]->ID );
		$this->assertSame( 'publish', $site_sponsors[ $sponsor_a ]->post_status );
	}

	/**
	 * Run is idempotent.
	 */
	public function test_run_is_idempotent() {
		$this->make_propagation_setup();

		MES_Propagator::run();
		$again = MES_Propagator::run();

		$this->assertSame( 1, $again['camps_scanned'] );
		$this->assertSame( 0, $again['camps_updated'] );
		$this->assertSame( 0, $again['sponsors_added'] );
	}

	/**
	 * While the flag is off, groups don't count, so propagation adds no group-only sponsors.
	 */
	public function test_run_ignores_groups_while_disabled() {
		list( , $site_id, , $sponsor_b ) = $this->make_propagation_setup();

		add_filter( 'mes_sponsor_groups_enabled', '__return_false', 20 );

		$summary = MES_Propagator::run();

		$this->assertSame( 0, $summary['sponsors_added'] );
		$this->assertArrayNotHasKey( $sponsor_b, $this->get_site_sponsors_by_mes_id( $site_id ) );
	}

	/**
	 * Run filters to one sponsor.
	 */
	public function test_run_filters_to_one_sponsor() {
		list( , $site_id, , $sponsor_b ) = $this->make_propagation_setup();

		$summary = MES_Propagator::run( $sponsor_b );

		$this->assertSame( 1, $summary['sponsors_added'] );
		$this->assertArrayHasKey( $sponsor_b, $this->get_site_sponsors_by_mes_id( $site_id ) );
	}

	/**
	 * Dry run counts without writing.
	 */
	public function test_dry_run_counts_without_writing() {
		list( , $site_id, , $sponsor_b ) = $this->make_propagation_setup();

		$summary = MES_Propagator::run( 0, true );

		$this->assertSame( 1, $summary['camps_updated'] );
		$this->assertSame( 1, $summary['sponsors_added'] );
		$this->assertArrayNotHasKey( $sponsor_b, $this->get_site_sponsors_by_mes_id( $site_id ) );
	}

	/**
	 * Run skips camps without live site.
	 */
	public function test_run_skips_camps_without_live_site() {
		$group_id   = self::factory()->term->create( array( 'taxonomy' => MES_Sponsor_Group::TAXONOMY_SLUG ) );
		$level_id   = self::factory()->post->create( array( 'post_type' => MES_Sponsorship_Level::POST_TYPE_SLUG ) );
		$sponsor_id = self::factory()->post->create( array( 'post_type' => MES_Sponsor::POST_TYPE_SLUG ) );

		update_post_meta( $sponsor_id, 'mes_group_sponsorships', array( $group_id => $level_id ) );

		$wordcamp_id = self::factory()->post->create( array( 'post_type' => 'wordcamp' ) );
		update_post_meta( $wordcamp_id, 'mes_sponsor_groups', array( $group_id ) );
		// No _site_id meta: the camp has no site yet — site creation handles it later.

		$summary = MES_Propagator::run();

		$this->assertSame( 0, $summary['camps_scanned'] );
		$this->assertSame( 0, $summary['sponsors_added'] );
	}

	/**
	 * Closed camps and camps whose event is over are left alone, even though they still have a site.
	 * Scheduled camps stay `wcpt-scheduled` until the close cron runs, so the status alone isn't enough.
	 */
	public function test_run_skips_closed_and_past_camps() {
		list( , $active_site ) = $this->make_propagation_setup();

		$closed_site = self::factory()->blog->create();
		$past_site   = self::factory()->blog->create();

		$this->make_camp_with_site( $closed_site, $this->group_id, 'wcpt-closed', strtotime( '+30 days' ) );
		$this->make_camp_with_site( $past_site, $this->group_id, 'wcpt-scheduled', strtotime( '-2 days' ) );

		$summary = MES_Propagator::run();

		$this->assertSame( 1, $summary['camps_scanned'] );
		$this->assertSame( 1, $summary['sponsors_added'] );
		$this->assertCount( 2, $this->get_site_sponsors_by_mes_id( $active_site ) );
		$this->assertSame( array(), $this->get_site_sponsors_by_mes_id( $closed_site ) );
		$this->assertSame( array(), $this->get_site_sponsors_by_mes_id( $past_site ) );
	}

	/**
	 * A camp whose event ends today is still on: the close cron waits for 23:59.
	 */
	public function test_run_includes_a_camp_that_ends_today() {
		list( $wordcamp_id, $site_id, , $sponsor_b ) = $this->make_propagation_setup();

		update_post_meta( $wordcamp_id, 'End Date (YYYY-mm-dd)', strtotime( 'today' ) );

		$summary = MES_Propagator::run();

		$this->assertSame( 1, $summary['camps_scanned'] );
		$this->assertArrayHasKey( $sponsor_b, $this->get_site_sponsors_by_mes_id( $site_id ) );
	}

	/**
	 * A sponsor the organizers trashed stays trashed: it still counts as present on the site.
	 */
	public function test_run_leaves_a_trashed_sponsor_alone() {
		list( , $site_id, $sponsor_a, $sponsor_b, $pushed_a_id ) = $this->make_propagation_setup();

		switch_to_blog( $site_id );
		wp_trash_post( $pushed_a_id );
		restore_current_blog();

		$summary = MES_Propagator::run();

		$this->assertSame( 1, $summary['sponsors_added'] );

		switch_to_blog( $site_id );
		$a_posts = get_posts( array(
			'post_type'   => 'wcb_sponsor',
			'post_status' => array( 'any', 'trash' ),
			'meta_key'    => '_mes_id',
			'meta_value'  => $sponsor_a,
			'fields'      => 'ids',
		) );
		restore_current_blog();

		$this->assertSame( array( $pushed_a_id ), $a_posts );
		$this->assertSame( 'trash', $this->site_post_status( $site_id, $pushed_a_id ) );
		$this->assertArrayHasKey( $sponsor_b, $this->get_site_sponsors_by_mes_id( $site_id ) );
	}

	/**
	 * Read a post's status on another site.
	 *
	 * @param int $site_id
	 * @param int $post_id
	 *
	 * @return string
	 */
	protected function site_post_status( $site_id, $post_id ) {
		switch_to_blog( $site_id );
		$status = get_post_status( $post_id );
		restore_current_blog();

		return $status;
	}

	/**
	 * The sponsor's logo on central is sideloaded onto the stub, the way both existing pushes do it.
	 *
	 * The download itself is HTTP, so a subclass records the call instead of making it.
	 */
	public function test_run_sideloads_the_logo_from_central() {
		list( , $site_id, , $sponsor_b ) = $this->make_propagation_setup();

		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $sponsor_b );
		set_post_thumbnail( $sponsor_b, $attachment_id );
		$logo_url = wp_get_attachment_image_src( $attachment_id, 'full' )[0];

		$propagator = new class() extends MES_Propagator {
			/**
			 * The ( $url, $post_id ) pairs the propagator asked to sideload.
			 *
			 * @var array
			 */
			public static $sideloaded = array();

			/**
			 * Record the request instead of downloading anything.
			 *
			 * @param string $url
			 * @param int    $post_id
			 *
			 * @return int
			 */
			protected static function sideload_logo( $url, $post_id ) {
				self::$sideloaded[] = array( $url, $post_id );

				return 0;
			}
		};

		$propagator::run();

		$stub = $this->get_site_sponsors_by_mes_id( $site_id )[ $sponsor_b ];

		$this->assertSame( array( array( $logo_url, $stub->ID ) ), $propagator::$sideloaded );
	}

	/**
	 * A sponsor without a logo asks for no download.
	 */
	public function test_run_does_not_sideload_when_there_is_no_logo() {
		$this->make_propagation_setup();

		$propagator = new class() extends MES_Propagator {
			/**
			 * The ( $url, $post_id ) pairs the propagator asked to sideload.
			 *
			 * @var array
			 */
			public static $sideloaded = array();

			/**
			 * Record the request instead of downloading anything.
			 *
			 * @param string $url
			 * @param int    $post_id
			 *
			 * @return int
			 */
			protected static function sideload_logo( $url, $post_id ) {
				self::$sideloaded[] = array( $url, $post_id );

				return 0;
			}
		};

		$propagator::run();

		$this->assertSame( array(), $propagator::$sideloaded );
	}

	/**
	 * Without a lead organizer, the stub is authored by whoever runs the command, like site creation.
	 */
	public function test_run_falls_back_to_the_current_user_as_author() {
		list( $wordcamp_id, $site_id, , $sponsor_b ) = $this->make_propagation_setup();

		delete_post_meta( $wordcamp_id, 'WordPress.org Username' );
		$runner = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $runner );

		MES_Propagator::run();

		$this->assertSame( $runner, (int) $this->get_site_sponsors_by_mes_id( $site_id )[ $sponsor_b ]->post_author );
	}

	/**
	 * Without a lead organizer or a current user (WP-CLI without --user), the camp is skipped and named,
	 * rather than getting stubs authored by nobody.
	 */
	public function test_run_skips_a_camp_whose_stubs_would_have_no_author() {
		list( $wordcamp_id, $site_id, , $sponsor_b ) = $this->make_propagation_setup();

		delete_post_meta( $wordcamp_id, 'WordPress.org Username' );
		wp_set_current_user( 0 );

		$summary = MES_Propagator::run();

		$this->assertSame( 0, $summary['sponsors_added'] );
		$this->assertSame( array( $wordcamp_id ), $summary['camps_skipped_no_author'] );
		$this->assertArrayNotHasKey( $sponsor_b, $this->get_site_sponsors_by_mes_id( $site_id ) );
	}
}
