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
	 * Create a camp with a live subsite, in a group with two mapped sponsors,
	 * one of which is already pushed to the subsite.
	 *
	 * @return array [ $wordcamp_id, $site_id, $sponsor_a, $sponsor_b, $pushed_a_id ]
	 */
	protected function make_propagation_setup() {
		$group_id = self::factory()->term->create( array( 'taxonomy' => MES_Sponsor_Group::TAXONOMY_SLUG ) );
		$level_id = self::factory()->post->create( array( 'post_type' => MES_Sponsorship_Level::POST_TYPE_SLUG ) );

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

		$site_id     = self::factory()->blog->create();
		$wordcamp_id = self::factory()->post->create( array( 'post_type' => 'wordcamp' ) );

		update_post_meta( $wordcamp_id, 'mes_sponsor_groups', array( $group_id ) );
		update_post_meta( $wordcamp_id, '_site_id', $site_id );

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

		switch_to_blog( $site_id );
		$this->assertSame( 'https://sponsor-b.example', get_post_meta( $stub->ID, '_wcpt_sponsor_website', true ) );
		$this->assertSame( 'no', get_post_meta( $stub->ID, '_wcb_sponsor_first_time', true ) );
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
}
