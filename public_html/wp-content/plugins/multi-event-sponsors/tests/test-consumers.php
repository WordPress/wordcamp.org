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
}
