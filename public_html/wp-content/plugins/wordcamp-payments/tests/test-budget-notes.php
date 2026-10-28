<?php

namespace WordCamp\Budgets\Tests;

use UnexpectedValueException;
use WPDieException;
use WP_UnitTestCase;
use WordCamp_Budget_Tool;

defined( 'WPINC' ) || die();

/**
 * The deputies' notes thread on the budget page (#1023).
 *
 * Notes are internal to deputies and program managers, and live in their own option so that nothing
 * the budget form does can wipe them.
 *
 * @group budgets
 */
class Test_Budget_Notes extends WP_UnitTestCase {
	/** @var int A camp admin, who can edit the budget but not approve it. */
	protected static $organizer;

	/** @var int A trusted deputy, who isn't a super admin. */
	protected static $deputy;

	/** @var int A network admin. */
	protected static $network_admin;

	/**
	 * @param \WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$organizer     = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$deputy        = $factory->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'Dee Puty',
			)
		);
		self::$network_admin = $factory->user->create(
			array(
				'role'       => 'administrator',
				'user_login' => 'budget-network-admin',
			)
		);
	}

	/**
	 * Register the deputy and the super admin, and keep render() off the network.
	 */
	public function set_up() {
		parent::set_up();

		$GLOBALS['trusted_deputies'] = array( self::$deputy );         // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Unset in tear_down().
		$GLOBALS['super_admins']     = array( 'budget-network-admin' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Unset in tear_down().

		set_site_transient( 'wcb-inspire-urls', array( 'https://example.org/wapuu.png' ) );
	}

	/**
	 * Clear the request, the globals and the stored budget.
	 */
	public function tear_down() {
		unset( $GLOBALS['trusted_deputies'], $GLOBALS['super_admins'] );
		$_POST    = array();
		$_REQUEST = array();
		delete_option( 'wcb_budget' );
		delete_option( WordCamp_Budget_Tool::NOTES_OPTION );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Deputies and network admins can use the notes. A camp admin, the organizer, can't.
	 */
	public function test_only_deputies_and_network_admins_manage_notes() {
		wp_set_current_user( self::$organizer );
		$this->assertTrue( current_user_can( 'manage_options' ), 'the organizer can edit the budget' );
		$this->assertFalse( WordCamp_Budget_Tool::can_manage_notes() );

		wp_set_current_user( self::$deputy );
		$this->assertTrue( WordCamp_Budget_Tool::can_manage_notes() );

		wp_set_current_user( self::$network_admin );
		$this->assertTrue( WordCamp_Budget_Tool::can_manage_notes() );
	}

	/**
	 * A note records who wrote it, when, and the budget's status at the time. Line breaks are kept,
	 * markup isn't.
	 */
	public function test_add_note_records_author_time_and_status() {
		$this->store_budget( 'pending' );

		$this->assertTrue( WordCamp_Budget_Tool::add_note( "Venue quote checked.\n<b>Catering</b> to follow.", self::$deputy ) );

		$notes = WordCamp_Budget_Tool::get_notes();

		$this->assertCount( 1, $notes );
		$this->assertSame( self::$deputy, $notes[0]['author_id'] );
		$this->assertSame( 'pending', $notes[0]['status'] );
		$this->assertSame( "Venue quote checked.\nCatering to follow.", $notes[0]['message'] );
		$this->assertEqualsWithDelta( time(), $notes[0]['timestamp'], 5 );
	}

	/**
	 * Notes stack up oldest first, and adding one doesn't touch the budget.
	 */
	public function test_notes_append_without_changing_the_budget() {
		$this->store_budget( 'approved' );
		$budget = get_option( 'wcb_budget' );

		WordCamp_Budget_Tool::add_note( 'First', self::$deputy );
		WordCamp_Budget_Tool::add_note( 'Second', self::$network_admin );

		$this->assertSame( array( 'First', 'Second' ), wp_list_pluck( WordCamp_Budget_Tool::get_notes(), 'message' ) );
		$this->assertSame( $budget, get_option( 'wcb_budget' ) );
	}

	/**
	 * An empty or whitespace-only note isn't stored.
	 *
	 * @dataProvider data_empty_notes
	 *
	 * @param string $message
	 */
	public function test_empty_note_is_not_added( $message ) {
		$this->assertFalse( WordCamp_Budget_Tool::add_note( $message, self::$deputy ) );
		$this->assertSame( array(), WordCamp_Budget_Tool::get_notes() );
	}

	/**
	 * @return array
	 */
	public function data_empty_notes() {
		return array(
			'empty'       => array( '' ),
			'whitespace'  => array( "  \n\t " ),
			'only markup' => array( '<p></p>' ),
		);
	}

	/**
	 * Every way the budget form saves leaves the notes as they were.
	 *
	 * @dataProvider data_budget_saves
	 *
	 * @param string $status   The budget's status before the save.
	 * @param string $button   The submit button pressed.
	 * @param string $expected The budget's status after the save, to show it ran.
	 */
	public function test_budget_saves_keep_the_notes( $status, $button, $expected ) {
		$this->store_budget( $status );
		$lines = get_option( 'wcb_budget' )['prelim'];

		wp_set_current_user( self::$network_admin );
		WordCamp_Budget_Tool::add_note( 'Kept through every save.', self::$network_admin );
		$before = WordCamp_Budget_Tool::get_notes();

		$_POST['_wcb_budget_data'] = wp_slash( wp_json_encode( $lines ) );
		$_POST[ $button ]          = '1';
		$_REQUEST['_wpnonce']      = wp_create_nonce( 'wcb_budget_noop-options' );

		WordCamp_Budget_Tool::validate( null );

		$this->assertSame( $expected, get_option( 'wcb_budget' )['status'] );
		$this->assertSame( $before, WordCamp_Budget_Tool::get_notes() );
	}

	/**
	 * @return array
	 */
	public function data_budget_saves() {
		return array(
			'save draft'     => array( 'draft', 'wcb-budget-save-draft', 'draft' ),
			'reject'         => array( 'pending', 'wcb-budget-reject', 'draft' ),
			'approve'        => array( 'pending', 'wcb-budget-approve', 'approved' ),
			'update working' => array( 'approved', 'wcb-budget-update-working', 'approved' ),
			'reset working'  => array( 'approved', 'wcb-budget-reset', 'approved' ),
		);
	}

	/**
	 * A deputy sees the thread, its notes and the form on the budget page.
	 */
	public function test_deputy_sees_the_notes_on_the_budget_page() {
		$this->store_budget( 'approved' );
		WordCamp_Budget_Tool::add_note( 'Deputy-only remark', self::$deputy );

		wp_set_current_user( self::$deputy );
		$html = $this->render_page();

		$this->assertStringContainsString( 'class="wcb-budget-notes"', $html );
		$this->assertStringContainsString( 'Deputy-only', $html );
		$this->assertStringContainsString( 'Dee Puty', $html );
		$this->assertStringContainsString( 'name="action" value="wcb_budget_add_note"', $html );
		$this->assertStringContainsString( 'name="wcb-view" value="approved"', $html );
		$this->assertSame( 1, substr_count( $html, 'id="_wpnonce"' ), 'one #_wpnonce on the page, the budget form\'s, which heartbeat refreshes' );
	}

	/**
	 * The thread escapes what it prints, even a note that reached the option without going through
	 * add_note(), and keeps its line breaks.
	 */
	public function test_notes_are_escaped_on_output() {
		$this->store_budget( 'approved' );
		update_option(
			WordCamp_Budget_Tool::NOTES_OPTION,
			array(
				array(
					'timestamp' => time(),
					'author_id' => self::$deputy,
					'message'   => "<script>alert(1)</script>\nSecond line",
					'status'    => 'approved',
				),
			)
		);

		wp_set_current_user( self::$deputy );
		$html = $this->render_page();

		$this->assertStringNotContainsString( '<script>alert(1)', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;<br />', $html );
	}

	/**
	 * The organizer sees neither the notes nor the form. This also fails if the notes are moved into
	 * `wcb_budget`, which the page prints whole into a hidden field.
	 */
	public function test_organizer_does_not_see_the_notes() {
		$this->store_budget( 'approved' );
		WordCamp_Budget_Tool::add_note( 'Deputy-only remark', self::$deputy );

		wp_set_current_user( self::$organizer );
		$html = $this->render_page();

		$this->assertStringContainsString( 'wcb-budget-tool', $html, 'the budget page itself rendered' );
		$this->assertStringNotContainsString( 'wcb-budget-notes', $html );
		$this->assertStringNotContainsString( 'Deputy-only remark', $html );
	}

	/**
	 * The form handler refuses anyone who isn't a deputy, even with a valid nonce.
	 */
	public function test_handler_refuses_the_organizer() {
		wp_set_current_user( self::$organizer );
		$this->fill_note_request( 'Sneaky note' );

		$this->assert_handler_dies();
	}

	/**
	 * The form handler refuses a request without its nonce.
	 */
	public function test_handler_requires_the_nonce() {
		wp_set_current_user( self::$deputy );
		$this->fill_note_request( 'No nonce' );
		unset( $_REQUEST['wcb_budget_note_nonce'], $_POST['wcb_budget_note_nonce'] );

		$this->assert_handler_dies();
	}

	/**
	 * A deputy's note is saved, and they go back to the tab they added it on.
	 */
	public function test_handler_saves_and_returns_to_the_tab() {
		wp_set_current_user( self::$deputy );
		$this->fill_note_request( "It\'s fine.", 'working' );

		$location = $this->capture_redirect( array( WordCamp_Budget_Tool::class, 'handle_add_note' ) );

		$this->assertSame( array( "It's fine." ), wp_list_pluck( WordCamp_Budget_Tool::get_notes(), 'message' ), 'the note is unslashed' );
		$this->assertStringContainsString( 'page=wordcamp-budget', $location );
		$this->assertStringContainsString( 'wcb-view=working', $location );
		$this->assertStringNotContainsString( 'wcb-note', $location, 'the result is not in the URL, where tab links and the budget form would carry it on' );
	}

	/**
	 * "Note added." shows on the page the deputy lands on, and not again after they save or approve.
	 */
	public function test_note_added_notice_shows_once() {
		$this->store_budget( 'approved' );
		wp_set_current_user( self::$deputy );
		$this->fill_note_request( 'Looks good', 'approved' );
		$this->capture_redirect( array( WordCamp_Budget_Tool::class, 'handle_add_note' ) );

		$this->assertStringContainsString( 'Note added.', $this->render_page() );
		$this->assertStringNotContainsString( 'Note added.', $this->render_page(), 'the next page load' );
	}

	/**
	 * An empty note gets the warning instead.
	 */
	public function test_empty_note_notice() {
		$this->store_budget( 'approved' );
		wp_set_current_user( self::$deputy );
		$this->fill_note_request( '   ' );
		$this->capture_redirect( array( WordCamp_Budget_Tool::class, 'handle_add_note' ) );

		$html = $this->render_page();

		$this->assertStringContainsString( 'The note was empty', $html );
		$this->assertStringNotContainsString( 'Note added.', $html );
	}

	/**
	 * A tab name that isn't one of the budget's tabs is left off the redirect.
	 */
	public function test_handler_ignores_an_unknown_tab() {
		wp_set_current_user( self::$deputy );
		$this->fill_note_request( 'Note', 'elsewhere' );

		$location = $this->capture_redirect( array( WordCamp_Budget_Tool::class, 'handle_add_note' ) );

		$this->assertStringNotContainsString( 'wcb-view', $location );
	}

	/**
	 * Store a budget with one expense line in every list.
	 *
	 * @param string $status
	 */
	protected function store_budget( $status ) {
		$lines = array(
			array(
				'type'     => 'expense',
				'category' => 'venue',
				'note'     => 'Venue',
				'amount'   => 500,
			),
		);

		update_option(
			'wcb_budget',
			array(
				'status'   => $status,
				'prelim'   => $lines,
				'approved' => $lines,
				'working'  => $lines,
			)
		);
	}

	/**
	 * Fill the request the notes form posts, with a valid nonce for the current user.
	 *
	 * @param string $message Slashed, as PHP delivers it.
	 * @param string $view
	 */
	protected function fill_note_request( $message, $view = 'approved' ) {
		$_POST = array(
			'action'                => 'wcb_budget_add_note',
			'wcb_budget_note'       => $message,
			'wcb-view'              => $view,
			'wcb_budget_note_nonce' => wp_create_nonce( 'wcb_budget_add_note' ),
		);

		$_REQUEST = $_POST;
	}

	/**
	 * Assert that the notes handler stops with wp_die() and saves nothing. A redirect is trapped, so a
	 * missing check fails the test instead of exiting.
	 */
	protected function assert_handler_dies() {
		$redirected = function ( $location ) {
			throw new UnexpectedValueException( esc_url_raw( $location ) );
		};
		add_filter( 'wp_redirect', $redirected );

		try {
			WordCamp_Budget_Tool::handle_add_note();
			$this->fail( 'The handler should have stopped.' );
		} catch ( WPDieException $exception ) {
			$this->assertSame( array(), WordCamp_Budget_Tool::get_notes() );
		} catch ( UnexpectedValueException $exception ) {
			$this->fail( 'The handler saved and redirected to ' . $exception->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $redirected );
		}
	}

	/**
	 * Run a handler that redirects and exits, and return where it redirected to.
	 *
	 * @param callable $handler
	 *
	 * @return string
	 */
	protected function capture_redirect( $handler ) {
		$stop = function ( $location ) {
			throw new UnexpectedValueException( esc_url_raw( $location ) );
		};
		add_filter( 'wp_redirect', $stop );

		try {
			call_user_func( $handler );
			$this->fail( 'The handler should have redirected.' );
		} catch ( UnexpectedValueException $exception ) {
			return $exception->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $stop );
		}
	}

	/**
	 * Render the budget page and return its HTML.
	 *
	 * @return string
	 */
	protected function render_page() {
		ob_start();
		WordCamp_Budget_Tool::render();

		return ob_get_clean();
	}
}
