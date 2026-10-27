<?php

namespace WordCamp\Groups\Tests;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/../../wporg-groups-frontend/tests/class-groups-testcase.php';

/**
 * Coverage for the "Become an organizer" callout on the single event page.
 *
 * Someone browsing an event had no visible route to find out how to help run
 * or start a group (#2039). The callout points them at the Meetup Organizer
 * Handbook, and stays out of the way of people who already organize: anyone
 * who can manage events doesn't see it.
 *
 * @group groups
 */
class Test_Groups_Site_Become_Organizer extends Groups_TestCase {

	const THEME_DIR = SUT_WP_CONTENT_DIR . 'themes/groups-site/';

	const HANDBOOK_URL = 'https://make.wordpress.org/community/handbook/meetup-organizer/';

	/**
	 * Render the pattern file the way WordPress does: include it and capture
	 * its output, so its capability check runs as the current user.
	 */
	private function render_pattern(): string {
		ob_start();
		include self::THEME_DIR . 'patterns/become-organizer.php';

		return ob_get_clean();
	}

	/**
	 * Visitors and members see the invitation.
	 *
	 * @dataProvider data_non_organizers
	 *
	 * @param string $role The user's role, or empty for a logged-out visitor.
	 */
	public function test_non_organizers_see_the_callout( string $role ) {
		if ( $role ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
		}

		$html = $this->render_pattern();

		$this->assertStringContainsString( 'groups-site-become-organizer', $html );
		$this->assertStringContainsString( 'href="' . self::HANDBOOK_URL . '"', $html );
	}

	/**
	 * Data provider for test_non_organizers_see_the_callout().
	 */
	public function data_non_organizers(): array {
		return array(
			'logged-out visitor' => array( '' ),
			'subscriber'         => array( 'subscriber' ),
		);
	}

	/**
	 * People who can already manage events are organizers; the callout is
	 * noise for them.
	 *
	 * @dataProvider data_organizers
	 *
	 * @param string $role The user's role.
	 */
	public function test_organizers_do_not_see_the_callout( string $role ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );

		$this->assertSame( '', trim( $this->render_pattern() ) );
	}

	/**
	 * Data provider for test_organizers_do_not_see_the_callout().
	 */
	public function data_organizers(): array {
		return array(
			'event organizer (author)' => array( 'author' ),
			'organizer (editor)'       => array( 'editor' ),
			'administrator'            => array( 'administrator' ),
		);
	}

	/**
	 * The single event template pulls the callout in.
	 */
	public function test_single_event_template_references_the_pattern() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a template file from disk, as test-groups-site-event-info-card.php does.
		$markup = file_get_contents( self::THEME_DIR . 'templates/single-event.html' );

		$this->assertStringContainsString( '<!-- wp:pattern {"slug":"groups-site/become-organizer"} /-->', $markup );
	}

	/**
	 * The footer's organizer link goes to the same handbook.
	 */
	public function test_footer_links_to_the_organizer_handbook() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a template part from disk, as test-groups-site-event-info-card.php does.
		$markup = file_get_contents( self::THEME_DIR . 'parts/footer.html' );

		$this->assertStringContainsString( 'href="' . self::HANDBOOK_URL . '">Become an organizer', $markup );
	}
}
