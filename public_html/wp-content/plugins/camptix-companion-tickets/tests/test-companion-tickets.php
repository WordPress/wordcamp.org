<?php

namespace CampTix\CompanionTickets\Tests;

use WP_UnitTestCase;
use CampTix_Companion_Tickets_Addon;

/**
 * Unit/integration tests for the Companion Tickets addon.
 */
class Test_Companion_Tickets extends WP_UnitTestCase {
	use \CampTix_Root_Blog_Fixture;

	/**
	 * Provision the central WordCamp.org root blog.
	 *
	 * Saving a `tix_attendee` runs `CampTix_Plugin::is_wordcamp_closed()`, which calls
	 * `get_wordcamp_post()` and switches to `WORDCAMP_ROOT_BLOG_ID`. Without the site
	 * existing, every attendee save emits "table doesn't exist" DB errors -- which the
	 * repo's PHPUnit bootstrap treats as a build failure even when assertions pass.
	 *
	 * @param \WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::create_wordcamp_root_blog( $factory );
	}

	/**
	 * Tears down the shared fixtures created in wpSetUpBeforeClass().
	 */
	public static function wpTearDownAfterClass() {
		self::delete_wordcamp_root_blog();
	}

	/**
	 * Create a published tix_ticket with a price and quantity.
	 */
	private function make_ticket( $title = 'Main', $price = 0, $quantity = 100 ) {
		$id = self::factory()->post->create( array(
			'post_type'   => 'tix_ticket',
			'post_status' => 'publish',
			'post_title'  => $title,
		) );
		update_post_meta( $id, 'tix_price', $price );
		update_post_meta( $id, 'tix_quantity', $quantity );
		return $id;
	}

	/**
	 * Create a tix_attendee for a ticket + username with the given status.
	 */
	private function make_attendee( $ticket_id, $username, $status = 'publish' ) {
		$id = self::factory()->post->create( array(
			'post_type'   => 'tix_attendee',
			'post_status' => $status,
		) );
		update_post_meta( $id, 'tix_ticket_id', $ticket_id );
		update_post_meta( $id, 'tix_username', $username );
		return $id;
	}

	/**
	 * Configure the companion tickets (+ optional qualifying whitelist).
	 * get_options() caches in CampTix's protected $options, so refresh it.
	 */
	private function set_companion_config( $companion_ids, $qualifying = array() ) {
		global $camptix;
		$options                                 = get_option( 'camptix_options', array() );
		$options['camptix-companion-ticket-ids'] = array_map( 'absint', (array) $companion_ids );
		if ( ! empty( $qualifying ) ) {
			$options['camptix-companion-qualifying-ticket-ids'] = array_map( 'absint', $qualifying );
		}
		update_option( 'camptix_options', $options );
		$camptix->load_options();
	}

	/**
	 * Reset CampTix's notice buffers between assertions (global singleton).
	 */
	private function reset_notices() {
		global $camptix;

		// These reflection helpers deliberately omit setAccessible(): it is a no-op as of
		// PHP 8.1 and deprecated in 8.5, where the notice would trip this repo's
		// unexpected-error-log check. This repo targets 8.3+.
		$ref = new \ReflectionObject( $camptix );
		foreach ( array( 'notices', 'errors', 'infos' ) as $prop ) {
			if ( $ref->hasProperty( $prop ) ) {
				$p = $ref->getProperty( $prop );
				$p->setValue( $camptix, array() );
			}
		}
	}

	/**
	 * Read CampTix's buffered front-end notices (global singleton).
	 */
	private function get_notices() {
		global $camptix;
		$ref = new \ReflectionObject( $camptix );
		if ( ! $ref->hasProperty( 'notices' ) ) {
			return array();
		}
		$p = $ref->getProperty( 'notices' );
		return (array) $p->getValue( $camptix );
	}

	/**
	 * The addon class loads and registers.
	 */
	public function test_addon_class_exists() {
		$this->assertTrue( class_exists( 'CampTix_Companion_Tickets_Addon' ) );
	}

	/**
	 * The companion-ticket check reflects the configured set.
	 */
	public function test_is_companion_ticket() {
		$main   = $this->make_ticket( 'Main' );
		$cd     = $this->make_ticket( 'Contributor Day' );
		$dinner = $this->make_ticket( 'Social Dinner' );
		$this->set_companion_config( array( $cd, $dinner ) );

		$addon = new CampTix_Companion_Tickets_Addon();
		$this->assertTrue( $addon->is_companion_ticket( $cd ) );
		$this->assertTrue( $addon->is_companion_ticket( $dinner ) );
		$this->assertFalse( $addon->is_companion_ticket( $main ) );
	}

	/**
	 * A user holding a published non-companion ticket is eligible.
	 */
	public function test_user_with_published_main_ticket_is_eligible() {
		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );
		$this->make_attendee( $main, 'alice', 'publish' );

