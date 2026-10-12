<?php

defined( 'WPINC' ) || die();

/**
 * Tests for the region-keyed consumers made group-aware: the public shortcode
 * and the organizer-reminders mailer.
 *
 * @group multi-event-sponsors
 * @group sponsor-groups
 */
class Test_MES_Consumers extends WP_UnitTestCase {
	/**
	 * Groups only count while the flag is on. Tests of the flag-off state switch it back off.
	 */
	public function set_up() {
		parent::set_up();

		add_filter( 'mes_sponsor_groups_enabled', '__return_true' );
	}

	/**
	 * Invoke a protected/private method for testing.
	 *
	 * @param object $object The object to invoke the method on.
	 * @param string $method The method name.
	 * @param array  $args   Arguments to pass to the method.
	 *
	 * @return mixed
	 */
	protected function invoke( $object, $method, array $args = array() ) {
		// No `setAccessible()` call -- it's been a no-op since PHP 8.1, and deprecated since 8.5.
		$ref = new ReflectionMethod( $object, $method );

		return $ref->invokeArgs( $object, $args );
	}

	/**
	 * A WCOR_Mailer instance without its constructor's trigger/hook wiring.
	 *
	 * @return WCOR_Mailer
	 */
	protected function bare_mailer() {
		return ( new ReflectionClass( 'WCOR_Mailer' ) )->newInstanceWithoutConstructor();
	}

	/**
	 * A camp targeted ONLY via a group (no legacy region anywhere).
	 *
	 * @return array [ $wordcamp_id, $sponsor_id, $level_id, $group_id ]
	 */
	protected function make_group_only_setup() {
		$group_id = self::factory()->term->create( array(
			'taxonomy' => MES_Sponsor_Group::TAXONOMY_SLUG, 'name' => 'Global Program',
		) );
		$level_id = self::factory()->post->create( array(
			'post_type' => MES_Sponsorship_Level::POST_TYPE_SLUG, 'post_title' => 'Champion',
		) );

		$sponsor_id = self::factory()->post->create( array(
			'post_type'  => MES_Sponsor::POST_TYPE_SLUG,
			'post_title' => 'Groupy Sponsor Co',
		) );
		update_post_meta( $sponsor_id, 'mes_group_sponsorships', array( $group_id => $level_id ) );
		update_post_meta( $sponsor_id, 'mes_first_name', 'Grace' );
		update_post_meta( $sponsor_id, 'mes_last_name', 'Hopper' );
		update_post_meta( $sponsor_id, 'mes_email_address', 'grace@example.com' );

		$wordcamp_id = self::factory()->post->create( array( 'post_type' => 'wordcamp' ) );
		update_post_meta( $wordcamp_id, 'mes_sponsor_groups', array( $group_id ) );

		return array( $wordcamp_id, $sponsor_id, $level_id, $group_id );
	}

	/**
	 * Shortcode renders group section.
	 */
	public function test_shortcode_renders_group_section() {
		$this->make_group_only_setup();

		$mes    = new Multi_Event_Sponsors();
		$output = $mes->shortcode_multi_event_sponsors( array() );

		$this->assertStringContainsString( 'If your WordCamp is in the Global Program group:', $output );
		$this->assertStringContainsString( 'Groupy Sponsor Co', $output );
		$this->assertStringContainsString( 'Champion', $output );
	}

	/**
	 * While the flag is off, the shortcode gets no group sections to list.
	 *
	 * Asserts on the data the view is given rather than the rendered output: the view is loaded with
	 * `require_once`, so only the first render in a process prints anything.
	 */
	public function test_shortcode_has_no_group_section_while_disabled() {
		list( , $sponsor_id ) = $this->make_group_only_setup();

		$mes      = new Multi_Event_Sponsors();
		$sponsors = array( $sponsor_id => get_post( $sponsor_id ) );

		$this->assertNotEmpty( $this->invoke( $mes, 'group_sponsors_by_group_and_level', array( $sponsors ) ) );

		add_filter( 'mes_sponsor_groups_enabled', '__return_false', 20 );

		$this->assertSame( array(), $this->invoke( $mes, 'group_sponsors_by_group_and_level', array( $sponsors ) ) );
	}

