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
	 * Adding a `capabilities` map stops `WP_Post_Type::set_props()` defaulting this to true, which
	 * would silently drop per-post capability mapping.
	 */
	public function test_meta_capabilities_are_still_mapped() {
		$this->assertTrue( $this->get_post_type()->map_meta_cap );
	}
}
