<?php

defined( 'WPINC' ) || die();

/**
 * Tests for the read-only dry-run preview screen.
 *
 * @group multi-event-sponsors
 * @group sponsor-groups
 */
class Test_MES_Dry_Run_Page extends WP_UnitTestCase {
	/**
	 * A user who may preview.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Give each test a super admin, matching the screen's capability.
	 */
	public function set_up() {
		parent::set_up();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		grant_super_admin( $this->admin_id );
	}

	/**
	 * Create a region + level + sponsor (mapped via the region) + camp (in the region).
	 *
	 * Mirrors the fixture in Test_MES_Migrate_Groups so this suite can assert the
	 * same "nothing written" property through the screen's own entry point.
	 *
	 * @return int The WordCamp post ID.
	 */
	protected function make_legacy_setup() {
		$region_id = self::factory()->term->create(
			array(
				'taxonomy' => MES_Region::TAXONOMY_SLUG,
				'name'     => 'EU',
			)
		);

		$level_id   = self::factory()->post->create( array( 'post_type' => MES_Sponsorship_Level::POST_TYPE_SLUG ) );
		$sponsor_id = self::factory()->post->create( array( 'post_type' => MES_Sponsor::POST_TYPE_SLUG ) );

		update_post_meta( $sponsor_id, 'mes_regional_sponsorships', array( $region_id => $level_id ) );

		$wordcamp_id = self::factory()->post->create( array( 'post_type' => WCPT_POST_TYPE_ID ) );

		update_post_meta( $wordcamp_id, 'Multi-Event Sponsor Region', $region_id );

		return $wordcamp_id;
	}

	/**
	 * A user without the capability cannot preview.
	 */
	public function test_run_task_requires_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$result = MES_Dry_Run_Page::run_task( 'migrate-groups' );