	/**
	 * Mailer mes info resolves level for group only camp.
	 */
	public function test_mailer_mes_info_resolves_level_for_group_only_camp() {
		list( $wordcamp_id ) = $this->make_group_only_setup();

		$info = $this->invoke( $this->bare_mailer(), 'get_mes_info', array( $wordcamp_id ) );

		$this->assertStringContainsString( 'Company: Groupy Sponsor Co', $info );
		$this->assertStringContainsString( 'Sponsorship Level: Champion', $info );
		$this->assertStringContainsString( 'Grace Hopper, grace@example.com', $info );
	}

	/**
	 * Mailer audience label prefers region falls back to groups.
	 */
	public function test_mailer_audience_label_prefers_region_falls_back_to_groups() {
		list( $wordcamp_id ) = $this->make_group_only_setup();

		$mailer = $this->bare_mailer();

		// Group-only camp: comma-joined group names.
		$this->assertSame( 'Global Program', $this->invoke( $mailer, 'get_mes_audience_label', array( $wordcamp_id, '' ) ) );

		// With a region set, the region name wins (legacy behavior preserved).
		$region_id = self::factory()->term->create( array(
			'taxonomy' => MES_Region::TAXONOMY_SLUG, 'name' => 'Europe',
		) );

		$this->assertSame( 'Europe', $this->invoke( $mailer, 'get_mes_audience_label', array( $wordcamp_id, $region_id ) ) );
	}

	/**
	 * While the flag is off, the mailer doesn't name a camp's groups.
	 */
	public function test_mailer_audience_label_ignores_groups_while_disabled() {
		list( $wordcamp_id ) = $this->make_group_only_setup();

		add_filter( 'mes_sponsor_groups_enabled', '__return_false', 20 );

		$this->assertSame( '', $this->invoke( $this->bare_mailer(), 'get_mes_audience_label', array( $wordcamp_id, '' ) ) );
	}

	/**
	 * A reminder email post that goes to the camera kit wrangler.
	 *
	 * @return int Email post ID.
	 */
	protected function make_camera_wrangler_email() {
		// get_recipients() only reads the email's meta, so the post type doesn't matter here and a plain
		// post avoids depending on the reminders plugin's registration order.
		$email_id = self::factory()->post->create();

		add_post_meta( $email_id, 'wcor_send_where', 'wcor_send_camera_wrangler' );

		return $email_id;
	}

	/**
	 * The organizer email lists sponsors newest first, the way it did before groups, not grouped by level.
	 *
	 * Three sponsors at two levels: grouping by level would pull the oldest one up next to the newest.
	 */
	public function test_mailer_mes_info_lists_sponsors_newest_first_not_by_level() {
		$group_id = self::factory()->term->create( array( 'taxonomy' => MES_Sponsor_Group::TAXONOMY_SLUG ) );
		$gold     = self::factory()->post->create( array(
			'post_type'  => MES_Sponsorship_Level::POST_TYPE_SLUG,
			'post_title' => 'Gold',
		) );
		$silver   = self::factory()->post->create( array(
			'post_type'  => MES_Sponsorship_Level::POST_TYPE_SLUG,
			'post_title' => 'Silver',
		) );

		$sponsors = array(
			'Oldest Co' => array(
				'date'  => '2026-01-01 10:00:00',
				'level' => $gold,
			),
			'Middle Co' => array(
				'date'  => '2026-03-01 10:00:00',
				'level' => $silver,
			),
			'Newest Co' => array(
				'date'  => '2026-06-01 10:00:00',
				'level' => $gold,
			),
		);

		foreach ( $sponsors as $name => $sponsor ) {
			$date  = $sponsor['date'];
			$level = $sponsor['level'];
			$sponsor_id = self::factory()->post->create( array(
				'post_type'  => MES_Sponsor::POST_TYPE_SLUG,
				'post_title' => $name,
				'post_date'  => $date,
			) );
			update_post_meta( $sponsor_id, 'mes_group_sponsorships', array( $group_id => $level ) );
			update_post_meta( $sponsor_id, 'mes_email_address', sanitize_title( $name ) . '@example.com' );
		}

		$wordcamp_id = self::factory()->post->create( array( 'post_type' => 'wordcamp' ) );
		update_post_meta( $wordcamp_id, 'mes_sponsor_groups', array( $group_id ) );

		$info = $this->invoke( $this->bare_mailer(), 'get_mes_info', array( $wordcamp_id ) );

		$newest = strpos( $info, 'Company: Newest Co' );
		$middle = strpos( $info, 'Company: Middle Co' );
		$oldest = strpos( $info, 'Company: Oldest Co' );

		$this->assertNotFalse( $newest );
		$this->assertNotFalse( $middle );
		$this->assertNotFalse( $oldest );
		$this->assertLessThan( $middle, $newest );
		$this->assertLessThan( $oldest, $middle );
		$this->assertMatchesRegularExpression( '/Company: Middle Co\s+Sponsorship Level: Silver/', $info );
	}