		$addon = new CampTix_Companion_Tickets_Addon();
		$found = $addon->get_user_main_attendee( 'alice' );
		$this->assertNotEmpty( $found );
		$this->assertSame( 'alice', get_post_meta( $found, 'tix_username', true ) );
	}

	/**
	 * A user with no main ticket is not eligible.
	 */
	public function test_user_without_published_main_ticket_is_not_eligible() {
		$cd = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );

		$addon = new CampTix_Companion_Tickets_Addon();
		$this->assertSame( 0, $addon->get_user_main_attendee( 'nobody' ) );
	}

	/**
	 * A draft main ticket does not count as held.
	 */
	public function test_draft_main_ticket_is_not_eligible() {
		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );
		$this->make_attendee( $main, 'alice', 'draft' );

		$addon = new CampTix_Companion_Tickets_Addon();
		$this->assertSame( 0, $addon->get_user_main_attendee( 'alice' ) );
	}

	/**
	 * The require-login "unconfirmed" sentinel never counts as a holder.
	 */
	public function test_unconfirmed_username_does_not_count() {
		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );
		$this->make_attendee( $main, '[[ unconfirmed ]]', 'publish' );

		$addon = new CampTix_Companion_Tickets_Addon();
		$this->assertSame( 0, $addon->get_user_main_attendee( '[[ unconfirmed ]]' ) );
	}

	/**
	 * Holding only a companion ticket does not make a user eligible.
	 */
	public function test_companion_ticket_does_not_qualify_as_main() {
		$cd     = $this->make_ticket( 'Contributor Day' );
		$dinner = $this->make_ticket( 'Social Dinner' );
		$this->set_companion_config( array( $cd, $dinner ) );
		// User holds a companion ticket but no real main ticket.
		$this->make_attendee( $cd, 'bob', 'publish' );

		$addon = new CampTix_Companion_Tickets_Addon();
		$this->assertSame( 0, $addon->get_user_main_attendee( 'bob' ) );
	}

	/**
	 * A qualifying-ticket whitelist restricts which holders are eligible.
	 */
	public function test_qualifying_whitelist_restricts_eligibility() {
		$allowed = $this->make_ticket( 'VIP' );
		$other   = $this->make_ticket( 'Other' );
		$cd      = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ), array( $allowed ) );

		$this->make_attendee( $other, 'erin', 'publish' );
		$addon = new CampTix_Companion_Tickets_Addon();
		$this->assertSame( 0, $addon->get_user_main_attendee( 'erin' ) );

		$this->make_attendee( $allowed, 'frank', 'publish' );
		$this->assertNotEmpty( $addon->get_user_main_attendee( 'frank' ) );
	}

	/**
	 * Registration detection is per companion ticket.
	 */
	public function test_user_has_registration_is_per_ticket() {
		$cd     = $this->make_ticket( 'Contributor Day' );
		$dinner = $this->make_ticket( 'Social Dinner' );
		$this->set_companion_config( array( $cd, $dinner ) );
		$this->make_attendee( $cd, 'carol', 'publish' );

		$addon = new CampTix_Companion_Tickets_Addon();
		$this->assertTrue( $addon->user_has_registration( 'carol', $cd ) );
		$this->assertFalse( $addon->user_has_registration( 'carol', $dinner ) );
	}

	/**
	 * Submitted companion ids are sanitised to ints.
	 */
	public function test_validate_options_sanitizes_companion_ids() {
		$addon = new CampTix_Companion_Tickets_Addon();
		$out   = $addon->validate_options(
			array(),
			array( 'camptix-companion-ticket-ids' => array( '7', 0, 'x', '9' ) )
		);
		$this->assertSame( array( 7, 9 ), $out['camptix-companion-ticket-ids'] );
	}

	/**
	 * A companion ticket is never saved into the qualifying whitelist.
	 */
	public function test_validate_options_strips_companion_from_qualifying() {
		$addon = new CampTix_Companion_Tickets_Addon();
		$out   = $addon->validate_options(
			array(),
			array(
				'camptix-companion-ticket-ids'            => array( 5, 6 ),
				'camptix-companion-qualifying-ticket-ids' => array( 5, 7, 9 ),
			)
		);
		$this->assertSame( array( 5, 6 ), $out['camptix-companion-ticket-ids'] );
		$this->assertSame( array( 7, 9 ), $out['camptix-companion-qualifying-ticket-ids'] );
	}

	/**
	 * An all-unchecked UI save clears the companion list (marker present, no array).
	 */
	public function test_validate_options_clears_companions_on_empty_ui_save() {
		$addon = new CampTix_Companion_Tickets_Addon();
		$out   = $addon->validate_options(
			array( 'camptix-companion-ticket-ids' => array( 7, 9 ) ),
			array( 'camptix-companion-tickets-rendered' => '1' )
		);
		$this->assertSame( array(), $out['camptix-companion-ticket-ids'] );
	}

	/**
	 * The gate blocks a companion registration when the user has no main ticket.
	 */
	public function test_gate_blocks_when_no_main_ticket() {
		$cd = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );

		$addon = new CampTix_Companion_Tickets_Addon();
		$this->assertSame( CampTix_Companion_Tickets_Addon::REQUIRES_FLAG, $addon->should_block_companion_attendee( $cd, 'stranger' ) );
	}

	/**
	 * The gate allows a companion registration for a qualifying ticket-holder.
	 */
	public function test_gate_allows_when_user_holds_main_ticket() {
		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );
		$this->make_attendee( $main, 'alice', 'publish' );

		$addon = new CampTix_Companion_Tickets_Addon();
		$this->assertSame( '', $addon->should_block_companion_attendee( $cd, 'alice' ) );
	}

	/**
	 * The gate rejects an unconfirmed / non-buyer seat (self-service only).
	 */
	public function test_gate_blocks_unconfirmed_attendee() {
		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );
		$this->make_attendee( $main, 'alice', 'publish' );

		$addon = new CampTix_Companion_Tickets_Addon();
		$this->assertSame( CampTix_Companion_Tickets_Addon::SELF_ONLY_FLAG, $addon->should_block_companion_attendee( $cd, '[[ unconfirmed ]]' ) );
	}

	/**
	 * The gate rejects an anonymous (empty-username) seat.
	 */
	public function test_gate_blocks_empty_username() {
		$cd = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );

		$addon = new CampTix_Companion_Tickets_Addon();
		$this->assertSame( CampTix_Companion_Tickets_Addon::SELF_ONLY_FLAG, $addon->should_block_companion_attendee( $cd, '' ) );
	}

	/**
	 * The gate blocks a duplicate registration, per companion ticket.
	 */
	public function test_gate_blocks_duplicate_per_ticket() {
		$main   = $this->make_ticket( 'Main' );
		$cd     = $this->make_ticket( 'Contributor Day' );
		$dinner = $this->make_ticket( 'Social Dinner' );
		$this->set_companion_config( array( $cd, $dinner ) );
		$this->make_attendee( $main, 'alice', 'publish' );
		$this->make_attendee( $cd, 'alice', 'publish' );

		$addon = new CampTix_Companion_Tickets_Addon();
		// Already has Contributor Day → blocked for it...
		$this->assertSame( CampTix_Companion_Tickets_Addon::DUPLICATE_FLAG, $addon->should_block_companion_attendee( $cd, 'alice' ) );
		// ...but still allowed for the Social Dinner (independent).
		$this->assertSame( '', $addon->should_block_companion_attendee( $dinner, 'alice' ) );
	}

	/**
	 * The gate never interferes with non-companion tickets.
	 */
	public function test_gate_ignores_non_companion_tickets() {
		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );

		$addon = new CampTix_Companion_Tickets_Addon();
		$this->assertSame( '', $addon->should_block_companion_attendee( $main, 'anyone' ) );
	}

	/**
	 * Linking a companion attendee writes the forward pointer to the main.
	 */
	public function test_link_writes_primary_pointer() {
		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );
		$main_id = $this->make_attendee( $main, 'alice', 'publish' );

		$cd_id = self::factory()->post->create( array(
			'post_type'   => 'tix_attendee',
			'post_status' => 'draft',
		) );
		update_post_meta( $cd_id, 'tix_ticket_id', $cd );
		update_post_meta( $cd_id, 'tix_username', 'alice' );

		$addon    = new CampTix_Companion_Tickets_Addon();
		$attendee = (object) array( 'ticket_id' => $cd );
		$addon->link_companion_attendee( $cd_id, $attendee );

		$this->assertSame( $main_id, absint( get_post_meta( $cd_id, 'tix_companion_primary_attendee_id', true ) ) );
	}

	/**
	 * Linking resolves the username from the attendee object, not stale meta.
	 */
	public function test_link_prefers_attendee_object_username() {
		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );
		$main_id = $this->make_attendee( $main, 'alice', 'publish' );

		$cd_id = self::factory()->post->create( array(
			'post_type'   => 'tix_attendee',
			'post_status' => 'draft',
		) );
		update_post_meta( $cd_id, 'tix_ticket_id', $cd );
		update_post_meta( $cd_id, 'tix_username', 'mallory' );

		$addon    = new CampTix_Companion_Tickets_Addon();
		$attendee = (object) array(
			'ticket_id' => $cd,
			'username'  => 'alice',
		);
		$addon->link_companion_attendee( $cd_id, $attendee );

		$this->assertSame( $main_id, absint( get_post_meta( $cd_id, 'tix_companion_primary_attendee_id', true ) ) );
	}

	/**
	 * Linking is a no-op for a non-companion attendee.
	 */
	public function test_link_ignores_non_companion_attendee() {
		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );
		$main_id = $this->make_attendee( $main, 'alice', 'publish' );

		$addon    = new CampTix_Companion_Tickets_Addon();
		$attendee = (object) array( 'ticket_id' => $main );
		$addon->link_companion_attendee( $main_id, $attendee );

		$this->assertSame( '', (string) get_post_meta( $main_id, 'tix_companion_primary_attendee_id', true ) );
	}

	/**
	 * Refunding the main attendee cancels ALL of its linked companion seats.
	 */
	public function test_refunding_main_cancels_all_linked_companions() {
		$main   = $this->make_ticket( 'Main' );
		$cd     = $this->make_ticket( 'Contributor Day' );
		$dinner = $this->make_ticket( 'Social Dinner' );
		$this->set_companion_config( array( $cd, $dinner ) );
		$main_id   = $this->make_attendee( $main, 'alice', 'publish' );
		$cd_id     = $this->make_attendee( $cd, 'alice', 'publish' );
		$dinner_id = $this->make_attendee( $dinner, 'alice', 'publish' );
		update_post_meta( $cd_id, 'tix_companion_primary_attendee_id', $main_id );
		update_post_meta( $dinner_id, 'tix_companion_primary_attendee_id', $main_id );

		$addon = new CampTix_Companion_Tickets_Addon();
		add_action( 'transition_post_status', array( $addon, 'maybe_cascade_cancel' ), 10, 3 );

		wp_update_post( array(
			'ID'          => $main_id,
			'post_status' => 'refund',
		) );

		$this->assertSame( 'cancel', get_post_status( $cd_id ) );
		$this->assertSame( 'cancel', get_post_status( $dinner_id ) );

		remove_action( 'transition_post_status', array( $addon, 'maybe_cascade_cancel' ), 10 );
	}

	/**
	 * Cancelling a companion attendee does not recurse into the cascade.
	 */
	public function test_cancelling_companion_does_not_recurse() {
		$cd = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );
		$cd_id = $this->make_attendee( $cd, 'alice', 'publish' );

		$addon = new CampTix_Companion_Tickets_Addon();
		$addon->maybe_cascade_cancel( 'cancel', 'publish', get_post( $cd_id ) );
		$this->assertTrue( true );
	}

	/**
	 * The admin label reads correctly from the companion side and lists the
	 * companion registrations from the main side.
	 */
	public function test_link_label_both_sides() {
		$main   = $this->make_ticket( 'Main' );
		$cd     = $this->make_ticket( 'Contributor Day' );
		$dinner = $this->make_ticket( 'Social Dinner' );
		$this->set_companion_config( array( $cd, $dinner ) );
		$main_id   = $this->make_attendee( $main, 'alice', 'publish' );
		$cd_id     = $this->make_attendee( $cd, 'alice', 'publish' );
		$dinner_id = $this->make_attendee( $dinner, 'alice', 'publish' );
		update_post_meta( $cd_id, 'tix_companion_primary_attendee_id', $main_id );
		update_post_meta( $dinner_id, 'tix_companion_primary_attendee_id', $main_id );

		$addon = new CampTix_Companion_Tickets_Addon();

		// Companion side points at the main attendee id.
		$this->assertStringContainsString( (string) $main_id, $addon->get_link_label( $cd_id ) );

		// Main side lists both companion registrations.
		$main_label = $addon->get_link_label( $main_id );
		$this->assertStringContainsString( (string) $cd_id, $main_label );
		$this->assertStringContainsString( (string) $dinner_id, $main_label );

		// Unrelated attendee → no label.
		$this->assertSame( '', $addon->get_link_label( $this->make_attendee( $main, 'bob', 'publish' ) ) );
	}

	/**
	 * A cancelled companion seat is not listed on the main attendee's label.
	 */
	public function test_link_label_excludes_cancelled_companion() {
		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );
		$main_id = $this->make_attendee( $main, 'alice', 'publish' );
		$cd_id   = $this->make_attendee( $cd, 'alice', 'cancel' );
		update_post_meta( $cd_id, 'tix_companion_primary_attendee_id', $main_id );

		$addon = new CampTix_Companion_Tickets_Addon();
		$this->assertSame( '', $addon->get_link_label( $main_id ) );
	}

	/**
	 * The whitelist UI offers non-companion tickets but never companion ones.
	 */
	public function test_render_qualifying_select_excludes_companions() {
		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );

		$addon = new CampTix_Companion_Tickets_Addon();
		ob_start();
		$addon->render_qualifying_select();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'camptix-companion-qualifying-rendered', $html );
		$this->assertStringContainsString( 'camptix-companion-qualifying-ticket-ids][]" value="' . $main . '"', $html );
		$this->assertStringNotContainsString( 'camptix-companion-qualifying-ticket-ids][]" value="' . $cd . '"', $html );
	}

	/**
	 * The companion-tickets UI offers every ticket and carries the clear marker.
	 */
	public function test_render_companion_select_lists_tickets() {
		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );

		$addon = new CampTix_Companion_Tickets_Addon();
		ob_start();
		$addon->render_companion_select();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'camptix-companion-tickets-rendered', $html );
		$this->assertStringContainsString( 'camptix-companion-ticket-ids][]" value="' . $main . '"', $html );
		$this->assertStringContainsString( 'camptix-companion-ticket-ids][]" value="' . $cd . '"', $html );
	}

	/**
	 * The selection-screen notice prompts a visitor with no qualifying ticket.
	 */
	public function test_eligibility_notice_shown_for_ineligible_user() {
		global $camptix;
		$cd = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );
		unset( $_REQUEST['tix_action'] );
		wp_set_current_user( 0 );
		$this->reset_notices();

		$addon = new CampTix_Companion_Tickets_Addon();
		$addon->maybe_show_eligibility_notice();

		ob_start();
		$camptix->do_notices();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'already have an event ticket', $html );
		$this->assertStringContainsString( 'Contributor Day', $html );
	}

	/**
	 * The selection-screen notice is suppressed for an eligible ticket-holder.
	 */
	public function test_eligibility_notice_hidden_for_eligible_user() {
		global $camptix;
		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );
		$uid = self::factory()->user->create( array( 'user_login' => 'gwen' ) );
		$this->make_attendee( $main, 'gwen', 'publish' );
		unset( $_REQUEST['tix_action'] );
		wp_set_current_user( $uid );
		$this->reset_notices();

		$addon = new CampTix_Companion_Tickets_Addon();
		$addon->maybe_show_eligibility_notice();

		ob_start();
		$camptix->do_notices();
		$html = ob_get_clean();
		$this->assertStringNotContainsString( 'already have an event ticket', $html );
	}

	/**
	 * An eligible holder who already registered sees an "already registered"
	 * notice for that activity ticket, not a "buy a ticket first" prompt.
	 */
	public function test_eligibility_notice_shows_already_registered() {
		global $camptix;
		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );
		$uid = self::factory()->user->create( array( 'user_login' => 'hank' ) );
		$this->make_attendee( $main, 'hank', 'publish' );
		$this->make_attendee( $cd, 'hank', 'publish' );
		unset( $_REQUEST['tix_action'] );
		wp_set_current_user( $uid );
		$this->reset_notices();

		$addon = new CampTix_Companion_Tickets_Addon();
		$addon->maybe_show_eligibility_notice();

		ob_start();
		$camptix->do_notices();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'already registered for', $html );
		$this->assertStringContainsString( 'Contributor Day', $html );
		$this->assertStringNotContainsString( 'already have an event ticket', $html );
	}

	/**
	 * The remaining-column label reads "Already registered" for a held activity
	 * ticket, and is left untouched otherwise.
	 */
	public function test_remaining_label_for_held_companion() {
		$cd     = $this->make_ticket( 'Contributor Day' );
		$dinner = $this->make_ticket( 'Social Dinner' );
		$main   = $this->make_ticket( 'Main' );
		$this->set_companion_config( array( $cd, $dinner ) );
		$uid = self::factory()->user->create( array( 'user_login' => 'ivy' ) );
		$this->make_attendee( $cd, 'ivy', 'publish' );
		wp_set_current_user( $uid );

		$addon = new CampTix_Companion_Tickets_Addon();

		// Held companion ticket → label replaced.
		$this->assertSame( 'Already registered', $addon->maybe_label_already_registered( 5, (object) array( 'ID' => $cd ) ) );
		// Companion ticket not held → unchanged.
		$this->assertSame( 5, $addon->maybe_label_already_registered( 5, (object) array( 'ID' => $dinner ) ) );
		// Non-companion ticket → unchanged.
		$this->assertSame( 5, $addon->maybe_label_already_registered( 5, (object) array( 'ID' => $main ) ) );
	}

	/**
	 * The export declares the two link columns.
	 */
	public function test_export_extra_columns_added() {
		$addon   = new CampTix_Companion_Tickets_Addon();
		$columns = $addon->export_extra_columns( array() );
		$this->assertArrayHasKey( 'companion_of', $columns );
		$this->assertArrayHasKey( 'companion_tickets', $columns );
	}

	/**
	 * The "Activity of" export cell holds the linked main attendee ID for a
	 * companion row, and is empty for a main row.
	 */
	public function test_export_column_companion_of() {
		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );
		$main_id = $this->make_attendee( $main, 'alice', 'publish' );
		$cd_id   = $this->make_attendee( $cd, 'alice', 'publish' );
		update_post_meta( $cd_id, 'tix_companion_primary_attendee_id', $main_id );

		$addon = new CampTix_Companion_Tickets_Addon();
		$this->assertSame( (string) $main_id, $addon->export_column_companion_of( '', get_post( $cd_id ) ) );
		$this->assertSame( '', $addon->export_column_companion_of( '', get_post( $main_id ) ) );
	}

	/**
	 * The "Activity tickets" export cell lists a main attendee's live companions.
	 */
	public function test_export_column_companion_tickets() {
		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );
		$main_id = $this->make_attendee( $main, 'alice', 'publish' );
		$cd_id   = $this->make_attendee( $cd, 'alice', 'publish' );
		update_post_meta( $cd_id, 'tix_companion_primary_attendee_id', $main_id );

		$addon = new CampTix_Companion_Tickets_Addon();
		$value = $addon->export_column_companion_tickets( '', get_post( $main_id ) );
		$this->assertStringContainsString( 'Contributor Day', $value );
		$this->assertStringContainsString( (string) $cd_id, $value );
		// A companion row has no sub-companions → empty.
		$this->assertSame( '', $addon->export_column_companion_tickets( '', get_post( $cd_id ) ) );
	}

	/**
	 * Confirming a companion attendee records that its confirmation email was sent.
	 */
	public function test_confirmation_email_sets_sent_flag() {
		$cd                 = $this->make_ticket( 'Contributor Day' );
		$opts               = get_option( 'camptix_options', array() );
		$opts['event_name'] = 'Test Event';
		update_option( 'camptix_options', $opts );
		$this->set_companion_config( array( $cd ) );

		$cd_id = $this->make_attendee( $cd, 'alice', 'draft' );
		update_post_meta( $cd_id, 'tix_email', 'alice@example.test' );
		update_post_meta( $cd_id, 'tix_first_name', 'Alice' );
		update_post_meta( $cd_id, 'tix_edit_token', 'tok123' );

		$addon = new CampTix_Companion_Tickets_Addon();
		$addon->maybe_send_activity_confirmation( 'publish', 'draft', get_post( $cd_id ) );

		$this->assertSame( '1', (string) get_post_meta( $cd_id, 'tix_companion_confirmation_sent', true ) );
	}

	/**
	 * No confirmation email is sent for a non-companion attendee.
	 */
	public function test_confirmation_email_skipped_for_non_companion() {
		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );

		$main_id = $this->make_attendee( $main, 'alice', 'draft' );
		update_post_meta( $main_id, 'tix_email', 'alice@example.test' );

		$addon = new CampTix_Companion_Tickets_Addon();
		$addon->maybe_send_activity_confirmation( 'publish', 'draft', get_post( $main_id ) );

		$this->assertSame( '', (string) get_post_meta( $main_id, 'tix_companion_confirmation_sent', true ) );
	}

	/**
	 * The flag slug matches the admin-flags settings parser's sanitization, so
	 * the stored meta value equals the config key.
	 */
	public function test_activity_flag_slug_matches_admin_flags_sanitization() {
		$cd = $this->make_ticket( 'Contributor Day (TEST)' );

		$addon = new CampTix_Companion_Tickets_Addon();
		$slug  = $addon->get_activity_flag_slug( $cd );

		$this->assertSame( sanitize_html_class( sanitize_title_with_dashes( $slug ) ), $slug );
		$this->assertStringStartsWith( 'activity-', $slug );
	}

	/**
	 * Linking a companion seat flags the MAIN attendee, exactly once.
	 */
	public function test_flag_added_to_main_on_link() {
		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );
		$main_id = $this->make_attendee( $main, 'alice', 'publish' );
		$cd_id   = $this->make_attendee( $cd, 'alice', 'publish' );

		$addon    = new CampTix_Companion_Tickets_Addon();
		$attendee = (object) array( 'ticket_id' => $cd );
		$addon->link_companion_attendee( $cd_id, $attendee );
		$addon->link_companion_attendee( $cd_id, $attendee ); // Idempotent.

		$slug  = $addon->get_activity_flag_slug( $cd );
		$flags = get_post_meta( $main_id, CampTix_Companion_Tickets_Addon::ADMIN_FLAG_META, false );
		$this->assertSame( array( $slug ), $flags );
	}

	/**
	 * Cancelling the companion seat removes the flag from its main again.
	 */
	public function test_flag_removed_when_companion_seat_cancelled() {
		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );
		$main_id = $this->make_attendee( $main, 'alice', 'publish' );
		$cd_id   = $this->make_attendee( $cd, 'alice', 'publish' );
		update_post_meta( $cd_id, 'tix_companion_primary_attendee_id', $main_id );

		$addon = new CampTix_Companion_Tickets_Addon();
		$addon->add_activity_admin_flag( $main_id, $cd );
		$this->assertNotEmpty( get_post_meta( $main_id, CampTix_Companion_Tickets_Addon::ADMIN_FLAG_META, false ) );

		$addon->maybe_remove_activity_admin_flag( 'cancel', 'publish', get_post( $cd_id ) );

		$this->assertSame( array(), get_post_meta( $main_id, CampTix_Companion_Tickets_Addon::ADMIN_FLAG_META, false ) );
	}

	/**
	 * Saving the companion config syncs flag definitions into the admin-flags
	 * config — preserving organiser-defined flags.
	 */
	public function test_flag_config_synced_on_options_save() {
		$cd = $this->make_ticket( 'Contributor Day' );

		$addon  = new CampTix_Companion_Tickets_Addon();
		$output = array(
			'camptix-admin-flags-data-parsed' => array( 'vip' => 'VIP' ),
			'camptix-admin-flags-data'        => 'vip: VIP',
		);
		$input  = array( 'camptix-companion-ticket-ids' => array( $cd ) );

		$output = $addon->validate_options( $output, $input );

		$slug = $addon->get_activity_flag_slug( $cd );
		$this->assertArrayHasKey( 'vip', $output['camptix-admin-flags-data-parsed'] );
		$this->assertArrayHasKey( $slug, $output['camptix-admin-flags-data-parsed'] );
		$this->assertStringContainsString( 'vip: VIP', $output['camptix-admin-flags-data'] );
		$this->assertStringContainsString( $slug . ': ', $output['camptix-admin-flags-data'] );
	}

	/**
	 * The public attendees listing excludes companion seats by default.
	 */
	public function test_attendees_listing_excludes_companion_tickets() {
		$cd = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );

		$addon = new CampTix_Companion_Tickets_Addon();
		$args  = $addon->exclude_companion_attendees_from_listing( array(), array( 'tickets' => '' ) );

		$this->assertSame( 'tix_ticket_id', $args['meta_query'][0]['key'] );
		$this->assertSame( 'NOT IN', $args['meta_query'][0]['compare'] );
		$this->assertContains( $cd, $args['meta_query'][0]['value'] );
	}

	/**
	 * An explicit tickets="…" attribute (an organiser's deliberate choice, e.g.
	 * a Contributor Day attendees page) disables the exclusion.
	 */
	public function test_attendees_listing_respects_explicit_tickets_attr() {
		$cd = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );

		$addon = new CampTix_Companion_Tickets_Addon();
		$args  = $addon->exclude_companion_attendees_from_listing( array(), array( 'tickets' => (string) $cd ) );

		$this->assertArrayNotHasKey( 'meta_query', $args );
	}

	/**
	 * Transferring a MAIN ticket to a new owner releases the old owner's
	 * linked companion seats (after the deferred queue runs).
	 */
	public function test_transfer_of_main_releases_linked_seats() {
		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );
		$main_id = $this->make_attendee( $main, 'alice', 'publish' );
		$cd_id   = $this->make_attendee( $cd, 'alice', 'publish' );
		update_post_meta( $cd_id, 'tix_companion_primary_attendee_id', $main_id );

		$addon = new CampTix_Companion_Tickets_Addon();
		add_action( 'update_post_meta', array( $addon, 'detect_ownership_change' ), 10, 4 );
		update_post_meta( $main_id, 'tix_username', 'bob' );
		remove_action( 'update_post_meta', array( $addon, 'detect_ownership_change' ), 10 );

		$this->assertSame( 'publish', get_post_status( $cd_id ), 'Release is deferred, not inline' );
		$addon->process_deferred_releases();

		$this->assertSame( 'cancel', get_post_status( $cd_id ) );
		$this->assertSame( 'publish', get_post_status( $main_id ), 'The transferred main itself stays live' );
	}

	/**
	 * Transferring the companion seat itself releases it back to the pool.
	 */
	public function test_transfer_of_companion_seat_releases_it() {
		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );
		$main_id = $this->make_attendee( $main, 'alice', 'publish' );
		$cd_id   = $this->make_attendee( $cd, 'alice', 'publish' );
		update_post_meta( $cd_id, 'tix_companion_primary_attendee_id', $main_id );

		$addon = new CampTix_Companion_Tickets_Addon();
		add_action( 'update_post_meta', array( $addon, 'detect_ownership_change' ), 10, 4 );
		update_post_meta( $cd_id, 'tix_username', 'bob' );
		remove_action( 'update_post_meta', array( $addon, 'detect_ownership_change' ), 10 );

		$addon->process_deferred_releases();

		$this->assertSame( 'cancel', get_post_status( $cd_id ) );
	}

	/**
	 * Claiming an "[[ unconfirmed ]]" group-purchase seat is first ownership,
	 * not a transfer — nothing is released.
	 */
	public function test_claiming_unconfirmed_seat_is_not_a_transfer() {
		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );
		$main_id = $this->make_attendee( $main, CampTix_Companion_Tickets_Addon::UNCONFIRMED_USERNAME, 'publish' );
		$cd_id   = $this->make_attendee( $cd, 'alice', 'publish' );
		update_post_meta( $cd_id, 'tix_companion_primary_attendee_id', $main_id );

		$addon = new CampTix_Companion_Tickets_Addon();
		add_action( 'update_post_meta', array( $addon, 'detect_ownership_change' ), 10, 4 );
		update_post_meta( $main_id, 'tix_username', 'bob' );
		remove_action( 'update_post_meta', array( $addon, 'detect_ownership_change' ), 10 );

		$addon->process_deferred_releases();

		$this->assertSame( 'publish', get_post_status( $cd_id ) );
	}

	/**
	 * The transfer policy filter can opt out of releasing seats.
	 */
	public function test_transfer_policy_filter_can_keep_seats() {
		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );
		$main_id = $this->make_attendee( $main, 'alice', 'publish' );
		$cd_id   = $this->make_attendee( $cd, 'alice', 'publish' );
		update_post_meta( $cd_id, 'tix_companion_primary_attendee_id', $main_id );

		$keep = function () {
			return 'keep';
		};
		add_filter( 'camptix_companion_transfer_policy', $keep );

		$addon = new CampTix_Companion_Tickets_Addon();
		$addon->handle_ownership_transfer( get_post( $main_id ), 'alice', 'bob' );
		$addon->process_deferred_releases();

		remove_filter( 'camptix_companion_transfer_policy', $keep );

		$this->assertSame( 'publish', get_post_status( $cd_id ) );
	}

	/*
	 * -------------------------------------------------------------------------
	 * v0.4.0 — "Your tickets" links + email-me-my-links (dd32's #1371 ask)
	 * -------------------------------------------------------------------------
	 */

	/**
	 * All of a user's published seats are returned, main tickets first.
	 */
	public function test_get_user_seat_ids_returns_published_seats_mains_first() {
		$main   = $this->make_ticket( 'Main' );
		$cd     = $this->make_ticket( 'Contributor Day' );
		$dinner = $this->make_ticket( 'Social Dinner' );
		$this->set_companion_config( array( $cd, $dinner ) );

		$cd_id   = $this->make_attendee( $cd, 'alice', 'publish' );
		$main_id = $this->make_attendee( $main, 'alice', 'publish' );
		$this->make_attendee( $dinner, 'alice', 'draft' );
		$this->make_attendee( $main, 'bob', 'publish' );

		$addon = new CampTix_Companion_Tickets_Addon();

		$this->assertSame( array( $main_id, $cd_id ), $addon->get_user_seat_ids( 'alice' ) );
	}

	/**
	 * Anonymous and unconfirmed identities hold no seats.
	 */
	public function test_get_user_seat_ids_empty_for_anonymous_and_unconfirmed() {
		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );
		$this->make_attendee( $main, CampTix_Companion_Tickets_Addon::UNCONFIRMED_USERNAME, 'publish' );

		$addon = new CampTix_Companion_Tickets_Addon();

		$this->assertSame( array(), $addon->get_user_seat_ids( '' ) );
		$this->assertSame( array(), $addon->get_user_seat_ids( CampTix_Companion_Tickets_Addon::UNCONFIRMED_USERNAME ) );
	}

	/**
	 * The links email goes to the given address and lists every seat's manage link.
	 */
	public function test_ticket_links_email_lists_every_seat() {
		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );
		$main_id = $this->make_attendee( $main, 'alice', 'publish' );
		$cd_id   = $this->make_attendee( $cd, 'alice', 'publish' );
		update_post_meta( $main_id, 'tix_edit_token', 'tok-main-123' );
		update_post_meta( $cd_id, 'tix_edit_token', 'tok-cd-456' );

		reset_phpmailer_instance();
		$addon = new CampTix_Companion_Tickets_Addon();
		$sent  = $addon->send_ticket_links_email( 'alice', 'alice@example.org' );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertTrue( $sent );
		$this->assertSame( 'alice@example.org', $mailer->get_recipient( 'to' )->address );
		$body = $mailer->get_sent()->body;
		$this->assertStringContainsString( 'Main', $body );
		$this->assertStringContainsString( 'Contributor Day', $body );
		$this->assertStringContainsString( 'tok-main-123', $body );
		$this->assertStringContainsString( 'tok-cd-456', $body );
	}

	/**
	 * No seats — nothing to send.
	 */
	public function test_ticket_links_email_requires_seats() {
		$cd = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );

		reset_phpmailer_instance();
		$addon = new CampTix_Companion_Tickets_Addon();

		$this->assertFalse( $addon->send_ticket_links_email( 'alice', 'alice@example.org' ) );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent() );
	}

	/**
	 * Repeat requests inside the throttle window do not send again.
	 */
	public function test_ticket_links_email_throttled_on_repeat() {
		$main = $this->make_ticket( 'Main' );
		$this->set_companion_config( array( $this->make_ticket( 'Contributor Day' ) ) );
		$main_id = $this->make_attendee( $main, 'alice', 'publish' );
		update_post_meta( $main_id, 'tix_edit_token', 'tok-1' );

		reset_phpmailer_instance();
		$addon = new CampTix_Companion_Tickets_Addon();

		$this->assertTrue( $addon->send_ticket_links_email( 'alice', 'alice@example.org' ) );
		$this->assertFalse( $addon->send_ticket_links_email( 'alice', 'alice@example.org' ) );
		$this->assertCount( 1, tests_retrieve_phpmailer_instance()->mock_sent );
	}

	/**
	 * The request handler ignores unrelated requests and rejects bad nonces.
	 */
	public function test_links_request_handler_guards() {
		$addon = new CampTix_Companion_Tickets_Addon();

		$this->assertSame( '', $addon->handle_links_email_request() );

		$_GET['tix_companion_email_links'] = '1';
		$_GET['_wpnonce']                  = 'bad';
		$this->assertSame( 'invalid', $addon->handle_links_email_request() );
		unset( $_GET['tix_companion_email_links'], $_GET['_wpnonce'] );
	}

	/**
	 * A logged-in seat-holder's request sends the email to their account address.
	 */
	public function test_links_request_handler_sends_for_logged_in_holder() {
		$main = $this->make_ticket( 'Main' );
		$this->set_companion_config( array( $this->make_ticket( 'Contributor Day' ) ) );

		$user_id = self::factory()->user->create( array(
			'user_login' => 'alice',
			'user_email' => 'alice@example.org',
		) );
		wp_set_current_user( $user_id );
		$main_id = $this->make_attendee( $main, 'alice', 'publish' );
		update_post_meta( $main_id, 'tix_edit_token', 'tok-1' );

		reset_phpmailer_instance();
		$_GET['tix_companion_email_links'] = '1';
		$_GET['_wpnonce']                  = wp_create_nonce( 'tix-companion-email-links' );

		$addon = new CampTix_Companion_Tickets_Addon();
		$this->assertSame( 'sent', $addon->handle_links_email_request() );
		$this->assertSame( 'alice@example.org', tests_retrieve_phpmailer_instance()->get_recipient( 'to' )->address );

		unset( $_GET['tix_companion_email_links'], $_GET['_wpnonce'] );
		wp_set_current_user( 0 );
	}

	/**
	 * The "Your tickets" notice lists the visitor's seats; anonymous visitors get nothing.
	 */
	public function test_my_ticket_links_notice_lists_seats() {
		$main = $this->make_ticket( 'Main' );
		$this->set_companion_config( array( $this->make_ticket( 'Contributor Day' ) ) );

		$user_id = self::factory()->user->create( array(
			'user_login' => 'alice',
			'user_email' => 'alice@example.org',
		) );
		$main_id = $this->make_attendee( $main, 'alice', 'publish' );
		update_post_meta( $main_id, 'tix_edit_token', 'tok-main-123' );

		$addon = new CampTix_Companion_Tickets_Addon();

		$this->reset_notices();
		wp_set_current_user( 0 );
		$addon->maybe_show_my_ticket_links();
		$this->assertSame( array(), $this->get_notices() );

		$this->reset_notices();
		wp_set_current_user( $user_id );
		$addon->maybe_show_my_ticket_links();
		$notices = implode( ' ', $this->get_notices() );
		$this->assertStringContainsString( 'Main', $notices );
		$this->assertStringContainsString( 'tok-main-123', $notices );

		$this->reset_notices();
		wp_set_current_user( 0 );
	}

	/*
	 * -------------------------------------------------------------------------
	 * Regression tests for the 2026-08-20 code review — H1 and H2
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Read (and reset) CampTix's checkout error flags (global singleton).
	 */
	private function reset_error_flags() {
		global $camptix;
		$camptix->error_flags = array();
	}

	/**
	 * H1: a `pending` seat drains ticket capacity (camptix.php
	 * get_purchased_tickets_count), so it must count as a registration too —
	 * otherwise a mixed-cart seat awaiting payment is invisible to the gate.
	 */
	public function test_user_has_registration_counts_pending_seat() {
		$cd = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );
		$this->make_attendee( $cd, 'carol', 'pending' );

		$addon = new CampTix_Companion_Tickets_Addon();
		$this->assertTrue( $addon->user_has_registration( 'carol', $cd ) );
	}

	/**
	 * H1: the duplicate gate must see a pending mixed-cart seat, so a second
	 * (free, instantly published) checkout cannot double-register and
	 * double-drain the capacity-limited pool.
	 */
	public function test_gate_blocks_duplicate_when_seat_is_pending() {
		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );
		$this->make_attendee( $main, 'alice', 'publish' );
		$this->make_attendee( $cd, 'alice', 'pending' );

		$addon = new CampTix_Companion_Tickets_Addon();
		$this->assertSame(
			CampTix_Companion_Tickets_Addon::DUPLICATE_FLAG,
			$addon->should_block_companion_attendee( $cd, 'alice' )
		);
	}

	/**
	 * H1: terminal statuses release the seat, so they must never block a fresh
	 * registration (the cascade relies on this).
	 */
	public function test_user_has_registration_ignores_terminal_seats() {
		$cd = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );
		$addon = new CampTix_Companion_Tickets_Addon();

		foreach ( array( 'cancel', 'refund', 'failed', 'timeout' ) as $status ) {
			$id = $this->make_attendee( $cd, 'dave', $status );
			$this->assertFalse(
				$addon->user_has_registration( 'dave', $cd ),
				"status {$status} must not count as a registration"
			);
			wp_delete_post( $id, true );
		}
	}

	/**
	 * H1: a `draft` seat is an order still in flight at the gateway — it has
	 * not drained capacity yet, but it will if the payment completes, so a
	 * second registration must be blocked with its own accurate reason.
	 */
	public function test_gate_blocks_in_flight_draft_seat() {
		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );
		$this->make_attendee( $main, 'alice', 'publish' );
		$draft_id = $this->make_attendee( $cd, 'alice', 'draft' );
		update_post_meta( $draft_id, 'tix_timestamp', time() - 60 );

		$addon = new CampTix_Companion_Tickets_Addon();
		$this->assertSame(
			CampTix_Companion_Tickets_Addon::IN_PROGRESS_FLAG,
			$addon->should_block_companion_attendee( $cd, 'alice' )
		);
	}

	/**
	 * H1: past CampTix's own draft-timeout window (review_timeout_payments,
	 * 24h) an abandoned draft must stop blocking, or the attendee is locked
	 * out of a seat they never got.
	 */
	public function test_gate_allows_when_draft_seat_is_stale() {
		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );
		$this->make_attendee( $main, 'alice', 'publish' );
		$draft_id = $this->make_attendee( $cd, 'alice', 'draft' );
		update_post_meta( $draft_id, 'tix_timestamp', time() - ( DAY_IN_SECONDS + 60 ) );

		$addon = new CampTix_Companion_Tickets_Addon();
		$this->assertSame( '', $addon->should_block_companion_attendee( $cd, 'alice' ) );
	}

	/**
	 * H1: the in-flight window is filterable, so a camp can tighten it.
	 */
	public function test_in_flight_window_is_filterable() {
		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );
		$this->make_attendee( $main, 'alice', 'publish' );
		$draft_id = $this->make_attendee( $cd, 'alice', 'draft' );
		update_post_meta( $draft_id, 'tix_timestamp', time() - ( 10 * MINUTE_IN_SECONDS ) );

		$addon  = new CampTix_Companion_Tickets_Addon();
		$shrink = function () {
			return MINUTE_IN_SECONDS;
		};
		add_filter( 'camptix_companion_in_flight_window', $shrink );
		$this->assertSame( '', $addon->should_block_companion_attendee( $cd, 'alice' ) );
		remove_filter( 'camptix_companion_in_flight_window', $shrink );
	}

	/**
	 * H1: the advisory "Already registered" label describes confirmed seats —
	 * an in-flight draft is not one, and must not claim otherwise.
	 */
	public function test_remaining_label_ignores_in_flight_draft() {
		$cd = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );
		$uid      = self::factory()->user->create( array( 'user_login' => 'ivy' ) );
		$draft_id = $this->make_attendee( $cd, 'ivy', 'draft' );
		update_post_meta( $draft_id, 'tix_timestamp', time() - 60 );
		wp_set_current_user( $uid );

		$addon = new CampTix_Companion_Tickets_Addon();
		$this->assertSame( 5, $addon->maybe_label_already_registered( 5, (object) array( 'ID' => $cd ) ) );

		wp_set_current_user( 0 );
	}

	/**
	 * H2: the addon header promises the gate fails CLOSED when require-login is
	 * inactive. require-login is the only thing that sets `$attendee->username`
	 * during checkout, so a seat without one must be blocked — never attributed
	 * to whoever happens to be logged in (which would let one buyer take
	 * quantity N of a capacity-limited seat).
	 */
	public function test_gate_fails_closed_when_attendee_object_has_no_username() {
		global $camptix;

		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );
		$uid = self::factory()->user->create( array( 'user_login' => 'alice' ) );
		$this->make_attendee( $main, 'alice', 'publish' );
		wp_set_current_user( $uid );

		$addon = new CampTix_Companion_Tickets_Addon();
		$this->reset_error_flags();
		// No ->username: exactly what CampTix passes with require-login inactive.
		$addon->gate_companion_attendee( (object) array( 'ticket_id' => $cd ), array(), 0 );

		$this->assertArrayHasKey(
			CampTix_Companion_Tickets_Addon::SELF_ONLY_FLAG,
			(array) $camptix->error_flags
		);

		$this->reset_error_flags();
		wp_set_current_user( 0 );
	}

	/**
	 * H2: with a real per-seat identity the gate still allows an eligible seat
	 * (the fail-closed fix must not block legitimate registrations).
	 */
	public function test_gate_allows_seat_with_confirmed_username() {
		global $camptix;

		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );
		$this->make_attendee( $main, 'alice', 'publish' );

		$addon = new CampTix_Companion_Tickets_Addon();
		$this->reset_error_flags();
		$addon->gate_companion_attendee(
			(object) array(
				'ticket_id' => $cd,
				'username'  => 'alice',
			),
			array(),
			0
		);

		$this->assertSame( array(), (array) $camptix->error_flags );
		$this->reset_error_flags();
	}

	/**
	 * H2: the linker must not attribute an unidentified seat to whoever is
	 * logged in — no identity, no link (and so no admin flag either).
	 */
	public function test_link_does_not_fall_back_to_current_user() {
		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );
		$uid = self::factory()->user->create( array( 'user_login' => 'alice' ) );
		$this->make_attendee( $main, 'alice', 'publish' );
		wp_set_current_user( $uid );

		$cd_id = self::factory()->post->create( array(
			'post_type'   => 'tix_attendee',
			'post_status' => 'draft',
		) );
		update_post_meta( $cd_id, 'tix_ticket_id', $cd );

		$addon = new CampTix_Companion_Tickets_Addon();
		$addon->link_companion_attendee( $cd_id, (object) array( 'ticket_id' => $cd ) );

		$this->assertSame( '', (string) get_post_meta( $cd_id, 'tix_companion_primary_attendee_id', true ) );

		wp_set_current_user( 0 );
	}

	/*
	 * -------------------------------------------------------------------------
	 * Regression tests for the 2026-08-20 code review — M1 and M2
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Set up one main attendee, one linked+flagged companion seat.
	 *
	 * @param string $seat_status Status to create the companion seat in.
	 * @return array{addon:CampTix_Companion_Tickets_Addon,main_id:int,seat_id:int,ticket_id:int,slug:string}
	 */
	private function make_linked_seat( $seat_status = 'publish' ) {
		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );
		$main_id = $this->make_attendee( $main, 'alice', 'publish' );
		$seat_id = $this->make_attendee( $cd, 'alice', $seat_status );
		update_post_meta( $seat_id, 'tix_companion_primary_attendee_id', $main_id );

		$addon = new CampTix_Companion_Tickets_Addon();
		$addon->add_activity_admin_flag( $main_id, $cd );
		$this->assertNotEmpty(
			get_post_meta( $main_id, CampTix_Companion_Tickets_Addon::ADMIN_FLAG_META, false ),
			'fixture: the flag should be on the main attendee to start with'
		);

		return array(
			'addon'     => $addon,
			'main_id'   => $main_id,
			'seat_id'   => $seat_id,
			'ticket_id' => $cd,
			'slug'      => $addon->get_activity_flag_slug( $cd ),
		);
	}

	/**
	 * The flags currently on an attendee.
	 *
	 * @param int $post_id Attendee post ID.
	 * @return string[]
	 */
	private function get_admin_flags( $post_id ) {
		return (array) get_post_meta( $post_id, CampTix_Companion_Tickets_Addon::ADMIN_FLAG_META, false );
	}

	/**
	 * M1: trash is reachable from the organiser UI (tix_attendee has show_ui and
	 * mapped delete caps), so trashing a main attendee — duplicate or fraud
	 * cleanup — must release its companion seats, not leave them live and
	 * holding capacity.
	 */
	public function test_trashing_main_cancels_linked_companions() {
		$f = $this->make_linked_seat();

		add_action( 'transition_post_status', array( $f['addon'], 'maybe_cascade_cancel' ), 10, 3 );
		wp_trash_post( $f['main_id'] );
		remove_action( 'transition_post_status', array( $f['addon'], 'maybe_cascade_cancel' ), 10 );

		$this->assertSame( 'cancel', get_post_status( $f['seat_id'] ) );
	}

	/**
	 * M1: force-delete fires only `before_delete_post`/`deleted_post`, which the
	 * addon did not hook at all — so a deleted main left its seats live forever.
	 */
	public function test_force_deleting_main_cancels_linked_companions() {
		$f = $this->make_linked_seat();

		add_action( 'before_delete_post', array( $f['addon'], 'maybe_release_on_delete' ), 10, 2 );
		wp_delete_post( $f['main_id'], true );
		remove_action( 'before_delete_post', array( $f['addon'], 'maybe_release_on_delete' ), 10 );

		$this->assertSame( 'cancel', get_post_status( $f['seat_id'] ) );
	}

	/**
	 * M1: trashing the companion seat itself must take the flag off its main,
	 * or the organiser filter/column/export the flag exists to power lies.
	 */
	public function test_trashing_companion_seat_removes_flag() {
		$f = $this->make_linked_seat();

		$f['addon']->maybe_remove_activity_admin_flag( 'trash', 'publish', get_post( $f['seat_id'] ) );

		$this->assertSame( array(), $this->get_admin_flags( $f['main_id'] ) );
	}

	/**
	 * M1: same for a force-deleted companion seat — the flag lives on the main,
	 * which survives, so nothing else would ever clean it up.
	 */
	public function test_force_deleting_companion_seat_removes_flag() {
		$f = $this->make_linked_seat();

		add_action( 'before_delete_post', array( $f['addon'], 'maybe_release_on_delete' ), 10, 2 );
		wp_delete_post( $f['seat_id'], true );
		remove_action( 'before_delete_post', array( $f['addon'], 'maybe_release_on_delete' ), 10 );

		$this->assertSame( array(), $this->get_admin_flags( $f['main_id'] ) );
	}

	/**
	 * M2: the flag is added from `camptix_checkout_update_post_meta`, while the
	 * seat is still a draft — i.e. before payment. An abandoned or declined
	 * mixed-cart order ends at `failed`, which must clear the flag again.
	 */
	public function test_failed_order_removes_activity_flag() {
		$f = $this->make_linked_seat( 'draft' );

		$f['addon']->maybe_remove_activity_admin_flag( 'failed', 'draft', get_post( $f['seat_id'] ) );

		$this->assertSame( array(), $this->get_admin_flags( $f['main_id'] ) );
	}

	/**
	 * M2: and `timeout`, which is where CampTix's daily
	 * `review_timeout_payments()` parks a draft nobody ever paid for.
	 */
	public function test_timed_out_order_removes_activity_flag() {
		$f = $this->make_linked_seat( 'draft' );

		$f['addon']->maybe_remove_activity_admin_flag( 'timeout', 'draft', get_post( $f['seat_id'] ) );

		$this->assertSame( array(), $this->get_admin_flags( $f['main_id'] ) );
	}

	/**
	 * M2 guard against over-fixing: the happy path — a draft seat confirming to
	 * publish — must KEEP the flag.
	 */
	public function test_confirming_companion_seat_keeps_flag() {
		$f = $this->make_linked_seat( 'draft' );

		$f['addon']->maybe_remove_activity_admin_flag( 'publish', 'draft', get_post( $f['seat_id'] ) );
		$f['addon']->maybe_remove_activity_admin_flag( 'publish', 'pending', get_post( $f['seat_id'] ) );

		$this->assertSame( array( $f['slug'] ), $this->get_admin_flags( $f['main_id'] ) );
	}

	/**
	 * M1/M2 guard: a live main ticket must never have its seats cascaded away by
	 * an ordinary status change.
	 */
	public function test_non_terminal_transition_does_not_cascade() {
		$f = $this->make_linked_seat();

		$f['addon']->maybe_cascade_cancel( 'publish', 'pending', get_post( $f['main_id'] ) );

		$this->assertSame( 'publish', get_post_status( $f['seat_id'] ) );
	}

	/**
	 * M1: deleting a post that is not an attendee at all is a no-op.
	 */
	public function test_delete_handler_ignores_other_post_types() {
		$f       = $this->make_linked_seat();
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		add_action( 'before_delete_post', array( $f['addon'], 'maybe_release_on_delete' ), 10, 2 );
		wp_delete_post( $page_id, true );
		remove_action( 'before_delete_post', array( $f['addon'], 'maybe_release_on_delete' ), 10 );

		$this->assertSame( 'publish', get_post_status( $f['seat_id'] ) );
		$this->assertSame( array( $f['slug'] ), $this->get_admin_flags( $f['main_id'] ) );
	}

	/**
	 * Read CampTix's buffered front-end errors (global singleton).
	 */
	private function get_errors() {
		global $camptix;
		$ref = new \ReflectionObject( $camptix );
		if ( ! $ref->hasProperty( 'errors' ) ) {
			return array();
		}
		$p = $ref->getProperty( 'errors' );
		return (array) $p->getValue( $camptix );
	}

	/**
	 * H3: a second activity seat in the buyer's OWN order must not be told to
	 * "log in and register for yourself" -- they did exactly that.
	 *
	 * The require-login addon gives the real username only to seat #1 and marks every
	 * later seat in the order `[[ unconfirmed ]]`, so an eligible logged-in attendee who
	 * selects two activities together trips the self-only branch on seat #2.
	 */
	public function test_h3_second_seat_of_own_order_reports_one_at_a_time() {
		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );

		$user_id = self::factory()->user->create( array( 'user_login' => 'dana' ) );
		$this->make_attendee( $main, 'dana', 'publish' );
		wp_set_current_user( $user_id );

		$addon = new CampTix_Companion_Tickets_Addon();

		$this->assertSame(
			CampTix_Companion_Tickets_Addon::ONE_AT_A_TIME_FLAG,
			$addon->should_block_companion_attendee( $cd, CampTix_Companion_Tickets_Addon::UNCONFIRMED_USERNAME )
		);

		wp_set_current_user( 0 );
	}

	/**
	 * H3: an unconfirmed seat with nobody logged in is still a self-only case.
	 */
	public function test_h3_anonymous_unconfirmed_seat_still_reports_self_only() {
		$cd = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );

		wp_set_current_user( 0 );

		$addon = new CampTix_Companion_Tickets_Addon();

		$this->assertSame(
			CampTix_Companion_Tickets_Addon::SELF_ONLY_FLAG,
			$addon->should_block_companion_attendee( $cd, CampTix_Companion_Tickets_Addon::UNCONFIRMED_USERNAME )
		);
	}

	/**
	 * H3: "one at a time" is only accurate for someone who could register at all.
	 *
	 * A logged-in visitor with no qualifying main ticket keeps the self-only message
	 * rather than being told to finish an order they cannot place.
	 */
	public function test_h3_logged_in_without_a_main_ticket_still_reports_self_only() {
		$cd = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );

		$user_id = self::factory()->user->create( array( 'user_login' => 'erin' ) );
		wp_set_current_user( $user_id );

		$addon = new CampTix_Companion_Tickets_Addon();

		$this->assertSame(
			CampTix_Companion_Tickets_Addon::SELF_ONLY_FLAG,
			$addon->should_block_companion_attendee( $cd, CampTix_Companion_Tickets_Addon::UNCONFIRMED_USERNAME )
		);

		wp_set_current_user( 0 );
	}

	/**
	 * H3: the fix changes the message, never the decision -- every unconfirmed or
	 * anonymous seat is still refused.
	 */
	public function test_h3_gate_decision_is_unchanged_for_unconfirmed_seats() {
		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );

		$user_id = self::factory()->user->create( array( 'user_login' => 'fred' ) );
		$this->make_attendee( $main, 'fred', 'publish' );
		wp_set_current_user( $user_id );

		$addon = new CampTix_Companion_Tickets_Addon();

		$this->assertNotSame( '', $addon->should_block_companion_attendee( $cd, CampTix_Companion_Tickets_Addon::UNCONFIRMED_USERNAME ) );
		$this->assertNotSame( '', $addon->should_block_companion_attendee( $cd, '' ) );

		wp_set_current_user( 0 );
	}

	/**
	 * H3: the new message says what to do and drops the false "log in" advice.
	 */
	public function test_h3_one_at_a_time_error_copy_is_accurate() {
		$this->reset_notices();

		$addon = new CampTix_Companion_Tickets_Addon();
		$addon->render_gate_errors( array( CampTix_Companion_Tickets_Addon::ONE_AT_A_TIME_FLAG => true ) );

		$errors = implode( ' ', $this->get_errors() );

		$this->assertStringContainsString( 'one at a time', $errors );
		$this->assertStringNotContainsString( 'log in', $errors );

		$this->reset_notices();
	}

	/**
	 * H3: an eligible visitor is told up front that activities go one per order,
	 * instead of discovering it as a wrong error at checkout.
	 */
	public function test_h3_notice_tells_an_eligible_visitor_to_register_one_at_a_time() {
		$main   = $this->make_ticket( 'Main' );
		$cd     = $this->make_ticket( 'Contributor Day' );
		$dinner = $this->make_ticket( 'Social Dinner' );
		$this->set_companion_config( array( $cd, $dinner ) );

		$user_id = self::factory()->user->create( array( 'user_login' => 'gina' ) );
		$this->make_attendee( $main, 'gina', 'publish' );
		wp_set_current_user( $user_id );

		$this->reset_notices();

		$addon = new CampTix_Companion_Tickets_Addon();
		$addon->maybe_show_eligibility_notice();

		$notices = implode( ' ', $this->get_notices() );

		$this->assertStringContainsString( 'one at a time', $notices );

		$this->reset_notices();
		wp_set_current_user( 0 );
	}

	/**
	 * M4: the flag slug used at link time is recorded on the seat.
	 *
	 * `get_activity_flag_slug()` derives from the ticket's `post_name`, which is
	 * mutable -- empty on a draft, rewritten on publish, editable at any time. Without
	 * recording what was actually written, removal has to guess.
	 */
	public function test_m4_flag_slug_is_recorded_on_the_seat() {
		$main = $this->make_ticket( 'Main' );
		$cd   = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );

		$main_id = $this->make_attendee( $main, 'alice', 'publish' );
		$seat_id = $this->make_attendee( $cd, 'alice', 'publish' );

		$addon    = new CampTix_Companion_Tickets_Addon();
		$attendee = (object) array(
			'ticket_id' => $cd,
			'username'  => 'alice',
		);
		$addon->link_companion_attendee( $seat_id, $attendee );

		$this->assertSame(
			$addon->get_activity_flag_slug( $cd ),
			get_post_meta( $seat_id, 'tix_companion_activity_flag', true )
		);
	}

	/**
	 * M4: a ticket slug that changes after the seat was linked must not orphan the flag.
	 *
	 * A draft ticket has no `post_name`, so it flags as `activity-ticket-{ID}` and
	 * becomes `activity-contributor-day` on publish. Recomputing at removal time then
	 * deletes a value that was never stored and leaves the real flag forever.
	 */
	public function test_m4_removal_survives_a_ticket_slug_change() {
		$main = $this->make_ticket( 'Main' );
		$cd   = self::factory()->post->create( array(
			'post_type'   => 'tix_ticket',
			'post_status' => 'publish',
			'post_title'  => 'Contributor Day',
			'post_name'   => 'contributor-day',
		) );
		update_post_meta( $cd, 'tix_price', 0 );
		update_post_meta( $cd, 'tix_quantity', 100 );
		$this->set_companion_config( array( $cd ) );

		$main_id = $this->make_attendee( $main, 'alice', 'publish' );
		$seat_id = $this->make_attendee( $cd, 'alice', 'publish' );

		$addon    = new CampTix_Companion_Tickets_Addon();
		$attendee = (object) array(
			'ticket_id' => $cd,
			'username'  => 'alice',
		);
		$addon->link_companion_attendee( $seat_id, $attendee );

		$flag_at_link_time = $addon->get_activity_flag_slug( $cd );
		$this->assertSame( array( $flag_at_link_time ), $this->get_admin_flags( $main_id ) );

		// The organiser renames the ticket slug.
		wp_update_post( array(
			'ID' => $cd, 'post_name' => 'contributor-day-2027',
		) );
		$this->assertNotSame( $flag_at_link_time, $addon->get_activity_flag_slug( $cd ) );

		$addon->maybe_remove_activity_admin_flag( 'cancel', 'publish', get_post( $seat_id ) );

		$this->assertSame( array(), $this->get_admin_flags( $main_id ) );
	}

	/**
	 * M4: a seat linked before the slug was recorded still has its flag removed.
	 */
	public function test_m4_removal_falls_back_for_a_seat_with_no_recorded_slug() {
		$f = $this->make_linked_seat();

		delete_post_meta( $f['seat_id'], 'tix_companion_activity_flag' );
		$this->assertSame( array( $f['slug'] ), $this->get_admin_flags( $f['main_id'] ) );

		$f['addon']->maybe_remove_activity_admin_flag( 'cancel', 'publish', get_post( $f['seat_id'] ) );

		$this->assertSame( array(), $this->get_admin_flags( $f['main_id'] ) );
	}

	/**
	 * M3: an activity flag with no admin-flags config entry is destroyed on the next
	 * manual save of that attendee.
	 *
	 * The admin-flags addon's own `save_post` (admin-flags.php:251) deletes EVERY
	 * `camptix-admin-flag` row and re-adds only slugs present in its parsed config, so
	 * any activity flag missing from that config is silently lost. The reachable trigger
	 * is saving the Activity Tickets config while admin-flags is inactive, which makes
	 * `sync_activity_admin_flag_config()` early-return.
	 *
	 * The config is therefore re-checked in wp-admin and repaired.
	 */
	public function test_m3_missing_config_entry_is_healed() {
		$cd = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );

		$addon = new CampTix_Companion_Tickets_Addon();
		$slug  = $addon->get_activity_flag_slug( $cd );

		// As if the config had been saved while admin-flags was inactive.
		$this->set_admin_flags_config( array() );
		$this->assertArrayNotHasKey( $slug, $this->get_admin_flags_config() );

		$addon->maybe_heal_activity_admin_flag_config();

		$this->assertArrayHasKey(
			$slug,
			$this->get_admin_flags_config(),
			'admin-flags re-adds only configured slugs, so the flag needs an entry to survive a save.'
		);
	}

	/**
	 * M3: healing must not trample flags an organiser defined by hand.
	 */
	public function test_m3_healing_preserves_organiser_defined_flags() {
		$cd = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );

		$this->set_admin_flags_config( array( 'vip' => 'VIP guest' ) );

		$addon = new CampTix_Companion_Tickets_Addon();
		$addon->maybe_heal_activity_admin_flag_config();

		$config = $this->get_admin_flags_config();

		$this->assertSame( 'VIP guest', $config['vip'] );
		$this->assertArrayHasKey( $addon->get_activity_flag_slug( $cd ), $config );
	}

	/**
	 * M3: the raw textarea value has to stay in step with the parsed config, or the
	 * next save of the Admin Flags section drops the entries again.
	 */
	public function test_m3_healing_keeps_the_raw_config_in_step() {
		$cd = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );
		$this->set_admin_flags_config( array() );

		$addon = new CampTix_Companion_Tickets_Addon();
		$addon->maybe_heal_activity_admin_flag_config();

		$options = (array) get_option( 'camptix_options', array() );
		$slug    = $addon->get_activity_flag_slug( $cd );

		$this->assertStringContainsString( $slug, (string) $options['camptix-admin-flags-data'] );
	}

	/**
	 * M3: a complete config is left exactly as it is.
	 */
	public function test_m3_healing_is_a_no_op_when_the_config_is_complete() {
		$cd = $this->make_ticket( 'Contributor Day' );
		$this->set_companion_config( array( $cd ) );

		$addon = new CampTix_Companion_Tickets_Addon();
		$addon->maybe_heal_activity_admin_flag_config();

		$before = get_option( 'camptix_options' );

		$addon->maybe_heal_activity_admin_flag_config();

		$this->assertSame( $before, get_option( 'camptix_options' ) );
	}

	/**
	 * Replace the admin-flags parsed config.
	 *
	 * @param array $flags slug => label.
	 */
	private function set_admin_flags_config( $flags ) {
		global $camptix;
		$options                                    = (array) get_option( 'camptix_options', array() );
		$options['camptix-admin-flags-data-parsed'] = $flags;

		$lines = array();
		foreach ( $flags as $slug => $label ) {
			$lines[] = sprintf( '%s: %s', $slug, $label );
		}
		$options['camptix-admin-flags-data'] = implode( "\n", $lines );

		update_option( 'camptix_options', $options );
		$camptix->load_options();
	}

	/**
	 * The admin-flags parsed config.
	 *
	 * @return array
	 */
	private function get_admin_flags_config() {
		$options = (array) get_option( 'camptix_options', array() );

		return isset( $options['camptix-admin-flags-data-parsed'] ) ? (array) $options['camptix-admin-flags-data-parsed'] : array();
	}
}
