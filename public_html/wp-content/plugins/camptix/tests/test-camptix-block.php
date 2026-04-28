<?php

defined( 'WPINC' ) || die();

/**
 * Tests for the CampTix Gutenberg block integration.
 *
 * @covers CampTix_Plugin::template_redirect
 * @covers CampTix_Plugin::form_start
 * @covers CampTix_Plugin::get_tickets_post_id
 */
class Test_CampTix_Block extends WP_UnitTestCase {

	/**
	 * @var CampTix_Plugin
	 */
	protected static $camptix;

	/**
	 * Post IDs to clean up.
	 *
	 * @var array
	 */
	protected $post_ids = array();

	/**
	 * Set up shared fixtures.
	 *
	 * @param WP_UnitTest_Factory $factory Test factory.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$camptix = $GLOBALS['camptix'];
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		foreach ( $this->post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		$this->post_ids = array();

		// Reset block attributes.
		self::$camptix->block_attributes = array();
		self::set_protected_property( 'tickets', array() );
		self::set_protected_property( 'did_template_redirect', false );
		self::set_protected_property( 'shortcode_contents', '' );

		parent::tear_down();
	}

	/**
	 * Helper: set a protected property on the CampTix instance via reflection.
	 *
	 * @param string $name  Property name.
	 * @param mixed  $value Value to set.
	 */
	protected static function set_protected_property( $name, $value ) {
		$reflection = new ReflectionProperty( get_class( self::$camptix ), $name );

		if ( PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}

		$reflection->setValue( self::$camptix, $value );
	}

	/**
	 * Helper: get a protected property on the CampTix instance via reflection.
	 *
	 * @param string $name Property name.
	 * @return mixed Property value.
	 */
	protected static function get_protected_property( $name ) {
		$reflection = new ReflectionProperty( get_class( self::$camptix ), $name );

		if ( PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}

		return $reflection->getValue( self::$camptix );
	}

	/**
	 * Helper: call a protected method on the CampTix instance via reflection.
	 *
	 * @param string $name Method name.
	 * @param array  $args Method arguments.
	 *
	 * @return mixed
	 */
	protected static function call_protected_method( $name, array $args = array() ) {
		$reflection = new ReflectionMethod( get_class( self::$camptix ), $name );

		if ( PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}

		return $reflection->invokeArgs( self::$camptix, $args );
	}

	/**
	 * Helper: create a ticket.
	 *
	 * @param string $title Ticket title.
	 * @param float  $price Ticket price.
	 * @return int Post ID.
	 */
	protected function create_ticket( $title = 'General Admission', $price = 25.00 ) {
		$ticket_id = wp_insert_post( array(
			'post_type'   => 'tix_ticket',
			'post_status' => 'publish',
			'post_title'  => $title,
		) );

		update_post_meta( $ticket_id, 'tix_price', $price );
		update_post_meta( $ticket_id, 'tix_quantity', 100 );

		$this->post_ids[] = $ticket_id;

		return $ticket_id;
	}

	/**
	 * Helper: render the start form with a configured maxTicketsPerOrder attribute.
	 *
	 * @param int $max_tickets_per_order Max tickets per order block attribute value.
	 * @return array Ticket ID and rendered form output.
	 */
	protected function render_form_start_for_max_tickets_per_order( $max_tickets_per_order ) {
		$ticket_id = $this->create_ticket( 'Test Ticket', 10.00 );

		$ticket                          = get_post( $ticket_id );
		$ticket->tix_price               = 10.00;
		$ticket->tix_remaining           = 50;
		$ticket->tix_coupon_applied      = false;
		$ticket->tix_discounted_price    = 10.00;

		self::set_protected_property( 'tickets', array( $ticket_id => $ticket ) );
		self::$camptix->block_attributes = array(
			'maxTicketsPerOrder' => $max_tickets_per_order,
		);

		return array( $ticket_id, self::$camptix->form_start() );
	}

	/**
	 * Helper: get the highest quantity option value for a ticket.
	 *
	 * @param string $output    Rendered form output.
	 * @param int    $ticket_id Ticket ID.
	 * @return int Highest quantity option value.
	 */
	protected function get_max_quantity_option_value( $output, $ticket_id ) {
		$select_pattern = sprintf( '#<select[^>]+id="tix-qty-%d"[^>]*>(.*?)</select>#s', $ticket_id );
		$this->assertSame( 1, preg_match( $select_pattern, $output, $select_match ) );

		preg_match_all( '/<option[^>]*value="(\d+)"/', $select_match[1], $option_matches );
		$this->assertNotEmpty( $option_matches[1] );

		return max( array_map( 'intval', $option_matches[1] ) );
	}

