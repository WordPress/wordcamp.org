<?php

namespace WordCamp\WCPT\Tests;

use WP_UnitTestCase;
use WP_Query;
use WordCamp_Loader;
use Meetup_Loader;

defined( 'WPINC' ) || die();

/**
 * Tests for which event post statuses expose a public single view.
 *
 * @group wcpt
 */
class Test_Event_Status_Visibility extends WP_UnitTestCase {

	/**
	 * A `wordcamp` post in an application status, created per test.
	 *
	 * @var int
	 */
	protected $application_id;

	/**
	 * Create an application that no public surface links to.
	 */
	public function set_up() {
		parent::set_up();

		$this->application_id = self::factory()->post->create(
			array(
				'post_type'   => WCPT_POST_TYPE_ID,
				'post_status' => 'wcpt-rejected',
				'post_title'  => 'WordCamp Nowhere',
			)
		);
	}

	/**
	 * Subroles are a global rather than user meta, so they have to be cleared by hand.
	 * So does the screen, since this suite otherwise runs with `is_admin()` true.
	 */
	public function tear_down() {
		$GLOBALS['wcorg_subroles'] = array();
		unset( $GLOBALS['current_screen'] );

		parent::tear_down();
	}

	/**
	 * Grant a user the WordCamp Wrangler subrole.
	 *
	 * `WP_User::add_cap()` is not usable here: `WordCamp\SubRoles\omit_usermeta_caps()`
	 * strips any capability stored in user meta, on purpose. Production grants these
	 * through `$wcorg_subroles` in `.config/capes.php`, so the tests do too.
	 *
	 * @param int $user_id
	 */
	protected function make_wrangler( $user_id ) {
		$GLOBALS['wcorg_subroles'][ $user_id ] = array( 'wordcamp_wrangler' );
	}

	/**
	 * Run the query a request for a camp's permalink produces.
	 *
	 * Queried by name rather than through `$this->go_to( get_permalink() )`, because that
	 * depends on rewrite rules and `$_SERVER` globals that other suites in this run leave
	 * modified, which made these tests pass alone and fail in the full suite. A `name`
	 * query sets `is_single`, which is all the status check in `WP_Query::get_posts()`
	 * needs.
	 *
	 * @param int $post_id
	 *
	 * @return WP_Query
	 */
	protected function query_single( $post_id ) {
		set_current_screen( 'front' );

		return new WP_Query(
			array(
				'post_type' => WCPT_POST_TYPE_ID,
				'name'      => get_post_field( 'post_name', $post_id ),
			)
		);
	}

	/**
	 * The two statuses public surfaces list stay `public`.
	 *
	 * @covers Event_Loader::register_post_statuses
	 */
	public function test_listed_statuses_are_public() {
		foreach ( WordCamp_Loader::get_public_post_statuses() as $status ) {
			$object = get_post_status_object( $status );

			$this->assertNotNull( $object, "$status is not registered" );
			$this->assertTrue( $object->public, "$status should be public" );
			$this->assertFalse( $object->protected, "$status should not be protected" );
		}
	}

	/**
	 * Application statuses lose their public single view.
	 *
	 * @covers Event_Loader::register_post_statuses
	 */
	public function test_application_statuses_are_protected() {
		$expected = array(
			'wcpt-needs-vetting',
			'wcpt-needs-orientati',
			'wcpt-more-info-reque',
			'wcpt-interview-sched',
			'wcpt-rejected',
			'wcpt-approved-pre-pl',
			'wcpt-needs-email',
			'wcpt-needs-site',
			'wcpt-needs-pre-plann',
			'wcpt-needs-action',
		);

		foreach ( $expected as $status ) {
			$object = get_post_status_object( $status );

			$this->assertNotNull( $object, "$status is not registered" );
			$this->assertFalse( $object->public, "$status should not be public" );
			$this->assertTrue( $object->protected, "$status should be protected" );
			$this->assertFalse( $object->publicly_queryable, "$status should not be publicly queryable" );
		}
	}

	/**
	 * Cancelled and pre-planning camps keep resolving, for the reasons in
	 * `WordCamp_Loader::get_publicly_viewable_post_statuses()`.
	 *
	 * @covers WordCamp_Loader::get_publicly_viewable_post_statuses
	 */
	public function test_deferred_statuses_still_resolve() {
		$deferred = array_merge(
			array( 'wcpt-cancelled' ),
			WordCamp_Loader::get_pre_planning_post_statuses()
		);

		foreach ( $deferred as $status ) {
			$this->assertTrue(
				get_post_status_object( $status )->public,
				"$status should still be public"
			);
		}
	}

