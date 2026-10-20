<?php
defined( 'WPINC' ) || die();

use const WordCamp\CampTix_Tweaks\Profile_Tickets\USER_META_KEY;

require_once dirname( __DIR__ ) . '/trait-wordcamp-root-blog.php';

// `camptix-tweaks` loads the addon on `camptix_load_addons`, but this suite doesn't load `camptix-tweaks`.
require_once SUT_WPMU_PLUGIN_DIR . '/camptix-tweaks/addons/profile-tickets.php';

/**
 * Tests for keeping each ticket in its holder's `wordcamp_ticket` user meta.
 *
 * @covers \WordCamp\CampTix_Tweaks\Profile_Tickets\sync_ticket
 */
class Test_Camptix_Profile_Tickets extends \WP_UnitTestCase {
	use CampTix_Root_Blog_Fixture;

	/**
	 * @var int
	 */
	protected static $ticket_id;

	/**
	 * @param WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::create_wordcamp_root_blog( $factory );

		self::$ticket_id = $factory->post->create( array(
			'post_type'   => 'tix_ticket',
			'post_status' => 'publish',
			'post_title'  => 'General Admission',
		) );
	}

	/**
	 * Tears down the shared fixtures created in wpSetUpBeforeClass().
	 */
	public static function wpTearDownAfterClass() {
		self::delete_wordcamp_root_blog();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		remove_filter( 'camptix_wp_mail_override', '__return_true' );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Don't send the ticket e-mails that `payment_result()` triggers.
	 */
	public function set_up() {
		parent::set_up();

		add_filter( 'camptix_wp_mail_override', '__return_true' );
	}

	/**
	 * Create an order the way checkout leaves it: draft attendees, the first stamped with the buyer, the rest
	 * waiting to be claimed.
	 *
	 * @param string $buyer_login
	 * @param int    $count
	 * @param bool   $first_unknown Whether the buyer didn't know who would use the first ticket either.
	 *
	 * @return array Payment token, and the attendee IDs in order.
	 */
	protected function create_order( $buyer_login, $count = 1, $first_unknown = false ) {
		$payment_token = wp_generate_password( 32, false );
		$attendee_ids  = array();

		for ( $i = 1; $i <= $count; $i++ ) {
			$attendee_id = self::factory()->post->create( array(
				'post_type'   => 'tix_attendee',
				'post_status' => 'draft',
				'post_title'  => "Attendee $i",
			) );

			update_post_meta( $attendee_id, 'tix_payment_token', $payment_token );
			update_post_meta( $attendee_id, 'tix_ticket_id', self::$ticket_id );
			update_post_meta( $attendee_id, 'tix_ticket_price', 25 );
			update_post_meta( $attendee_id, 'tix_edit_token', "edit_$attendee_id" );
			update_post_meta( $attendee_id, 'tix_access_token', "access_$payment_token" );
			update_post_meta( $attendee_id, 'tix_receipt_email', 'receipt@example.test' );
			update_post_meta(
				$attendee_id,
				'tix_order',
				array(
					'items' => array(),
					'total' => 0,
				)
			);

			if ( 1 === $i && ! $first_unknown ) {
				update_post_meta( $attendee_id, 'tix_username', $buyer_login );
			} else {
				update_post_meta( $attendee_id, 'tix_username', CampTix_Require_Login::UNCONFIRMED_USERNAME );

				if ( 1 === $i ) {
					update_post_meta( $attendee_id, 'tix_buyer_username', $buyer_login );
				}
			}

			$attendee_ids[] = $attendee_id;
		}

		return array( $payment_token, $attendee_ids );
	}

	/**
	 * Get a user's ticket rows, keyed by attendee ID.
	 *
	 * @param int $user_id
	 *
	 * @return array
	 */
	protected function get_rows( $user_id ) {
		return array_column( get_user_meta( $user_id, USER_META_KEY, false ), null, 'attendee_id' );
	}

	/**
	 * Complete the payment for an order.
	 *
	 * @param string $payment_token
	 */
	protected function complete( $payment_token ) {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		$camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_COMPLETED, array(), false );
	}