	/**
	 * A group-only camp's reminder reaches the camera kit wrangler set on its group.
	 */
	public function test_mailer_camera_wrangler_recipient_comes_from_the_camps_groups() {
		list( $wordcamp_id, , , $group_id ) = $this->make_group_only_setup();

		update_term_meta( $group_id, MES_Sponsor_Group::CAMERA_WRANGLER_META, 'camera@example.com' );

		$recipients = $this->invoke( $this->bare_mailer(), 'get_recipients', array( $wordcamp_id, $this->make_camera_wrangler_email() ) );

		$this->assertSame( array( 'camera@example.com' ), array_values( $recipients ) );
	}

	/**
	 * A camp with a region and groups reaches both wranglers, once each.
	 */
	public function test_mailer_camera_wrangler_recipients_cover_region_and_groups_without_repeats() {
		list( $wordcamp_id, , , $group_id ) = $this->make_group_only_setup();

		$other_group = self::factory()->term->create( array( 'taxonomy' => MES_Sponsor_Group::TAXONOMY_SLUG ) );
		$region_id   = self::factory()->term->create( array( 'taxonomy' => MES_Region::TAXONOMY_SLUG ) );

		update_post_meta( $wordcamp_id, 'mes_sponsor_groups', array( $group_id, $other_group ) );
		update_post_meta( $wordcamp_id, 'Multi-Event Sponsor Region', $region_id );
		update_option( 'mes_region_camera_wranglers', array( $region_id => 'region@example.com' ) );
		update_term_meta( $group_id, MES_Sponsor_Group::CAMERA_WRANGLER_META, 'group@example.com' );
		update_term_meta( $other_group, MES_Sponsor_Group::CAMERA_WRANGLER_META, 'region@example.com' );

		$recipients = $this->invoke( $this->bare_mailer(), 'get_recipients', array( $wordcamp_id, $this->make_camera_wrangler_email() ) );

		$this->assertSame( array( 'region@example.com', 'group@example.com' ), array_values( $recipients ) );
	}

	/**
	 * While the flag is off, a group's camera kit wrangler isn't a recipient.
	 */
	public function test_mailer_camera_wrangler_from_groups_is_ignored_while_disabled() {
		list( $wordcamp_id, , , $group_id ) = $this->make_group_only_setup();

		update_term_meta( $group_id, MES_Sponsor_Group::CAMERA_WRANGLER_META, 'camera@example.com' );
		add_filter( 'mes_sponsor_groups_enabled', '__return_false', 20 );

		$recipients = $this->invoke( $this->bare_mailer(), 'get_recipients', array( $wordcamp_id, $this->make_camera_wrangler_email() ) );

		$this->assertSame( array(), array_values( $recipients ) );
	}
}