	/**
	 * `exclude_from_search` governs `post_status => 'any'`, which `get_wordcamps()` and
	 * `get_wordcamp_post()` depend on. It has to stay false for every status.
	 *
	 * @covers Event_Loader::register_post_statuses
	 */
	public function test_no_status_is_excluded_from_search() {
		$statuses = array_merge(
			array_keys( WordCamp_Loader::get_post_statuses() ),
			array_keys( Meetup_Loader::get_post_statuses() )
		);

		foreach ( $statuses as $status ) {
			$this->assertFalse(
				get_post_status_object( $status )->exclude_from_search,
				"$status must not be excluded from search"
			);
		}
	}

	/**
	 * The regression the previous test guards, stated as behaviour rather than as a flag.
	 *
	 * @covers Event_Loader::register_post_statuses
	 */
	public function test_any_status_query_still_returns_applications() {
		$found = get_posts(
			array(
				'post_type'   => WCPT_POST_TYPE_ID,
				'post_status' => 'any',
				'fields'      => 'ids',
			)
		);

		$this->assertContains( $this->application_id, $found );
	}

	/**
	 * A query that doesn't name a status must not pick up applications.
	 *
	 * `WP_Query` rather than `get_posts()`, because `get_posts()` substitutes
	 * `post_status => 'publish'` and would never exercise the default status set.
	 * `wcpt_has_wordcamps()`, which the Central theme calls, uses `WP_Query`.
	 *
	 * @covers Event_Loader::register_post_statuses
	 */
	public function test_default_status_query_omits_applications() {
		// This suite runs with `is_admin()` true, and WP_Query deliberately widens the
		// default status set in admin context. Pin the front end explicitly.
		set_current_screen( 'front' );

		$listed = $this->create_listed_camp();

		$query = new WP_Query(
			array(
				'post_type'      => WCPT_POST_TYPE_ID,
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$this->assertContains( $listed, $query->posts );
		$this->assertNotContains( $this->application_id, $query->posts );
	}

	/**
	 * The other half of the previous test. Wranglers triage from the list table, so the
	 * admin has to keep seeing what the front end no longer does.
	 *
	 * @covers Event_Loader::register_post_statuses
	 */
	public function test_default_status_query_keeps_applications_in_the_admin() {
		set_current_screen( 'edit-wordcamp' );

		$query = new WP_Query(
			array(
				'post_type'      => WCPT_POST_TYPE_ID,
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$this->assertTrue( is_admin(), 'this test needs admin context to mean anything' );
		$this->assertContains( $this->application_id, $query->posts );
	}

	/**
	 * A camp in a status public surfaces list.
	 *
	 * `wcpt-closed` rather than `wcpt-scheduled`, because WordCamp_Admin fires a Slack
	 * notification on the transition to scheduled and it fatals on PHP 8 when the camp
	 * has no `Start Date` meta.
	 *
	 * @return int
	 */
	protected function create_listed_camp() {
		return self::factory()->post->create(
			array(
				'post_type'   => WCPT_POST_TYPE_ID,
				'post_status' => 'wcpt-closed',
			)
		);
	}

	/**
	 * The disclosure itself: a logged out visitor gets nothing from the permalink.
	 *
	 * @covers Event_Loader::register_post_statuses
	 */
	public function test_single_view_is_empty_for_anonymous_visitors() {
		wp_set_current_user( 0 );

		$this->assertEmpty( $this->query_single( $this->application_id )->posts );
	}

	/**
	 * A Wrangler clicking "View" on an application still gets the page. This is the
	 * workflow that ruled out registering the statuses as plain non-public.
	 *
	 * @covers Event_Loader::register_post_statuses
	 */
	public function test_single_view_resolves_for_users_who_can_edit() {
		$wrangler = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->make_wrangler( $wrangler );

		wp_set_current_user( $wrangler );

		$this->assertTrue(
			current_user_can( 'edit_post', $this->application_id ),
			'the fixture did not actually grant edit rights'
		);

		$query = $this->query_single( $this->application_id );

		$this->assertCount( 1, $query->posts );
		$this->assertSame( $this->application_id, $query->posts[0]->ID );
		$this->assertTrue( $query->is_preview, 'the wrangler should get a preview' );
	}

	/**
	 * Mentors reach `edit_post` through a different branch of `map_subrole_caps()` than
	 * Wranglers do, mapping to plain `edit_posts`. They process applications too, so the
	 * preview has to work for them as well.
	 *
	 * @covers Event_Loader::register_post_statuses
	 */
	public function test_mentor_can_preview_their_own_mentee_camp() {
		$mentor = self::factory()->user->create(
			array(
				'role'       => 'contributor',
				'user_login' => 'mentor_of_nowhere',
			)
		);

		update_post_meta( $this->application_id, 'Mentor WordPress.org User Name', 'mentor_of_nowhere' );
		wp_set_current_user( $mentor );

		$query = $this->query_single( $this->application_id );

		$this->assertCount( 1, $query->posts );
		$this->assertSame( $this->application_id, $query->posts[0]->ID );
	}

	/**
	 * Being a mentor somewhere does not open every application.
	 *
	 * @covers Event_Loader::register_post_statuses
	 */
	public function test_mentor_cannot_preview_a_camp_they_do_not_mentor() {
		$mentor = self::factory()->user->create(
			array(
				'role'       => 'contributor',
				'user_login' => 'mentor_of_elsewhere',
			)
		);

		update_post_meta( $this->application_id, 'Mentor WordPress.org User Name', 'somebody_else' );
		wp_set_current_user( $mentor );

		$this->assertEmpty( $this->query_single( $this->application_id )->posts );
	}

	/**
	 * The mentor branch maps to `edit_posts`, which resolves to `edit_wordcamps`, which
	 * `register_post_capabilities()` only grants to Contributor and above. A mentor who
	 * is a Subscriber on Central therefore loses the preview.
	 *
	 * Pinned as the current boundary rather than as desired behaviour. The mapping in
	 * `wcorg-subroles.php` says it "assumes mentors already have contributor+ access",
	 * and nothing enforces that.
	 *
	 * @covers Event_Loader::register_post_statuses
	 */
	public function test_subscriber_mentor_does_not_get_the_preview() {
		$mentor = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_login' => 'mentor_without_caps',
			)
		);

		update_post_meta( $this->application_id, 'Mentor WordPress.org User Name', 'mentor_without_caps' );
		wp_set_current_user( $mentor );

		$this->assertEmpty( $this->query_single( $this->application_id )->posts );
	}

	/**
	 * A logged in user without edit rights is treated the same as a logged out one.
	 *
	 * @covers Event_Loader::register_post_statuses
	 */
	public function test_single_view_is_empty_for_subscribers() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertEmpty( $this->query_single( $this->application_id )->posts );
	}

	/**
	 * A cancelled camp keeps resolving for everyone, so the v2 REST API keeps serving it.
	 *
	 * @covers WordCamp_Loader::get_publicly_viewable_post_statuses
	 */
	public function test_cancelled_single_view_still_resolves_for_anonymous_visitors() {
		$cancelled = self::factory()->post->create(
			array(
				'post_type'   => WCPT_POST_TYPE_ID,
				'post_status' => 'wcpt-cancelled',
			)
		);

		wp_set_current_user( 0 );

		$this->assertCount( 1, $this->query_single( $cancelled )->posts );
	}

	/**
	 * Meetups inherit the same treatment, against their own public set. `wcpt-mtp-nds-vet`
	 * is deliberately public there, unlike the WordCamp equivalent.
	 *
	 * @covers Event_Loader::get_publicly_viewable_post_statuses
	 */
	public function test_meetup_statuses_follow_the_meetup_public_set() {
		foreach ( Meetup_Loader::get_public_post_statuses() as $status ) {
			$this->assertTrue(
				get_post_status_object( $status )->public,
				"$status should be public"
			);
		}

		$this->assertTrue( get_post_status_object( 'wcpt-mtp-nds-vet' )->public );
		$this->assertFalse( get_post_status_object( 'wcpt-mtp-rejected' )->public );
		$this->assertTrue( get_post_status_object( 'wcpt-mtp-rejected' )->protected );
	}

	/**
	 * The admin list table shows every status under "All", which relies on protected
	 * statuses opting in to that list.
	 *
	 * @covers Event_Loader::register_post_statuses
	 */
	public function test_protected_statuses_stay_in_the_admin_lists() {
		foreach ( array_keys( WordCamp_Loader::get_post_statuses() ) as $status ) {
			$object = get_post_status_object( $status );

			$this->assertTrue( $object->show_in_admin_all_list, "$status missing from the admin All list" );
			$this->assertTrue( $object->show_in_admin_status_list, "$status missing from the admin status links" );
		}
	}
}