	/**
	 * A completed order should put every ticket on the buyer's profile until it's claimed, and only the buyer
	 * should get the link that manages the order.
	 */
	public function test_completed_order_is_logged_to_the_buyer() {
		$buyer_id = self::factory()->user->create( array( 'user_login' => 'buyer' ) );

		list( $payment_token, $attendee_ids ) = $this->create_order( 'buyer', 2 );
		$this->complete( $payment_token );

		$rows = $this->get_rows( $buyer_id );

		$this->assertCount( 2, $rows );
		$this->assertSame( 'publish', $rows[ $attendee_ids[0] ]['status'] );
		$this->assertSame( 'General Admission', $rows[ $attendee_ids[0] ]['ticket'] );
		$this->assertSame( get_current_blog_id(), $rows[ $attendee_ids[0] ]['blog_id'] );
		$this->assertFalse( $rows[ $attendee_ids[0] ]['unclaimed'] );
		$this->assertTrue( $rows[ $attendee_ids[1] ]['unclaimed'] );
		$this->assertStringContainsString( "tix_edit_token=edit_{$attendee_ids[1]}", $rows[ $attendee_ids[1] ]['edit_link'] );
		$this->assertStringContainsString( 'tix_action=access_tickets', $rows[ $attendee_ids[0] ]['access_link'] );
	}

	/**
	 * When the buyer didn't know who'd use the first ticket either, the buyer comes from `tix_buyer_username`.
	 */
	public function test_unknown_first_attendee_is_logged_to_the_buyer() {
		$buyer_id = self::factory()->user->create( array( 'user_login' => 'buyer' ) );

		list( $payment_token, $attendee_ids ) = $this->create_order( 'buyer', 1, true );
		$this->complete( $payment_token );

		$rows = $this->get_rows( $buyer_id );

		$this->assertTrue( $rows[ $attendee_ids[0] ]['unclaimed'] );
		$this->assertArrayHasKey( 'access_link', $rows[ $attendee_ids[0] ] );
	}

	/**
	 * Webhooks complete orders with nobody logged in, so the row can't depend on the current user.
	 */
	public function test_order_completed_without_a_logged_in_user_is_logged_to_the_buyer() {
		$buyer_id = self::factory()->user->create( array( 'user_login' => 'buyer' ) );

		list( $payment_token, $attendee_ids ) = $this->create_order( 'buyer' );

		$this->assertSame( 0, get_current_user_id() );
		$this->complete( $payment_token );

		$this->assertArrayHasKey( $attendee_ids[0], $this->get_rows( $buyer_id ) );
	}

	/**
	 * An additional attendee claiming their ticket should move it from the buyer's profile to theirs, without
	 * the link that manages the buyer's order.
	 */
	public function test_claimed_ticket_moves_to_the_attendee() {
		$buyer_id    = self::factory()->user->create( array( 'user_login' => 'buyer' ) );
		$claimant_id = self::factory()->user->create( array( 'user_login' => 'claimant' ) );

		list( $payment_token, $attendee_ids ) = $this->create_order( 'buyer', 2 );
		$this->complete( $payment_token );

		update_post_meta( $attendee_ids[1], 'tix_username', 'claimant' );

		$buyer_rows    = $this->get_rows( $buyer_id );
		$claimant_rows = $this->get_rows( $claimant_id );

		$this->assertSame( array( $attendee_ids[0] ), array_keys( $buyer_rows ) );
		$this->assertSame( array( $attendee_ids[1] ), array_keys( $claimant_rows ) );
		$this->assertFalse( $claimant_rows[ $attendee_ids[1] ]['unclaimed'] );
		$this->assertArrayNotHasKey( 'access_link', $claimant_rows[ $attendee_ids[1] ] );
	}

	/**
	 * A ticket transferred to someone else should leave the previous holder's profile.
	 */
	public function test_transferred_ticket_moves_to_the_new_holder() {
		$buyer_id     = self::factory()->user->create( array( 'user_login' => 'buyer' ) );
		$claimant_id  = self::factory()->user->create( array( 'user_login' => 'claimant' ) );
		$recipient_id = self::factory()->user->create( array( 'user_login' => 'recipient' ) );

		list( $payment_token, $attendee_ids ) = $this->create_order( 'buyer', 2 );
		$this->complete( $payment_token );

		update_post_meta( $attendee_ids[1], 'tix_username', 'claimant' );
		update_post_meta( $attendee_ids[1], 'tix_username', 'recipient' );

		$this->assertSame( array(), $this->get_rows( $claimant_id ) );
		$this->assertSame( array( $attendee_ids[1] ), array_keys( $this->get_rows( $recipient_id ) ) );
		$this->assertCount( 1, $this->get_rows( $buyer_id ) );
	}

