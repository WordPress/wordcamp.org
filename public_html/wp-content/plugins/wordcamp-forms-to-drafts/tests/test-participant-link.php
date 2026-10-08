<?php

namespace WordCamp\Forms_To_Drafts\Tests;

use WP_UnitTestCase;
use WordCamp_Forms_To_Drafts;

defined( 'WPINC' ) || die();

// `wcorg_get_linkable_user_login()` is an mu-plugin helper, not loaded by this suite.
require_once dirname( __DIR__, 3 ) . '/mu-plugins/3-helpers-misc.php';

/**
 * A submission may only link its draft to the submitter's own account, unless the submitter can edit other
 * authors' posts. Anonymous submissions link no account.
 *
 * @group wordcamp-forms-to-drafts
 */
class Test_Participant_Link extends WP_UnitTestCase {
	protected $plugin;
	protected static $contributor;
	protected static $editor;
	protected static $victim;

	/**
	 * Create the users the entitlement checks distinguish between.
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		self::$contributor = $factory->user->create( array( 'role' => 'contributor' ) );
		self::$editor      = $factory->user->create( array( 'role' => 'editor' ) );
		self::$victim      = $factory->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Grab the plugin instance under test.
	 */
	public function set_up() {
		parent::set_up();
		$this->plugin = $GLOBALS['wordcamp_forms_to_drafts'];
	}

	/**
	 * Create a submission post parented to a form page with the given WCFD key.
	 */
	protected function make_submission( $wcfd_key ): int {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_post_meta( $page_id, 'wcfd-key', $wcfd_key );

		return self::factory()->post->create( array(
			'post_parent' => $page_id,
			'post_status' => 'publish',
		) );
	}

	/**
	 * Return the most recent draft of the given post type, or null.
	 */
	protected function latest_draft( string $post_type ) {
		$posts = get_posts( array(
			'post_type'   => $post_type,
			'post_status' => 'draft',
			'numberposts' => 1,
		) );

		return $posts ? $posts[0] : null;
	}

	/**
	 * Run a Call for Volunteers submission as the given user and return the draft.
	 */
	private function submit_volunteer( int $acting_user, string $username ) {
		wp_set_current_user( $acting_user );

		$this->plugin->call_for_volunteers(
			$this->make_submission( 'call-for-volunteers' ),
			array(
				'Name'                   => 'Test Volunteer',
				'Email'                  => 'volunteer@example.org',
				'WordPress.org Username' => $username,
			),
			array()
		);

		return $this->latest_draft( 'wcb_volunteer' );
	}

	/**
	 * An anonymous submission links no account.
	 */
	public function test_anonymous_volunteer_links_no_account(): void {
		$draft = $this->submit_volunteer( 0, get_userdata( self::$victim )->user_login );

		$this->assertNotNull( $draft );
		$this->assertSame( '', get_post_meta( $draft->ID, '_wcpt_user_name', true ) );
	}

	/**
	 * A logged-in submitter links their own account.
	 */
	public function test_volunteer_links_own_account(): void {
		$login = get_userdata( self::$contributor )->user_login;
		$draft = $this->submit_volunteer( self::$contributor, $login );

		$this->assertSame( $login, get_post_meta( $draft->ID, '_wcpt_user_name', true ) );
	}

	/**
	 * Naming another account without permission leaves the draft unlinked.
	 */
	public function test_volunteer_without_permission_links_nothing(): void {
		$draft = $this->submit_volunteer( self::$contributor, get_userdata( self::$victim )->user_login );

		$this->assertSame( '', get_post_meta( $draft->ID, '_wcpt_user_name', true ) );
	}

	/**
	 * A logged-in submitter who names no account is linked to their own.
	 */
	public function test_volunteer_without_username_links_self(): void {
		$draft = $this->submit_volunteer( self::$contributor, '' );

		$this->assertSame( get_userdata( self::$contributor )->user_login, get_post_meta( $draft->ID, '_wcpt_user_name', true ) );
	}

	/**
	 * A submitter with `edit_others_posts` can link another account.
	 */
	public function test_editor_can_link_another_account(): void {
		$login = get_userdata( self::$victim )->user_login;
		$draft = $this->submit_volunteer( self::$editor, $login );

		$this->assertSame( $login, get_post_meta( $draft->ID, '_wcpt_user_name', true ) );
	}

	/**
	 * Run a Call for Speakers submission as the given user and return the draft.
	 */
	private function submit_speaker( int $acting_user, string $username ) {
		wp_set_current_user( $acting_user );

		$this->plugin->call_for_speakers(
			$this->make_submission( 'call-for-speakers' ),
			array(
				'Name'                   => 'Test Speaker',
				'Email Address'          => 'speaker@example.org',
				'WordPress.org Username' => $username,
				'Your Bio'               => 'A bio.',
				'Topic Title'            => 'A talk',
				'Topic Description'      => 'A description.',
			),
			array()
		);

		return $this->latest_draft( 'wcb_speaker' );
	}

	/**
	 * A speaker draft names no account when the submitter may not link the one they gave.
	 */
	public function test_speaker_without_permission_links_nothing(): void {
		$draft = $this->submit_speaker( self::$contributor, get_userdata( self::$victim )->user_login );

		$this->assertSame( 0, (int) get_post_meta( $draft->ID, '_wcpt_user_id', true ) );
	}

	/**
	 * Two unlinked submissions from one contributor create separate drafts, not one shared post.
	 */
	public function test_unlinked_speaker_submissions_do_not_collapse(): void {
		$this->submit_speaker( self::$contributor, get_userdata( self::$editor )->user_login );
		$this->submit_speaker( self::$contributor, get_userdata( self::$victim )->user_login );

		$drafts = get_posts( array(
			'post_type'   => 'wcb_speaker',
			'post_status' => 'draft',
			'numberposts' => -1,
		) );

		$this->assertCount( 2, $drafts );
	}

	/**
	 * An editor may link a speaker draft to another account.
	 */
	public function test_editor_can_link_speaker_to_another_account(): void {
		$draft = $this->submit_speaker( self::$editor, get_userdata( self::$victim )->user_login );

		$this->assertSame( self::$victim, (int) get_post_meta( $draft->ID, '_wcpt_user_id', true ) );
	}
}