		$this->assertWPError( $result );
		$this->assertSame( 'mes_dry_run_forbidden', $result->get_error_code() );
	}

	/**
	 * An unrecognised task is refused rather than dispatched.
	 */
	public function test_run_task_rejects_unknown_task() {
		wp_set_current_user( $this->admin_id );

		$result = MES_Dry_Run_Page::run_task( 'delete-everything' );

		$this->assertWPError( $result );
		$this->assertSame( 'mes_dry_run_unknown_task', $result->get_error_code() );
	}

	/**
	 * The task list is a closed set.
	 *
	 * Guards the dispatch surface: if someone adds a task here, they have to add
	 * it to the switch in run_task() too, and this test makes that deliberate.
	 */
	public function test_tasks_is_a_closed_set() {
		$this->assertSame(
			array( 'migrate-groups', 'propagate', 'seed-flagships' ),
			array_keys( MES_Dry_Run_Page::tasks() )
		);
	}

	/**
	 * The migration preview reports counts and writes nothing.
	 *
	 * The reason this screen exists: the same assertion as the CLI dry-run test,
	 * reached through the screen's own entry point.
	 */
	public function test_run_task_migrate_previews_without_writing() {
		wp_set_current_user( $this->admin_id );

		$wordcamp_id = $this->make_legacy_setup();

		$summary = MES_Dry_Run_Page::run_task( 'migrate-groups' );

		$this->assertSame( 1, $summary['groups_created'] );
		$this->assertSame( 1, $summary['camps_assigned'] );
		$this->assertSame( 1, $summary['sponsors_migrated'] );

		$created_groups = get_terms(
			array(
				'taxonomy'   => MES_Sponsor_Group::TAXONOMY_SLUG,
				'hide_empty' => false,
			)
		);

		$this->assertSame( array(), $created_groups );
		$this->assertSame( array(), MES_Sponsor_Group::get_camp_groups( $wordcamp_id ) );
	}

	/**
	 * The propagation preview returns the propagator's summary shape.
	 */
	public function test_run_task_propagate_returns_a_summary() {
		wp_set_current_user( $this->admin_id );

		$summary = MES_Dry_Run_Page::run_task( 'propagate' );

		$this->assertIsArray( $summary );
		$this->assertArrayHasKey( 'camps_scanned', $summary );
		$this->assertArrayHasKey( 'camps_updated', $summary );
		$this->assertArrayHasKey( 'sponsors_added', $summary );
	}

	/**
	 * The flagship preview returns the seeder's summary shape.
	 */
	public function test_run_task_seed_flagships_returns_a_summary() {
		wp_set_current_user( $this->admin_id );

		$summary = MES_Dry_Run_Page::run_task( 'seed-flagships' );

		$this->assertIsArray( $summary );
		$this->assertArrayHasKey( 'group_created', $summary );
		$this->assertArrayHasKey( 'camps_assigned', $summary );
		$this->assertArrayHasKey( 'camps_matched', $summary );
	}

	/**
	 * The screen renders without fatalling, and shows each preview button.
	 *
	 * Stands in for a browser pass: the view is only ever reached from an admin
	 * page callback, so nothing else here would catch a typo in it.
	 */
	public function test_render_outputs_the_form_for_every_task() {
		require_once ABSPATH . 'wp-admin/includes/template.php';

		wp_set_current_user( $this->admin_id );

		$page = new MES_Dry_Run_Page();

		ob_start();
		$page->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Sponsor Groups Dry Run', $html );

		foreach ( array_keys( MES_Dry_Run_Page::tasks() ) as $slug ) {
			$this->assertStringContainsString( 'value="' . $slug . '"', $html );
		}

		// The screen must never offer a real run.
		$this->assertStringNotContainsString( 'dry_run=false', $html );
	}

	/**
	 * Render the screen with a stored preview result, the way it looks after the redirect.
	 *
	 * @param string $task
	 * @param array  $result
	 *
	 * @return string The screen's HTML.
	 */
	protected function render_with_result( $task, array $result ) {
		require_once ABSPATH . 'wp-admin/includes/template.php';

		wp_set_current_user( $this->admin_id );

		$stored = array(
			'task'   => $task,
			'result' => $result,
			'error'  => false,
		);

		set_transient( 'mes_dry_run_result_' . $this->admin_id, $stored );

		ob_start();
		( new MES_Dry_Run_Page() )->render();

		return ob_get_clean();
	}

	/**
	 * Every preview's real summary renders, including the list-valued keys.
	 */
	public function test_render_shows_each_preview_result() {
		$this->make_legacy_setup();

		foreach ( array_keys( MES_Dry_Run_Page::tasks() ) as $task ) {
			wp_set_current_user( $this->admin_id );

			$html = $this->render_with_result( $task, MES_Dry_Run_Page::run_task( $task ) );

			$this->assertStringContainsString( 'Nothing was written.', $html, $task );
		}
	}

	/**
	 * Skipped regions and camps are listed by ID and name, and an empty list says so.
	 */
	public function test_render_lists_skipped_regions_and_camps() {
		$camp_id = self::factory()->post->create( array(
			'post_type'  => WCPT_POST_TYPE_ID,
			'post_title' => 'WordCamp Nowhere',
		) );

		$propagate = array(
			'camps_scanned'           => 2,
			'camps_skipped_no_author' => array( $camp_id ),
		);

		$html = $this->render_with_result( 'propagate', $propagate );

		$this->assertStringContainsString( '2', $html );
		$this->assertStringContainsString( $camp_id . ': WordCamp Nowhere', $html );

		$migrate = array(
			'groups_created'  => 0,
			'regions_skipped' => array( 12 => 'EU & Africa' ),
		);

		$html = $this->render_with_result( 'migrate-groups', $migrate );

		$this->assertStringContainsString( '12: EU &amp; Africa', $html );

		$html = $this->render_with_result( 'migrate-groups', array( 'regions_skipped' => array() ) );

		$this->assertStringContainsString( 'none', $html );
	}

	/**
	 * The screen's URL points at the screen.
	 */
	public function test_page_url_targets_the_screen() {
		$url = MES_Dry_Run_Page::page_url();

		$this->assertStringContainsString( 'post_type=' . MES_Sponsor::POST_TYPE_SLUG, $url );
		$this->assertStringContainsString( 'page=' . MES_Dry_Run_Page::PAGE_SLUG, $url );
	}
}
