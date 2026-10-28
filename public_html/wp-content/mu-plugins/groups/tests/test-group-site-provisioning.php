<?php

namespace WordCamp\Groups\Tests;

use WP_Error;

use function WordCamp\Groups\Site_Provisioning\create_group_site;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__, 2 ) . '/wporg-groups-frontend/tests/class-groups-testcase.php';

/**
 * @group groups
 */
class Test_Group_Site_Provisioning extends Groups_TestCase {

	/**
	 * @var int
	 */
	protected static $organizer_id;

	/**
	 * @param \WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		parent::wpSetUpBeforeClass( $factory );

		self::$organizer_id = $factory->user->create( array( 'user_login' => 'grouporganizer' ) );
	}

	/**
	 * Happy path: a valid request creates a fully configured Group site.
	 */
	public function test_creates_configured_group_site() {
		$site_id = create_group_site( 'Narnia WordPress Meetup', 'narnia', 'grouporganizer', 'Australia/Brisbane' );

		$this->assertIsInt( $site_id );

		$site = get_site( $site_id );
		$this->assertSame( 'events.wordpress.test', $site->domain );
		$this->assertSame( '/group/narnia/', $site->path );

		switch_to_blog( $site_id );

		$this->assertSame( 'groups-site', get_stylesheet() );
		$this->assertSame( 'Australia/Brisbane', get_option( 'timezone_string' ) );

		$organizer = new \WP_User( self::$organizer_id );
		$this->assertContains( 'administrator', $organizer->roles );

		$members_page = get_page_by_path( 'members' );
		$this->assertNotNull( $members_page );
		$this->assertSame( 'publish', $members_page->post_status );

		// The About page is seeded as a draft: invisible to visitors until
		// published, but the organizer starts from example prose with an
		// editor's note at the end instead of a blank editor.
		$about_page = get_page_by_path( 'about' );
		$this->assertNotNull( $about_page );
		$this->assertSame( 'draft', $about_page->post_status );
		$this->assertSame( self::$organizer_id, (int) $about_page->post_author );
		$this->assertStringContainsString( 'all skill levels welcome', $about_page->post_content );
		$this->assertStringContainsString( '<em>Editor’s note:', $about_page->post_content );

		// The stock "Hello world!"/"Sample Page" boilerplate shouldn't survive --
		// a group site should start from the group template, not generic WP content.
		$this->assertNull( get_page_by_path( 'hello-world', OBJECT, 'post' ) );
		$this->assertNull( get_page_by_path( 'sample-page' ) );

		// Leaving `blogdescription` at its default is intentional -- it's
		// what triggers the existing "Set up your group" nudge in
		// `group-settings/render.php`.
		$this->assertSame( '', get_option( 'blogdescription' ) );

		restore_current_blog();
	}

	/**
	 * A slug that's already in use by another group is rejected, and no new
	 * site is created.
	 */
	public function test_rejects_duplicate_slug() {
		$existing_count = count( get_sites( array( 'network_id' => GROUPS_NETWORK_ID ) ) );

		$result = create_group_site( 'Duplicate Group', 'sunshine-coast-qld', 'grouporganizer' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'slug_taken', $result->get_error_code() );
		$this->assertCount( $existing_count, get_sites( array( 'network_id' => GROUPS_NETWORK_ID ) ) );
	}

	/**
	 * An unknown organizer username is rejected before any site is created.
	 */
	public function test_rejects_unknown_organizer() {
		$existing_count = count( get_sites( array( 'network_id' => GROUPS_NETWORK_ID ) ) );

		$result = create_group_site( 'Nowhere Group', 'nowhere', 'this-user-does-not-exist' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'organizer_not_found', $result->get_error_code() );
		$this->assertCount( $existing_count, get_sites( array( 'network_id' => GROUPS_NETWORK_ID ) ) );
	}

	/**
	 * An empty/unsanitizable slug is rejected before any site is created.
	 */
	public function test_rejects_invalid_slug() {
		$result = create_group_site( 'No Slug Group', '!!!', 'grouporganizer' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_slug', $result->get_error_code() );
	}

	/**
	 * A timezone that isn't a real PHP timezone identifier is rejected before
	 * any site is created.
	 */
	public function test_rejects_invalid_timezone() {
		$existing_count = count( get_sites( array( 'network_id' => GROUPS_NETWORK_ID ) ) );

		$result = create_group_site( 'Bad Timezone Group', 'bad-timezone', 'grouporganizer', 'Not/A_Real_Zone' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_timezone', $result->get_error_code() );
		$this->assertCount( $existing_count, get_sites( array( 'network_id' => GROUPS_NETWORK_ID ) ) );
	}
}
