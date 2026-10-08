<?php
defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/trait-wordcamp-root-blog.php';

/**
 * @covers CampTix_Require_Login
 */
class Test_Camptix_Require_Login_Addon extends \WP_UnitTestCase {
	use CampTix_Root_Blog_Fixture;

	/**
	 * @param WP_UnitTest_Factory $factory
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
	 * E-mails CampTix tried to send during the current test.
	 *
	 * @var array
	 */
	protected $sent_mail = array();

	/**
	 * CampTix properties changed by set_camptix_property(), with their values before the test.
	 *
	 * @var array
	 */
	protected $camptix_properties = array();

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		foreach ( $this->camptix_properties as $name => $value ) {
			( new ReflectionProperty( 'CampTix_Plugin', $name ) )->setValue( $camptix, $value );
		}
		$this->camptix_properties = array();

		remove_filter( 'camptix_wp_mail_override', array( $this, 'capture_mail' ) );
		remove_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
		$camptix->release_checkout_rows();
		$this->sent_mail = array();
		wp_set_current_user( 0 );

		unset( $_GET['tix_action'], $_POST['tix_attendee_info'], $_POST['tix_receipt_email'], $GLOBALS['post'] );

		parent::tear_down();
	}

	/**
	 * Locate a running addon instance from $camptix->addons_loaded.
	 *
	 * @param string $class_name
	 *
	 * @return CampTix_Addon
	 */
	protected function get_addon( $class_name ) {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		foreach ( $camptix->addons_loaded as $addon ) {
			if ( $addon instanceof $class_name ) {
				return $addon;
			}
		}

		$this->fail( "$class_name was not loaded." );
	}

	/**
	 * Create a published attendee with the given meta.
	 *
	 * @param string $first_name
	 * @param string $last_name
	 * @param string $email
	 * @param string $username
	 *
	 * @return int
	 */
	protected function create_attendee( $first_name, $last_name, $email, $username ) {
		$attendee_id = self::factory()->post->create( array(
			'post_type'   => 'tix_attendee',
			'post_status' => 'publish',
			'post_title'  => "$first_name $last_name",
		) );

		update_post_meta( $attendee_id, 'tix_first_name', $first_name );
		update_post_meta( $attendee_id, 'tix_last_name', $last_name );
		update_post_meta( $attendee_id, 'tix_email', $email );
		update_post_meta( $attendee_id, 'tix_username', $username );

		return $attendee_id;
	}

	/**
	 * Render [camptix_attendees], bypassing the cache.
	 *
	 * @return string
	 */
	protected function render_attendees_shortcode() {
		/** @var CampTix_Addon_Shortcodes $shortcodes */
		$shortcodes = $this->get_addon( 'CampTix_Addon_Shortcodes' );

		return $shortcodes->get_attendees_shortcode_content( $shortcodes->sanitize_attendees_atts( array() ), true );
	}

	/**
	 * A confirmed attendee is listed. This keeps the "hidden" tests below from passing vacuously.
	 *
	 * @covers CampTix_Require_Login::hide_unconfirmed_attendees
	 */
	public function test_attendees_shortcode_shows_confirmed_attendee() {
		$this->create_attendee( 'Confirmed', 'Person', 'confirmed@example.org', 'confirmed-person' );

		$this->assertStringContainsString( '<span class="tix-first">Confirmed</span>', $this->render_attendees_shortcode() );
	}

	/**
	 * An additional attendee who hasn't confirmed yet is not listed.
	 *
	 * @covers CampTix_Require_Login::hide_unconfirmed_attendees
	 */
	public function test_attendees_shortcode_hides_unconfirmed_attendee() {
		$this->create_attendee( 'Confirmed', 'Person', 'confirmed@example.org', 'confirmed-person' );
		$this->create_attendee( 'Unconfirmed', 'Person', 'unconfirmed@example.org', CampTix_Require_Login::UNCONFIRMED_USERNAME );

		$content = $this->render_attendees_shortcode();

		$this->assertStringContainsString( '<span class="tix-first">Confirmed</span>', $content );
		$this->assertStringNotContainsString( '<span class="tix-first">Unconfirmed</span>', $content );
	}

	/**
	 * An unknown attendee is not listed, even when their row was stored with a real username.
	 *
	 * That's the case for the buyer's own row, which gets the buyer's username at checkout.
	 *
	 * @see https://github.com/WordPress/wordcamp.org/issues/2114
	 *
	 * @covers CampTix_Require_Login::hide_unconfirmed_attendees
	 */
	public function test_attendees_shortcode_hides_unknown_attendee_with_real_username() {
		$this->create_attendee( 'Confirmed', 'Person', 'confirmed@example.org', 'confirmed-person' );
		$this->create_attendee( 'Unknown', 'Attendee', CampTix_Require_Login::UNKNOWN_ATTENDEE_EMAIL, 'buyer-username' );

		$content = $this->render_attendees_shortcode();

		$this->assertStringContainsString( '<span class="tix-first">Confirmed</span>', $content );
		$this->assertStringNotContainsString( '<span class="tix-first">Unknown</span>', $content );
	}

	/**
	 * Every attendee on an order is returned, not only the first batch of 200.
	 *
	 * @covers CampTix_Require_Login::get_attendees_by_access_token
	 */
	public function test_get_attendees_by_access_token_pages_past_the_first_batch() {
		$attendee_args = array(
			'post_type'   => 'tix_attendee',
			'post_status' => 'publish',
		);

		foreach ( self::factory()->post->create_many( 201, $attendee_args ) as $attendee_id ) {
			update_post_meta( $attendee_id, 'tix_access_token', 'bigorder' );
		}

		$other_order = self::factory()->post->create( $attendee_args );
		update_post_meta( $other_order, 'tix_access_token', 'otherorder' );

		/** @var CampTix_Require_Login $addon */
		$addon     = $this->get_addon( 'CampTix_Require_Login' );
		$attendees = $addon->get_attendees_by_access_token( 'bigorder' );

		$this->assertCount( 201, $attendees );
		$this->assertCount( 201, array_unique( wp_list_pluck( $attendees, 'ID' ) ) );
		$this->assertNotContains( $other_order, wp_list_pluck( $attendees, 'ID' ) );
	}

	/**
	 * The buyer-facing status label is escaped, even when a translation contains markup.
	 *
	 * @covers CampTix_Require_Login::show_buyer_attendee_status_instead_of_edit_link
	 */
	public function test_buyer_status_label_is_escaped() {
		$attendee_id = $this->create_attendee( 'Confirmed', 'Person', 'confirmed@example.org', 'confirmed-person' );

		$inject_markup = function ( $translation, $text, $context ) {
			return ( 'Status: Confirmed' === $text && 'WordCamp ticket status.' === $context ) ? '<b>Status: Confirmed</b>' : $translation;
		};
		add_filter( 'gettext_with_context', $inject_markup, 10, 3 );

		/** @var CampTix_Require_Login $addon */
		$addon   = $this->get_addon( 'CampTix_Require_Login' );
		$content = $addon->show_buyer_attendee_status_instead_of_edit_link( '', get_post( $attendee_id ) );

		remove_filter( 'gettext_with_context', $inject_markup, 10 );

		$this->assertStringNotContainsString( '<b>', $content );
		$this->assertStringContainsString( '&lt;b&gt;Status:&nbsp;Confirmed&lt;/b&gt;', $content );
	}

	/**
	 * The status help is a native disclosure, so it works with a keyboard, a screen reader and touch.
	 *
	 * @covers CampTix_Require_Login::show_buyer_attendee_status_instead_of_edit_link
	 */
	public function test_buyer_status_help_is_a_disclosure() {
		$attendee_id = $this->create_attendee( 'Unconfirmed', 'Person', 'unconfirmed@example.org', CampTix_Require_Login::UNCONFIRMED_USERNAME );

		/** @var CampTix_Require_Login $addon */
		$addon   = $this->get_addon( 'CampTix_Require_Login' );
		$content = $addon->show_buyer_attendee_status_instead_of_edit_link( '', get_post( $attendee_id ) );

		$this->assertMatchesRegularExpression( '#<details class="tix-status-help"><summary[^>]*>.+</summary><span class="tix-status-help__text">This ticket is fully paid for\.[^<]+</span></details>#', $content );
		$this->assertStringNotContainsString( 'title=', $content );
		$this->assertStringNotContainsString( 'tabindex=', $content );
	}

	/**
	 * The re-sent claim email names the buyer, like the first send does.
	 *
	 * @covers CampTix_Require_Login::resend_claim_links
	 */
	public function test_resend_claim_links_names_the_buyer() {
		$buyer_id    = $this->create_attendee( 'Jane', 'Buyer', 'jane@example.org', 'jane' );
		$attendee_id = $this->create_attendee( 'Pat', 'Guest', 'pat@example.org', CampTix_Require_Login::UNCONFIRMED_USERNAME );

		foreach ( array( $buyer_id, $attendee_id ) as $id ) {
			update_post_meta( $id, 'tix_access_token', 'resendorder' );
			update_post_meta( $id, 'tix_receipt_email', 'jane@example.org' );
			update_post_meta( $id, 'tix_edit_token', 'edittoken' . $id );
		}

		reset_phpmailer_instance();

		/** @var CampTix_Require_Login $addon */
		$addon   = $this->get_addon( 'CampTix_Require_Login' );
		$summary = $addon->resend_claim_links( 'resendorder' );

		$this->assertCount( 1, $summary['sent'] );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertSame( 'pat@example.org', $mailer->get_recipient( 'to' )->address );
		$this->assertStringContainsString( 'purchased for you by Jane Buyer.', $mailer->get_sent()->body );
	}

	/**
	 * Create one attendee of each ticket status shape, for the Notify segment tests.
	 *
	 * @return int[] Attendee IDs, keyed by shape.
	 */
	protected function create_segment_attendees() {
		$ids = array(
			'confirmed'        => $this->create_attendee( 'Confirmed', 'Person', 'confirmed@example.org', 'confirmed-person' ),
			'unconfirmed'      => $this->create_attendee( 'Unconfirmed', 'Person', 'unconfirmed@example.org', CampTix_Require_Login::UNCONFIRMED_USERNAME ),
			'no_username'      => $this->create_attendee( 'Unlinked', 'Person', 'unlinked@example.org', '' ),
			'unknown'          => $this->create_attendee( 'Unknown', 'Attendee', CampTix_Require_Login::UNKNOWN_ATTENDEE_EMAIL, CampTix_Require_Login::UNCONFIRMED_USERNAME ),
			'unknown_realuser' => $this->create_attendee( 'Unknown', 'Attendee', CampTix_Require_Login::UNKNOWN_ATTENDEE_EMAIL, 'buyer-username' ),
		);

		delete_post_meta( $ids['no_username'], 'tix_username' );

		return $ids;
	}

	/**
	 * Build an "is" condition, as posted by the Notify form.
	 *
	 * @param string $field
	 * @param string $value
	 *
	 * @return array
	 */
	protected function segment_condition( $field, $value ) {
		return array(
			'field' => $field,
			'op'    => 'is',
			'value' => $value,
		);
	}

	/**
	 * Run a Notify segment and name the attendees it matched.
	 *
	 * @param string $relation   'and' or 'or'.
	 * @param array  $conditions Segment conditions, as posted by the Notify form.
	 * @param int[]  $ids        Attendee IDs, keyed by shape.
	 *
	 * @return string[] The matched shapes, sorted.
	 */
	protected function get_segment_shapes( $relation, $conditions, $ids ) {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		$shapes = array_keys( array_intersect( $ids, $camptix->get_segment( $relation, $conditions ) ) );
		sort( $shapes );

		return $shapes;
	}

	/**
	 * The Notify screen offers ticket status as a segment field.
	 *
	 * @covers CampTix_Require_Login::camptix_notify_segment_fields
	 */
	public function test_notify_segment_fields_include_ticket_status() {
		$fields = apply_filters( 'camptix_notify_segment_fields', array() );

		$this->assertContains( 'ticket_status', wp_list_pluck( $fields, 'option_value' ) );
	}

	/**
	 * Confirmed and Unconfirmed don't overlap, and unknown attendees are in neither, as they have no email to send to.
	 *
	 * @covers CampTix_Require_Login::camptix_notify_segment_query
	 */
	public function test_notify_ticket_status_segments() {
		$ids = $this->create_segment_attendees();

		$this->assertSame(
			array( 'confirmed' ),
			$this->get_segment_shapes( 'and', array( $this->segment_condition( 'ticket_status', 'confirmed' ) ), $ids )
		);

		$this->assertSame(
			array( 'no_username', 'unconfirmed' ),
			$this->get_segment_shapes( 'and', array( $this->segment_condition( 'ticket_status', 'unconfirmed' ) ), $ids )
		);
	}

	/**
	 * "Any of" a ticket status and a question answer matches either one, not both.
	 *
	 * @covers CampTix_Plugin::get_segment
	 */
	public function test_notify_ticket_status_segment_with_or_relation() {
		$ids = $this->create_segment_attendees();

		$question_id = self::factory()->post->create( array(
			'post_type'   => 'tix_question',
			'post_status' => 'publish',
		) );
		update_post_meta( $question_id, 'tix_type', 'select' );
		update_post_meta( $question_id, 'tix_values', array( 'S', 'L' ) );

		foreach ( $ids as $shape => $attendee_id ) {
			update_post_meta( $attendee_id, 'tix_questions', array( $question_id => 'unconfirmed' === $shape ? 'L' : 'S' ) );
		}

		$conditions = array(
			$this->segment_condition( 'ticket_status', 'confirmed' ),
			$this->segment_condition( "tix-question-$question_id", 'L' ),
		);

		$this->assertSame( array( 'confirmed', 'unconfirmed' ), $this->get_segment_shapes( 'or', $conditions, $ids ) );
		$this->assertSame( array(), $this->get_segment_shapes( 'and', $conditions, $ids ) );
	}

	/**
	 * The row's pre-filled email doesn't survive when the buyer doesn't know who the attendee is.
	 *
	 * The buyer's row comes pre-filled with the buyer's own email, and the form only hides it
	 * when the box is ticked.
	 *
	 * @see https://github.com/WordPress/wordcamp.org/issues/2114
	 *
	 * @covers CampTix_Require_Login::add_unknown_attendee_info_stubs
	 */
	public function test_unknown_attendee_gets_placeholder_email_over_prefilled_email() {
		$attendee_info = array(
			'first_name'       => '',
			'last_name'        => '',
			'email'            => 'buyer@example.org',
			'unknown_attendee' => '1',
		);

		$attendee_info = apply_filters( 'camptix_checkout_attendee_info', $attendee_info );

		$this->assertSame( CampTix_Require_Login::UNKNOWN_ATTENDEE_EMAIL, $attendee_info['email'] );
	}

	/**
	 * The row's pre-filled name doesn't survive when the buyer doesn't know who the attendee is.
	 *
	 * @covers CampTix_Require_Login::add_unknown_attendee_info_stubs
	 */
	public function test_unknown_attendee_gets_placeholder_name_over_prefilled_name() {
		$attendee_info = array(
			'first_name'       => 'Jane',
			'last_name'        => 'Buyer',
			'email'            => 'buyer@example.org',
			'unknown_attendee' => '1',
		);

		$attendee_info = apply_filters( 'camptix_checkout_attendee_info', $attendee_info );

		$this->assertSame( 'Unknown', $attendee_info['first_name'] );
		$this->assertSame( 'Attendee', $attendee_info['last_name'] );
	}

	/**
	 * An unknown attendee on the buyer's row isn't assigned to the buyer's account.
	 *
	 * @covers CampTix_Require_Login::add_username_to_attendee_object
	 */
	public function test_unknown_attendee_on_buyer_row_is_unconfirmed() {
		$this->log_in_buyer();

		$attendee = apply_filters( 'camptix_form_register_complete_attendee_object', new stdClass(), array( 'unknown_attendee' => '1' ), 1 );

		$this->assertSame( CampTix_Require_Login::UNCONFIRMED_USERNAME, $attendee->username );
	}

	/**
	 * The buyer's row is still assigned to the buyer when the attendee is known.
	 *
	 * @covers CampTix_Require_Login::add_username_to_attendee_object
	 */
	public function test_buyer_row_is_assigned_to_buyer() {
		$buyer = $this->log_in_buyer();

		$attendee = apply_filters( 'camptix_form_register_complete_attendee_object', new stdClass(), array(), 1 );

		$this->assertSame( $buyer->user_login, $attendee->username );
	}

	/**
	 * A one-ticket order for an unknown attendee is stored as an unknown attendee, so it isn't listed.
	 *
	 * @see https://github.com/WordPress/wordcamp.org/issues/2114#issuecomment-5812433412
	 *
	 * @covers CampTix_Require_Login::add_unknown_attendee_info_stubs
	 * @covers CampTix_Require_Login::add_username_to_attendee_object
	 */
	public function test_checkout_stores_unknown_attendee_on_buyer_row_as_unknown() {
		$this->log_in_buyer();

		$attendee_id = $this->check_out_one_ticket_for_unknown_attendee();

		$this->assertSame( CampTix_Require_Login::UNKNOWN_ATTENDEE_EMAIL, get_post_meta( $attendee_id, 'tix_email', true ) );
		$this->assertSame( CampTix_Require_Login::UNCONFIRMED_USERNAME, get_post_meta( $attendee_id, 'tix_username', true ) );
		$this->assertStringNotContainsString( '<span class="tix-first">Unknown</span>', $this->render_attendees_shortcode() );
	}

	/**
	 * The buyer still gets the receipt when the ticket's own email is the placeholder.
	 *
	 * @covers CampTix_Require_Login::send_receipt_to_buyer_instead_of_unknown_attendee
	 */
	public function test_checkout_sends_receipt_to_buyer_when_buyer_row_is_unknown() {
		$this->log_in_buyer();

		$attendee_id = $this->check_out_one_ticket_for_unknown_attendee();

		$this->assertSame( 'buyer@example.org', get_post_meta( $attendee_id, 'tix_receipt_email', true ) );
		$this->assertSame( array( 'buyer@example.org', 'buyer@example.org' ), wp_list_pluck( $this->sent_mail, 'to' ) );
	}

	/**
	 * The buyer of a one-ticket order for an unknown attendee gets the claim link to forward, as on multiple purchases.
	 *
	 * The receipt only links to the buyer's own order, which shouldn't be forwarded.
	 *
	 * @covers CampTix_Require_Login::send_claim_link_for_single_unknown_attendee
	 */
	public function test_checkout_sends_claim_link_to_buyer_when_buyer_row_is_unknown() {
		$this->log_in_buyer();

		$attendee_id = $this->check_out_one_ticket_for_unknown_attendee();
		$claim_query = 'tix_edit_token=' . get_post_meta( $attendee_id, 'tix_edit_token', true );

		$this->assertCount( 2, $this->sent_mail );
		$this->assertStringNotContainsString( $claim_query, $this->sent_mail[0]['message'] );
		$this->assertSame( 'buyer@example.org', $this->sent_mail[1]['to'] );
		$this->assertStringContainsString( 'please forward the link below', $this->sent_mail[1]['message'] );
		$this->assertStringContainsString( $claim_query, $this->sent_mail[1]['message'] );
	}

	/**
	 * A one-ticket order for the buyer themselves still only gets the receipt.
	 *
	 * @covers CampTix_Require_Login::send_claim_link_for_single_unknown_attendee
	 */
	public function test_checkout_sends_only_receipt_when_buyer_row_is_known() {
		$this->log_in_buyer();

		$this->check_out_one_ticket_for_unknown_attendee( false );

		$this->assertSame( array( 'buyer@example.org' ), wp_list_pluck( $this->sent_mail, 'to' ) );
	}

	/**
	 * The buyer's own abandoned order is still theirs when their row is an unknown attendee.
	 *
	 * CampTix finds a buyer's own orders by the buyer's username on the buyer row, so it can leave
	 * their abandoned drafts out of their counts. An unknown attendee on that row has no username.
	 *
	 * @covers CampTix_Require_Login::add_username_to_attendee_object
	 * @covers CampTix_Require_Login::save_checkout_username_meta
	 */
	public function test_buyers_abandoned_order_is_found_when_buyer_row_is_unknown() {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		$this->log_in_buyer();
		$draft_id = $this->insert_abandoned_order_for_unknown_attendee();

		$this->assertSame( array( $draft_id ), $camptix->get_abandoned_draft_attendee_ids() );
	}

	/**
	 * Someone else's abandoned order isn't treated as the buyer's own when its buyer row is an unknown attendee.
	 *
	 * @covers CampTix_Require_Login::add_username_to_attendee_object
	 * @covers CampTix_Require_Login::save_checkout_username_meta
	 */
	public function test_someone_elses_abandoned_order_is_not_found_when_buyer_row_is_unknown() {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		$this->log_in_buyer();
		$this->insert_abandoned_order_for_unknown_attendee();

		wp_set_current_user( self::factory()->user->create() );

		$this->assertSame( array(), $camptix->get_abandoned_draft_attendee_ids() );
	}

	/**
	 * Write the draft of a one-ticket order for an unknown attendee on the buyer's row, as checkout
	 * does, and let its payment session expire long enough ago to count as abandoned.
	 *
	 * @return int The draft attendee ID.
	 */
	protected function insert_abandoned_order_for_unknown_attendee() {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		$attendee             = new stdClass();
		$attendee->ticket_id  = self::factory()->post->create( array( 'post_type' => 'tix_ticket' ) );
		$attendee->first_name = 'Unknown';
		$attendee->last_name  = 'Attendee';
		$attendee->email      = CampTix_Require_Login::UNKNOWN_ATTENDEE_EMAIL;
		$attendee->answers    = array();

		$attendee = apply_filters( 'camptix_form_register_complete_attendee_object', $attendee, array( 'unknown_attendee' => '1' ), 1 );
		$drafts   = $camptix->insert_attendee_drafts( array( $attendee ), 'stripe', 'buyer@example.org', wp_generate_password( 12, false ), wp_generate_password( 12, false ) );
		$draft_id = $drafts[0]->post_id;

		update_post_meta( $draft_id, 'tix_session_expires_at', time() - CampTix_Plugin::DRAFT_LIFETIME_GRACE - MINUTE_IN_SECONDS );

		return $draft_id;
	}

	/**
	 * Log in a buyer whose profile has an email but no name, like many WordPress.org profiles.
	 *
	 * @return WP_User
	 */
	protected function log_in_buyer() {
		$user_id = self::factory()->user->create( array(
			'user_email' => 'buyer@example.org',
			'first_name' => '',
			'last_name'  => '',
		) );

		wp_set_current_user( $user_id );

		return get_userdata( $user_id );
	}

	/**
	 * Check out one free ticket with "I don't know who will use this ticket yet" ticked on the buyer's row.
	 *
	 * The row is submitted as the form sends it: the buyer's pre-filled email is still in the hidden field.
	 *
	 * @param bool $unknown_attendee Whether the box is ticked. If it isn't, the buyer fills in their own name.
	 *
	 * @return int The attendee ID.
	 */
	protected function check_out_one_ticket_for_unknown_attendee( $unknown_attendee = true ) {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		$ticket_id = self::factory()->post->create( array(
			'post_type'   => 'tix_ticket',
			'post_status' => 'publish',
			'post_title'  => 'Free',
		) );
		update_post_meta( $ticket_id, 'tix_price', 0 );
		update_post_meta( $ticket_id, 'tix_quantity', 10 );

		$ticket                       = get_post( $ticket_id );
		$ticket->tix_price            = 0;
		$ticket->tix_remaining        = 10;
		$ticket->tix_coupon_applied   = false;
		$ticket->tix_discounted_price = 0;

		// What template_redirect() would have set up for this order.
		$this->set_camptix_property( 'error_flags', array() );
		$this->set_camptix_property( 'shortcode_str', '[camptix]' );
		$this->set_camptix_property( 'tickets', array( $ticket_id => $ticket ) );
		$this->set_camptix_property( 'tickets_selected', array( $ticket_id => 1 ) );
		$this->set_camptix_property( 'tickets_selected_count', 1 );
		$order = array(
			'items' => array(
				array(
					'id'          => $ticket_id,
					'name'        => 'Free',
					'description' => '',
					'quantity'    => 1,
					'price'       => 0,
				),
			),
			'total' => 0,
		);

		$this->set_camptix_property( 'order', $order );

		$GLOBALS['post'] = get_post( self::factory()->post->create( array(
			'post_type'    => 'page',
			'post_content' => '[camptix]',
		) ) );

		$_GET['tix_action']         = 'checkout';
		$_POST['tix_receipt_email'] = 1;
		$_POST['tix_attendee_info'] = array(
			1 => array(
				'ticket_id'        => $ticket_id,
				'first_name'       => '',
				'last_name'        => '',
				'email'            => 'buyer@example.org',
				'unknown_attendee' => '1',
			),
		);

		if ( ! $unknown_attendee ) {
			unset( $_POST['tix_attendee_info'][1]['unknown_attendee'] );
			$_POST['tix_attendee_info'][1]['first_name'] = 'Jane';
			$_POST['tix_attendee_info'][1]['last_name']  = 'Buyer';
		}

		add_filter( 'camptix_wp_mail_override', array( $this, 'capture_mail' ), 10, 2 );
		add_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );

		try {
			$camptix->form_checkout();
			$this->fail( 'Checkout did not complete.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 'redirect', $e->getMessage() );
		}

		$attendee_ids = get_posts( array(
			'post_type'   => 'tix_attendee',
			'post_status' => 'publish',
			'fields'      => 'ids',
			'meta_key'    => 'tix_ticket_id',
			'meta_value'  => $ticket_id,
		) );

		$this->assertCount( 1, $attendee_ids );

		return $attendee_ids[0];
	}

	/**
	 * Set one of CampTix's protected properties.
	 *
	 * @param string $name
	 * @param mixed  $value
	 */
	protected function set_camptix_property( $name, $value ) {
		$property = new ReflectionProperty( 'CampTix_Plugin', $name );

		if ( ! array_key_exists( $name, $this->camptix_properties ) ) {
			$this->camptix_properties[ $name ] = $property->getValue( $GLOBALS['camptix'] );
		}

		$property->setValue( $GLOBALS['camptix'], $value );
	}

	/**
	 * Record an outgoing CampTix e-mail instead of sending it.
	 *
	 * @param bool  $override
	 * @param array $mail
	 *
	 * @return bool
	 */
	public function capture_mail( $override, $mail ) {
		$this->sent_mail[] = $mail;

		return true;
	}

	/**
	 * End the request where checkout redirects to the purchased tickets, before it calls die().
	 *
	 * @throws WPDieException Always.
	 */
	public function stop_redirect() {
		throw new WPDieException( 'redirect' );
	}
}