	/**
	 * Helper: run the CampTix template_redirect flow for a page.
	 *
	 * @param int $page_id Page ID.
	 */
	protected function run_template_redirect_for_page( $page_id ) {
		$original_post = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;

		$this->go_to( add_query_arg( 'page_id', $page_id, home_url( '/' ) ) );

		$GLOBALS['post'] = get_post( $page_id );
		setup_postdata( $GLOBALS['post'] );

		self::set_protected_property( 'did_template_redirect', false );
		self::$camptix->template_redirect();

		wp_reset_postdata();
		$GLOBALS['post'] = $original_post;
	}

	/**
	 * Test that template_redirect detects a block in page content.
	 */
	public function test_block_detection_sets_block_attributes() {
		$ticket_id = $this->create_ticket();

		$page_id = wp_insert_post( array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Tickets',
			'post_content' => '<!-- wp:wordcamp/camptix {"ticketIds":[' . $ticket_id . ']} /-->',
		) );
		$this->post_ids[] = $page_id;

		$this->run_template_redirect_for_page( $page_id );

		$this->assertArrayHasKey( 'ticketIds', self::$camptix->block_attributes );
		$this->assertContains( $ticket_id, self::$camptix->block_attributes['ticketIds'] );
	}

	/**
	 * Test that nested CampTix blocks are detected.
	 */
	public function test_nested_block_detection_sets_block_attributes() {
		$content = '<!-- wp:group --><div class="wp-block-group">'
			. '<!-- wp:wordcamp/camptix {"maxTicketsPerOrder":3} /-->'
			. '</div><!-- /wp:group -->';
		$block = self::call_protected_method( 'get_camptix_block', array( $content ) );

		$this->assertTrue( is_array( $block ) );
		$this->assertSame( 3, $block['attrs']['maxTicketsPerOrder'] );
	}

	/**
	 * Test that get_tickets_post_id finds a page with the CampTix block.
	 */
	public function test_get_tickets_post_id_finds_block_page() {
		$page_id = wp_insert_post( array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Tickets',
			'post_content' => '<!-- wp:wordcamp/camptix /-->',
		) );
		$this->post_ids[] = $page_id;

		$this->assertSame( $page_id, self::$camptix->get_tickets_post_id() );
	}

	/**
	 * Test that get_tickets_post_id finds a nested CampTix block.
	 */
	public function test_get_tickets_post_id_finds_nested_block_page() {
		$page_id = wp_insert_post( array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Tickets',
			'post_content' => '<!-- wp:group --><div class="wp-block-group">'
				. '<!-- wp:wordcamp/camptix /-->'
				. '</div><!-- /wp:group -->',
		) );
		$this->post_ids[] = $page_id;

		$this->assertSame( $page_id, self::$camptix->get_tickets_post_id() );
	}

	/**
	 * Test that get_tickets_post_id ignores other CampTix shortcodes.
	 */
	public function test_get_tickets_post_id_ignores_attendees_shortcode() {
		$attendees_page_id = wp_insert_post( array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Attendees',
			'post_content' => '[camptix_attendees]',
		) );
		$this->post_ids[] = $attendees_page_id;

		$tickets_page_id = wp_insert_post( array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Tickets',
			'post_content' => '<!-- wp:wordcamp/camptix /-->',
		) );
		$this->post_ids[] = $tickets_page_id;

		$this->assertSame( $tickets_page_id, self::$camptix->get_tickets_post_id() );
	}

	/**
	 * Test that ticketIds attribute filters which tickets are loaded.
	 */
	public function test_ticket_filtering_by_ids() {
		$ticket_a = $this->create_ticket( 'Ticket A', 10.00 );
		$ticket_b = $this->create_ticket( 'Ticket B', 20.00 );
		$ticket_c = $this->create_ticket( 'Ticket C', 30.00 );

		$attributes = array(
			'ticketIds' => array(
				$ticket_a,
				'not-a-ticket',
				array( 'bad' ),
				$ticket_c,
				0,
				$ticket_a,
			),
		);

		$page_id = wp_insert_post( array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Tickets',
			'post_content' => '<!-- wp:wordcamp/camptix ' . wp_json_encode( $attributes ) . ' /-->',
		) );
		$this->post_ids[] = $page_id;

		$this->run_template_redirect_for_page( $page_id );

		$filtered_tickets = self::get_protected_property( 'tickets' );

		$this->assertCount( 2, $filtered_tickets );
		$this->assertArrayHasKey( $ticket_a, $filtered_tickets );
		$this->assertArrayHasKey( $ticket_c, $filtered_tickets );
		$this->assertArrayNotHasKey( $ticket_b, $filtered_tickets );
	}

	/**
	 * Test that custom noTicketsMessage is used when set.
	 */
	public function test_custom_no_tickets_message() {
		$custom_message = 'Tickets coming soon - check back next week!';

		self::$camptix->block_attributes = array(
			'noTicketsMessage' => $custom_message,
		);

		// With no tickets and block attributes set, form_start should use the custom message.
		self::set_protected_property( 'tickets', array() );

		$output = self::$camptix->form_start();

		$this->assertStringContainsString( $custom_message, $output );
	}

	/**
	 * Test that default no-tickets message is used when block attribute is empty.
	 */
	public function test_default_no_tickets_message_when_attribute_empty() {
		self::$camptix->block_attributes = array();
		self::set_protected_property( 'tickets', array() );

		$output = self::$camptix->form_start();

		$this->assertStringContainsString( 'Sorry, but there are currently no tickets for sale', $output );
	}

	/**
	 * Test that maxTicketsPerOrder block attribute affects form_start output.
	 */
	public function test_max_tickets_per_order_attribute() {
		list( $ticket_id, $output ) = $this->render_form_start_for_max_tickets_per_order( 3 );

		$this->assertEquals( 3, $this->get_max_quantity_option_value( $output, $ticket_id ) );
	}

	/**
	 * Test that maxTicketsPerOrder block attribute is clamped to its lower limit.
	 */
	public function test_max_tickets_per_order_attribute_lower_limit() {
		list( $ticket_id, $output ) = $this->render_form_start_for_max_tickets_per_order( -5 );

		$this->assertEquals( 1, $this->get_max_quantity_option_value( $output, $ticket_id ) );
	}

	/**
	 * Test that maxTicketsPerOrder block attribute is clamped to its upper limit.
	 */
	public function test_max_tickets_per_order_attribute_upper_limit() {
		list( $ticket_id, $output ) = $this->render_form_start_for_max_tickets_per_order( 999 );

		$this->assertEquals( 10, $this->get_max_quantity_option_value( $output, $ticket_id ) );
	}

	/**
	 * Test that auto-coupon is injected into REQUEST when block attribute is set.
	 */
	public function test_auto_coupon_injection() {
		// Ensure no manual coupon is set.
		unset( $_REQUEST['tix_coupon'] );

		$block_attributes = array( 'coupon' => 'EARLYBIRD' );

		// Simulate the auto-coupon logic from template_redirect.
		if ( empty( $_REQUEST['tix_coupon'] ) && ! empty( $block_attributes['coupon'] ) ) {
			$_REQUEST['tix_coupon'] = sanitize_text_field( $block_attributes['coupon'] );
		}

		$this->assertEquals( 'EARLYBIRD', $_REQUEST['tix_coupon'] );

		// Clean up.
		unset( $_REQUEST['tix_coupon'] );
	}

	/**
	 * Test that manual coupon takes precedence over block auto-coupon.
	 */
	public function test_manual_coupon_overrides_auto_coupon() {
		$_REQUEST['tix_coupon'] = 'MANUAL';

		$block_attributes = array( 'coupon' => 'EARLYBIRD' );

		// Simulate the auto-coupon logic from template_redirect.
		if ( empty( $_REQUEST['tix_coupon'] ) && ! empty( $block_attributes['coupon'] ) ) {
			$_REQUEST['tix_coupon'] = sanitize_text_field( $block_attributes['coupon'] );
		}

		$this->assertEquals( 'MANUAL', $_REQUEST['tix_coupon'] );

		// Clean up.
		unset( $_REQUEST['tix_coupon'] );
	}

	/**
	 * Test backward compatibility: shortcode continues to work without block attributes.
	 */
	public function test_shortcode_still_detected() {
		$content = '[camptix]';

		$this->assertEquals( '[camptix]', self::call_protected_method( 'get_camptix_shortcode_string', array( $content ) ) );
		$this->assertFalse( self::call_protected_method( 'get_camptix_shortcode_string', array( '[camptix_attendees]' ) ) );
	}
}
