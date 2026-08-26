<?php

namespace WordCamp\Organizer_Reminders\Tests;

use WCOR_Reminder;
use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * Class Test_WCOR_Reminder_Capabilities
 *
 * The reminder screens sit under a menu page that requires `manage_options`. The post type has to
 * require the same thing, because `post-new.php` never consults the menu.
 *
 * @group organizer-reminders
 */
class Test_WCOR_Reminder_Capabilities extends WP_UnitTestCase {
	/**
	 * Get the registered post type object for automated reminders.
	 */
	protected function get_post_type() {
		$post_type = get_post_type_object( WCOR_Reminder::AUTOMATED_POST_TYPE_SLUG );

		$this->assertNotNull( $post_type, 'The automated reminder post type is not registered.' );

		return $post_type;
	}

	/**
	 * A Contributor holds core's generic `edit_posts`, so the type must not be resolving to it.
	 */
	public function test_contributor_cannot_reach_reminders() {
		$post_type = $this->get_post_type();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$this->assertFalse( current_user_can( $post_type->cap->create_posts ), 'A Contributor can create a reminder.' );
		$this->assertFalse( current_user_can( $post_type->cap->edit_posts ), 'A Contributor can reach the reminder list.' );
	}

	/**
	 * An Author can publish generic posts, which is the other half of the same problem.
	 */
	public function test_author_cannot_reach_reminders() {
		$post_type = $this->get_post_type();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$this->assertFalse( current_user_can( $post_type->cap->create_posts ), 'An Author can create a reminder.' );
		$this->assertFalse( current_user_can( $post_type->cap->publish_posts ), 'An Author can publish a reminder.' );
	}

	/**
	 * The people the screens are built for still get in.
	 */
	public function test_manage_options_still_reaches_reminders() {
		$post_type = $this->get_post_type();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertTrue( current_user_can( 'manage_options' ), 'The fixture does not have manage_options.' );
		$this->assertTrue( current_user_can( $post_type->cap->create_posts ), 'An administrator cannot create a reminder.' );
		$this->assertTrue( current_user_can( $post_type->cap->edit_posts ), 'An administrator cannot reach the reminder list.' );
		$this->assertTrue( current_user_can( $post_type->cap->edit_others_posts ), 'An administrator cannot edit other reminders.' );
	}

	/**
	 * Per-post mapping, which is what `save_post()` checks before it writes meta and sends mail.
	 *
	 * This also covers leaving `map_meta_cap` implicit. `WP_Post_Type::set_props()` only defaults
	 * it to true while `capabilities` is empty, so a map without the flag stops `edit_post` mapping
	 * at all and locks out administrators too.
	 */
	public function test_edit_post_maps_for_a_real_reminder() {
		$this->assertTrue( $this->get_post_type()->map_meta_cap, 'Meta capabilities are not mapped.' );

		$reminder = self::factory()->post->create(
			array( 'post_type' => WCOR_Reminder::AUTOMATED_POST_TYPE_SLUG )
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$this->assertFalse( current_user_can( 'edit_post', $reminder ), 'A Contributor can edit a reminder.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( current_user_can( 'edit_post', $reminder ), 'An administrator cannot edit a reminder.' );
	}

	/**
	 * `save_post()` guarded on the primitive `edit_posts`, which every Contributor holds, and passed
	 * it a post ID that a primitive never consults.
	 */
	public function test_save_post_ignores_a_writer_who_cannot_edit_the_reminder() {
		$reminder = self::factory()->post->create(
			array( 'post_type' => WCOR_Reminder::AUTOMATED_POST_TYPE_SLUG )
		);

		$_POST  = array( 'wcor_send_when' => 'wcor_send_before' );
		$plugin = $GLOBALS['WCOR_Reminder'];

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$plugin->save_post( $reminder, get_post( $reminder ) );
		$this->assertSame( '', get_post_meta( $reminder, 'wcor_send_when', true ), 'A Contributor wrote reminder meta.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$plugin->save_post( $reminder, get_post( $reminder ) );
		$this->assertSame( 'wcor_send_before', get_post_meta( $reminder, 'wcor_send_when', true ), 'An administrator could not write reminder meta.' );
	}
}
