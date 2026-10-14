<?php

namespace WordCamp\Groups\Tests;

use WP_Admin_Bar;

use function WordCamp\Groups\Frontend\Modal\link_new_event_to_modal;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * @group groups
 *
 * Covers the toolbar's + New → Event, which opens the front-end create-event
 * modal rather than the block editor for the people the modal serves (#2027).
 */
class Test_Groups_Modal extends Groups_TestCase {

	/**
	 * Loads the toolbar class, which core only loads when it shows a toolbar.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		parent::wpSetUpBeforeClass( $factory );

		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
	}

	/**
	 * Builds the toolbar's + New menu for a user with the given role, the
	 * way core does, then applies the link change.
	 */
	private function build_new_content_menu( string $role ): WP_Admin_Bar {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );

		$wp_admin_bar = new WP_Admin_Bar();
		wp_admin_bar_new_content_menu( $wp_admin_bar );
		link_new_event_to_modal( $wp_admin_bar );

		return $wp_admin_bar;
	}

	/**
	 * Data provider for test_new_event_opens_the_modal().
	 */
	public function data_event_manager_roles(): array {
		return array(
			'administrator' => array( 'administrator' ),
			'editor'        => array( 'editor' ),
			'author'        => array( 'author' ),
		);
	}

	/**
	 * Organizers and Event Organizers are sent to the home page, which opens
	 * the create-event modal from the fragment.
	 *
	 * @dataProvider data_event_manager_roles
	 */
	public function test_new_event_opens_the_modal( string $role ) {
		$node = $this->build_new_content_menu( $role )->get_node( 'new-gatherpress_event' );

		$this->assertNotNull( $node );
		$this->assertSame( home_url( '/#create-event' ), $node->href );

		// Still the same item in the same menu.
		$this->assertSame( 'new-content', $node->parent );
		$this->assertNotEmpty( $node->title );
	}

	/**
	 * A contributor can start an event in the editor but can't use the
	 * modal, so their link is left pointing at the editor.
	 */
	public function test_contributor_keeps_the_editor() {
		$node = $this->build_new_content_menu( 'contributor' )->get_node( 'new-gatherpress_event' );

		$this->assertNotNull( $node );
		$this->assertSame( admin_url( 'post-new.php?post_type=gatherpress_event' ), $node->href );
	}

	/**
	 * Without the menu item there's nothing to point anywhere, and nothing
	 * is added in its place.
	 */
	public function test_missing_menu_item_is_not_added() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$wp_admin_bar = new WP_Admin_Bar();
		link_new_event_to_modal( $wp_admin_bar );

		$this->assertNull( $wp_admin_bar->get_node( 'new-gatherpress_event' ) );
	}

	/**
	 * The change runs after core has built the + New menu.
	 */
	public function test_runs_after_the_new_content_menu() {
		// Hooks up core's own menus, as `_wp_admin_bar_init()` does.
		( new WP_Admin_Bar() )->add_menus();

		$this->assertIsInt( has_action( 'admin_bar_menu', 'wp_admin_bar_new_content_menu' ) );
		$this->assertGreaterThan(
			has_action( 'admin_bar_menu', 'wp_admin_bar_new_content_menu' ),
			has_action( 'admin_bar_menu', 'WordCamp\\Groups\\Frontend\\Modal\\link_new_event_to_modal' )
		);
	}
}