	/**
	 * The buyer giving away their own ticket mustn't hand them the rest of the order too: the unclaimed tickets
	 * and the link that manages the order stay with the buyer.
	 */
	public function test_buyer_transferring_their_own_ticket_keeps_the_rest_of_the_order() {
		$buyer_id     = self::factory()->user->create( array( 'user_login' => 'buyer' ) );
		$recipient_id = self::factory()->user->create( array( 'user_login' => 'recipient' ) );

		list( $payment_token, $attendee_ids ) = $this->create_order( 'buyer', 2 );
		$this->complete( $payment_token );

		update_post_meta( $attendee_ids[0], 'tix_username', 'recipient' );

		// Something else about the unclaimed ticket changes later, like its status.
		wp_update_post( array(
			'ID'          => $attendee_ids[1],
			'post_status' => 'refund',
		) );

		$buyer_rows     = $this->get_rows( $buyer_id );
		$recipient_rows = $this->get_rows( $recipient_id );

		$this->assertSame( array( $attendee_ids[1] ), array_keys( $buyer_rows ) );
		$this->assertArrayHasKey( 'access_link', $buyer_rows[ $attendee_ids[1] ] );
		$this->assertSame( array( $attendee_ids[0] ), array_keys( $recipient_rows ) );
		$this->assertArrayNotHasKey( 'access_link', $recipient_rows[ $attendee_ids[0] ] );
	}

	/**
	 * An organizer refunding an order should update the buyer's rows, not look for rows of their own.
	 */
	public function test_refund_by_another_user_updates_the_holders_rows() {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		$buyer_id     = self::factory()->user->create( array( 'user_login' => 'buyer' ) );
		$organizer_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		list( $payment_token, $attendee_ids ) = $this->create_order( 'buyer', 2 );
		$this->complete( $payment_token );

		wp_set_current_user( $organizer_id );
		$camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_REFUNDED, array(), false );

		$rows = $this->get_rows( $buyer_id );

		// Updated in place, rather than added alongside the old rows.
		$this->assertCount( 2, get_user_meta( $buyer_id, USER_META_KEY, false ) );
		$this->assertSame( 'refund', $rows[ $attendee_ids[0] ]['status'] );
		$this->assertSame( 'refund', $rows[ $attendee_ids[1] ]['status'] );
		$this->assertSame( array(), $this->get_rows( $organizer_id ) );
	}

	/**
	 * A repeated payment result, like a webhook arriving after the interactive return, mustn't add the ticket twice.
	 */
	public function test_repeated_payment_result_does_not_duplicate_rows() {
		$buyer_id = self::factory()->user->create( array( 'user_login' => 'buyer' ) );

		list( $payment_token ) = $this->create_order( 'buyer' );
		$this->complete( $payment_token );
		$this->complete( $payment_token );

		$this->assertCount( 1, get_user_meta( $buyer_id, USER_META_KEY, false ) );
	}

	/**
	 * A checkout that never got paid isn't a ticket, so it shouldn't show up.
	 */
	public function test_failed_checkout_is_not_logged() {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		$buyer_id = self::factory()->user->create( array( 'user_login' => 'buyer' ) );

		list( $payment_token, $attendee_ids ) = $this->create_order( 'buyer', 2 );
		$camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED, array(), false );

		$this->assertSame( array(), get_user_meta( $buyer_id, USER_META_KEY, false ) );

		// Nor should it leave anything behind on the attendees.
		foreach ( $attendee_ids as $attendee_id ) {
			$this->assertSame( '', get_post_meta( $attendee_id, 'tix_profile_buyer', true ) );
			$this->assertSame( '', get_post_meta( $attendee_id, 'tix_profile_user_id', true ) );
		}
	}

	/**
	 * Trashing or deleting an attendee should take the ticket off the profile.
	 */
	public function test_trashed_and_deleted_tickets_are_removed() {
		$buyer_id = self::factory()->user->create( array( 'user_login' => 'buyer' ) );

		list( $payment_token, $attendee_ids ) = $this->create_order( 'buyer', 2 );
		$this->complete( $payment_token );

		wp_trash_post( $attendee_ids[0] );
		wp_delete_post( $attendee_ids[1], true );

		$this->assertSame( array(), get_user_meta( $buyer_id, USER_META_KEY, false ) );
	}

	/**
	 * Rows are keyed by site as well as attendee, since attendee IDs repeat across WordCamps.
	 */
	public function test_rows_from_another_site_are_left_alone() {
		$buyer_id = self::factory()->user->create( array( 'user_login' => 'buyer' ) );

		list( $payment_token, $attendee_ids ) = $this->create_order( 'buyer' );

		$other_site_row = array(
			'blog_id'     => get_current_blog_id() + 1000,
			'attendee_id' => $attendee_ids[0],
			'status'      => 'publish',
		);
		add_user_meta( $buyer_id, USER_META_KEY, $other_site_row );

		$this->complete( $payment_token );
		wp_delete_post( $attendee_ids[0], true );

		$this->assertSame( array( $other_site_row ), get_user_meta( $buyer_id, USER_META_KEY, false ) );
	}
}
