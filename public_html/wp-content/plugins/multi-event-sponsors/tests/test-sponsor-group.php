<?php

defined( 'WPINC' ) || die();

/**
 * Tests for the mes_sponsor_group taxonomy, camp-side membership, and the
 * sponsor-side group → level map.
 *
 * @group multi-event-sponsors
 * @group sponsor-groups
 */
class Test_MES_Sponsor_Group extends WP_UnitTestCase {
	/**
	 * Switch the camp-side group UI on for one test.
	 *
	 * WP_UnitTestCase backs up and restores hooks per test, so this does not
	 * leak into the rest of the suite.
	 */
	protected function enable_groups() {
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
	 * Taxonomy is registered.
	 */
	public function test_taxonomy_is_registered() {
		$this->assertTrue( taxonomy_exists( MES_Sponsor_Group::TAXONOMY_SLUG ) );
	}

	/**
	 * Taxonomy is attached to mes post type.
	 */
	public function test_taxonomy_is_attached_to_mes_post_type() {
		$taxonomies = get_object_taxonomies( MES_Sponsor::POST_TYPE_SLUG );

		$this->assertContains( MES_Sponsor_Group::TAXONOMY_SLUG, $taxonomies );
	}

	/**
	 * Group sponsorships save and read.
	 */
	public function test_group_sponsorships_save_and_read() {
		$this->enable_groups();

		$sponsor_id = self::factory()->post->create( array( 'post_type' => MES_Sponsor::POST_TYPE_SLUG ) );
		$mes        = new MES_Sponsor();

		$submitted = array(
			'mes_group_sponsorships' => array(
				11 => 501,
				12 => 'null',
				13 => 502,
			),
		);

		$this->invoke( $mes, 'save_post_meta', array( $sponsor_id, $submitted ) );

		$map = MES_Sponsor::get_group_sponsorships( $sponsor_id );

		// 'null'/non-numeric dropped.
		$expected = array(
			11 => 501,
			13 => 502,
		);

		$this->assertSame( $expected, $map );
	}

	/**
	 * Group sponsorships read defaults to empty array.
	 */
	public function test_group_sponsorships_read_defaults_to_empty_array() {
		$sponsor_id = self::factory()->post->create( array( 'post_type' => MES_Sponsor::POST_TYPE_SLUG ) );

		$this->assertSame( array(), MES_Sponsor::get_stored_group_sponsorships( $sponsor_id ) );
	}

	/**
	 * Camp groups save and read.
	 */
	public function test_camp_groups_save_and_read() {
		$wordcamp_id = self::factory()->post->create( array( 'post_type' => 'wordcamp' ) );

		update_post_meta( $wordcamp_id, 'mes_sponsor_groups', array( 11, 13, 0, '13' ) );

		$groups = MES_Sponsor_Group::get_stored_camp_groups( $wordcamp_id );

		$this->assertSame( array( 11, 13 ), $groups ); // Ints, deduped, zero dropped.
	}

	/**
	 * Camp groups read defaults to empty array.
	 */
	public function test_camp_groups_read_defaults_to_empty_array() {
		$wordcamp_id = self::factory()->post->create( array( 'post_type' => 'wordcamp' ) );

		$this->assertSame( array(), MES_Sponsor_Group::get_stored_camp_groups( $wordcamp_id ) );
	}

	/**
	 * Log in as a user who may edit wrangler-protected wcpt fields.
	 *
	 * The capability is granted explicitly rather than relying on a super admin's
	 * blanket caps, so the test states exactly what the save path requires.
	 *
	 * @return int User ID.
	 */
	protected function set_current_user_as_wrangler() {
		global $wcorg_subroles;

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		// The capability comes from a subrole, not from add_cap(): wcorg-subroles
		// deliberately filters ad-hoc grants of it back out.
		$wcorg_subroles = array( $user_id => array( 'wordcamp_wrangler' ) );

		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Clear any subrole assignment so it can't leak into another test.
	 */
	public function tear_down() {
		$GLOBALS['wcorg_subroles'] = array();

		parent::tear_down();
	}

	/**
	 * `save_group_picker` writes sanitized selection.
	 */
	public function test_save_group_picker_writes_sanitized_selection() {
		$this->enable_groups();

		$this->set_current_user_as_wrangler();

		$wordcamp_id = self::factory()->post->create( array( 'post_type' => 'wordcamp' ) );
		$post_key    = wcpt_key_to_str( MES_Sponsor_Group::WCPT_FIELD, 'wcpt_' );
		$first       = self::factory()->term->create( array( 'taxonomy' => MES_Sponsor_Group::TAXONOMY_SLUG ) );
		$second      = self::factory()->term->create( array( 'taxonomy' => MES_Sponsor_Group::TAXONOMY_SLUG ) );

		$_POST[ $post_key ] = array( (string) $first, (string) $first, '0', $second );

		$group = new MES_Sponsor_Group();
		$group->save_group_picker( MES_Sponsor_Group::WCPT_FIELD, '', $wordcamp_id );

		unset( $_POST[ $post_key ] );

		$this->assertSame( array( $first, $second ), MES_Sponsor_Group::get_camp_groups( $wordcamp_id ) );
	}

	/**
	 * `save_group_picker` keeps only IDs that are sponsor groups: a made-up ID or a term from another
	 * taxonomy would otherwise be stored and count as a group.
	 */
	public function test_save_group_picker_drops_ids_that_are_not_groups() {
		$this->enable_groups();

		$this->set_current_user_as_wrangler();

		$wordcamp_id = self::factory()->post->create( array( 'post_type' => 'wordcamp' ) );
		$post_key    = wcpt_key_to_str( MES_Sponsor_Group::WCPT_FIELD, 'wcpt_' );
		$real        = self::factory()->term->create( array( 'taxonomy' => MES_Sponsor_Group::TAXONOMY_SLUG ) );
		$region      = self::factory()->term->create( array( 'taxonomy' => MES_Region::TAXONOMY_SLUG ) );

		$_POST[ $post_key ] = array( (string) $real, '999999', (string) $region );

		$group = new MES_Sponsor_Group();
		$group->save_group_picker( MES_Sponsor_Group::WCPT_FIELD, '', $wordcamp_id );

		unset( $_POST[ $post_key ] );

		$this->assertSame( array( $real ), MES_Sponsor_Group::get_camp_groups( $wordcamp_id ) );
	}

	/**
	 * Group sponsorships metabox renders selection.
	 */
	public function test_group_sponsorships_metabox_renders_selection() {
		$this->enable_groups();

		$group_id = self::factory()->term->create( array(
			'taxonomy' => MES_Sponsor_Group::TAXONOMY_SLUG, 'name' => 'Flagships',
		) );
		$level_id = self::factory()->post->create( array(
			'post_type' => MES_Sponsorship_Level::POST_TYPE_SLUG, 'post_title' => 'Champion',
		) );

		$sponsor_id = self::factory()->post->create( array( 'post_type' => MES_Sponsor::POST_TYPE_SLUG ) );
		update_post_meta( $sponsor_id, 'mes_group_sponsorships', array( $group_id => $level_id ) );

		ob_start();
		( new MES_Sponsor() )->markup_meta_boxes( get_post( $sponsor_id ), array( 'id' => 'mes_group_sponsorships' ) );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Flagships', $html );
		$this->assertStringContainsString( 'name="mes_group_sponsorships[' . $group_id . ']"', $html );
		$this->assertMatchesRegularExpression( '/value="' . $level_id . '"\s+selected/', $html );
	}

	/**
	 * `save_group_picker` respects protected field.
	 */
	public function test_save_group_picker_respects_protected_field() {
		$this->enable_groups();

		$wordcamp_id = self::factory()->post->create( array( 'post_type' => 'wordcamp' ) );
		$post_key    = wcpt_key_to_str( MES_Sponsor_Group::WCPT_FIELD, 'wcpt_' );

		update_post_meta( $wordcamp_id, 'mes_sponsor_groups', array( 5 ) );

		// No wrangler capability, so wcpt treats the field as locked.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$_POST[ $post_key ] = array( '7' );

		$group = new MES_Sponsor_Group();
		$group->save_group_picker( MES_Sponsor_Group::WCPT_FIELD, '', $wordcamp_id );

		unset( $_POST[ $post_key ] );

		$this->assertSame( array( 5 ), MES_Sponsor_Group::get_camp_groups( $wordcamp_id ) );
	}

	/**
	 * The camp-side group UI is off unless something switches it on.
	 *
	 * This is what makes merging the group model a no-op for organizers and
	 * deputies: no field on the WordCamp screen, no taxonomy menu, no saves.
	 */
	public function test_group_ui_is_disabled_by_default() {
		$this->assertFalse( MES_Sponsor_Group::is_enabled() );
	}

	/**
	 * The taxonomy is registered but stays out of the admin menu while disabled.
	 */
	public function test_taxonomy_has_no_admin_menu_while_disabled() {
		$taxonomy = get_taxonomy( MES_Sponsor_Group::TAXONOMY_SLUG );

		$this->assertNotFalse( $taxonomy );
		$this->assertFalse( $taxonomy->show_ui );
	}

	/**
	 * While disabled, wcpt is not offered the field at all.
	 */
	public function test_wcpt_field_is_not_offered_while_disabled() {
		$group = new MES_Sponsor_Group();

		$this->assertSame( array(), $group->register_wcpt_field( array(), 'wordcamp' ) );
		$this->assertSame( array(), $group->register_wcpt_field( array(), 'all' ) );
		$this->assertSame( array(), $group->register_wcpt_field( array(), '' ) );
	}

	/**
	 * Once enabled, the field is offered to the WordCamp field groups.
	 */
	public function test_wcpt_field_is_offered_when_enabled() {
		$this->enable_groups();

		$group = new MES_Sponsor_Group();

		foreach ( array( 'wordcamp', 'all', '' ) as $meta_group ) {
			$keys = $group->register_wcpt_field( array(), $meta_group );

			$this->assertSame( array( MES_Sponsor_Group::WCPT_FIELD => 'mes-groups' ), $keys );
		}
	}

	/**
	 * Once enabled, the field is in the key set wcpt's own save loop walks.
	 *
	 * `Event_Admin::metabox_save()` asks for `meta_keys()` with no group, so a
	 * field offered only to `wordcamp` and `all` renders on the screen but is
	 * never saved.
	 */
	public function test_wcpt_field_is_in_the_save_loop_keys_when_enabled() {
		$this->assertArrayNotHasKey( MES_Sponsor_Group::WCPT_FIELD, WordCamp_Admin::meta_keys() );

		$this->enable_groups();

		$this->assertArrayHasKey( MES_Sponsor_Group::WCPT_FIELD, WordCamp_Admin::meta_keys() );
	}

	/**
	 * A wrangler's selection is saved through wcpt's real metabox save.
	 */
	public function test_wrangler_selection_saves_through_wcpt_metabox_save() {
		$this->enable_groups();

		$this->set_current_user_as_wrangler();

		$wordcamp_id = self::factory()->post->create( array( 'post_type' => 'wordcamp' ) );
		$post_key    = wcpt_key_to_str( MES_Sponsor_Group::WCPT_FIELD, 'wcpt_' );
		$first       = self::factory()->term->create( array( 'taxonomy' => MES_Sponsor_Group::TAXONOMY_SLUG ) );
		$second      = self::factory()->term->create( array( 'taxonomy' => MES_Sponsor_Group::TAXONOMY_SLUG ) );

		$_POST = array(
			'action'   => 'editpost',
			'_wpnonce' => wp_create_nonce( 'update-post_' . $wordcamp_id ),
			$post_key  => array( (string) $first, (string) $second ),
		);

		// Only the per-field loop is under test. The after-save handlers geocode
		// the venue over HTTP and read form fields this request doesn't post.
		remove_all_actions( 'wcpt_metabox_save_done' );

		$GLOBALS['wordcamp_admin']->metabox_save( $wordcamp_id, get_post( $wordcamp_id ) );

		$_POST = array();

		$this->assertSame( array( $first, $second ), MES_Sponsor_Group::get_camp_groups( $wordcamp_id ) );
	}

	/**
	 * The field is not added to unrelated field groups.
	 */
	public function test_wcpt_field_is_not_offered_to_other_meta_groups() {
		$this->enable_groups();

		$group = new MES_Sponsor_Group();

		$this->assertSame( array(), $group->register_wcpt_field( array(), 'venue' ) );
		$this->assertSame( array(), $group->register_wcpt_field( array(), 'organizer' ) );
	}

	/**
	 * While disabled, the picker renders nothing even if wcpt asks for it.
	 */
	public function test_render_outputs_nothing_while_disabled() {
		$group = new MES_Sponsor_Group();

		ob_start();
		$group->render_group_picker( MES_Sponsor_Group::WCPT_FIELD, '', MES_Sponsor_Group::WCPT_FIELD );
		$html = ob_get_clean();

		$this->assertSame( '', $html );
	}

	/**
	 * While disabled, a posted selection is ignored.
	 */
	public function test_save_is_ignored_while_disabled() {
		$wordcamp_id = self::factory()->post->create( array( 'post_type' => 'wordcamp' ) );
		$post_key    = wcpt_key_to_str( MES_Sponsor_Group::WCPT_FIELD, 'wcpt_' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_POST[ $post_key ] = array( '7', '9' );

		$group = new MES_Sponsor_Group();
		$group->save_group_picker( MES_Sponsor_Group::WCPT_FIELD, '', $wordcamp_id );

		unset( $_POST[ $post_key ] );

		$this->assertSame( array(), MES_Sponsor_Group::get_stored_camp_groups( $wordcamp_id ) );
	}

	/**
	 * While disabled, a posted sponsor-side group map is ignored.
	 *
	 * The metabox isn't offered, so the only way to post the field is a forged
	 * request -- and it must not overwrite a map stored while the UI was on.
	 */
	public function test_sponsor_group_map_save_is_ignored_while_disabled() {
		$sponsor_id = self::factory()->post->create( array( 'post_type' => MES_Sponsor::POST_TYPE_SLUG ) );
		update_post_meta( $sponsor_id, 'mes_group_sponsorships', array( 11 => 501 ) );

		$submitted = array( 'mes_group_sponsorships' => array( 99 => 502 ) );

		$this->invoke( new MES_Sponsor(), 'save_post_meta', array( $sponsor_id, $submitted ) );

		$this->assertSame( array( 11 => 501 ), MES_Sponsor::get_stored_group_sponsorships( $sponsor_id ) );
	}

	/**
	 * The sponsor screen gains no metabox while the group UI is disabled.
	 */
	public function test_sponsor_metabox_is_not_added_while_disabled() {
		global $wp_meta_boxes;

		$wp_meta_boxes = array();

		set_current_screen( MES_Sponsor::POST_TYPE_SLUG );

		$sponsor = new MES_Sponsor();
		$sponsor->add_meta_boxes();

		$registered = $wp_meta_boxes[ MES_Sponsor::POST_TYPE_SLUG ]['normal']['default'] ?? array();

		$this->assertArrayNotHasKey( 'mes_group_sponsorships', $registered );
		$this->assertArrayHasKey( 'mes_regional_sponsorships', $registered );
	}

	/**
	 * The camera kit wranglers for a camp: one address per group that has one, no repeats, valid only.
	 */
	public function test_camera_wranglers_for_camp_are_distinct_valid_addresses() {
		$this->enable_groups();

		$group_a = self::factory()->term->create( array( 'taxonomy' => MES_Sponsor_Group::TAXONOMY_SLUG ) );
		$group_b = self::factory()->term->create( array( 'taxonomy' => MES_Sponsor_Group::TAXONOMY_SLUG ) );
		$group_c = self::factory()->term->create( array( 'taxonomy' => MES_Sponsor_Group::TAXONOMY_SLUG ) );
		$group_d = self::factory()->term->create( array( 'taxonomy' => MES_Sponsor_Group::TAXONOMY_SLUG ) );

		update_term_meta( $group_a, MES_Sponsor_Group::CAMERA_WRANGLER_META, 'one@example.com' );
		update_term_meta( $group_b, MES_Sponsor_Group::CAMERA_WRANGLER_META, 'one@example.com' );
		update_term_meta( $group_c, MES_Sponsor_Group::CAMERA_WRANGLER_META, 'not an address' );

		$wordcamp_id = self::factory()->post->create( array( 'post_type' => 'wordcamp' ) );
		update_post_meta( $wordcamp_id, 'mes_sponsor_groups', array( $group_a, $group_b, $group_c, $group_d ) );

		$this->assertSame( array( 'one@example.com' ), MES_Sponsor_Group::get_camera_wranglers_for_camp( $wordcamp_id ) );

		add_filter( 'mes_sponsor_groups_enabled', '__return_false', 20 );

		$this->assertSame( array(), MES_Sponsor_Group::get_camera_wranglers_for_camp( $wordcamp_id ) );
	}

	/**
	 * The group screen's camera wrangler field saves a valid address, clears on empty, and ignores junk.
	 */
	public function test_save_camera_wrangler_writes_clears_and_rejects() {
		$this->enable_groups();
		$this->set_current_user_as_wrangler();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$group_id = self::factory()->term->create( array( 'taxonomy' => MES_Sponsor_Group::TAXONOMY_SLUG ) );
		$group    = new MES_Sponsor_Group();

		$_POST['mes_group_camera_wrangler_nonce'] = wp_create_nonce( 'mes_group_camera_wrangler_' . $group_id );

		$_POST['camera-wrangler-email'] = 'camera@example.com';
		$group->save_camera_wrangler( $group_id );
		$this->assertSame( 'camera@example.com', get_term_meta( $group_id, MES_Sponsor_Group::CAMERA_WRANGLER_META, true ) );

		$_POST['camera-wrangler-email'] = 'not an address';
		$group->save_camera_wrangler( $group_id );
		$this->assertSame( 'camera@example.com', get_term_meta( $group_id, MES_Sponsor_Group::CAMERA_WRANGLER_META, true ) );

		$_POST['camera-wrangler-email'] = '';
		$group->save_camera_wrangler( $group_id );
		$this->assertSame( '', get_term_meta( $group_id, MES_Sponsor_Group::CAMERA_WRANGLER_META, true ) );

		// Without a valid nonce nothing is written.
		$_POST['camera-wrangler-email']           = 'camera@example.com';
		$_POST['mes_group_camera_wrangler_nonce'] = 'nope';
		$group->save_camera_wrangler( $group_id );
		$this->assertSame( '', get_term_meta( $group_id, MES_Sponsor_Group::CAMERA_WRANGLER_META, true ) );

		unset( $_POST['camera-wrangler-email'], $_POST['mes_group_camera_wrangler_nonce'] );
	}
}
