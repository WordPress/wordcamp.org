<?php

use PHPUnit\Framework\TestCase;

class Camptix_Actions_Test extends WP_UnitTestCase {

	public function testConstants() {
		$this->assertSame( 'tix_action', Camptix_Actions::TICKET_ACTION );
		$this->assertSame( 'tix_coupon', Camptix_Actions::COUPON );
		$this->assertSame( 'tix_single_ticket_purchase', Camptix_Actions::SINGLE_TICKET_PURCHASE );
		$this->assertSame( 'tix_tickets_selected', Camptix_Actions::TICKETS_SELECTED );
		$this->assertSame( 'tix_attendee_id', Camptix_Actions::ATTENDEE_ID );
		$this->assertSame( 'tix_edit_token', Camptix_Actions::EDIT_TOKEN );
		$this->assertSame( 'tix_access_token', Camptix_Actions::ACCESS_TOKEN );
		$this->assertSame( 'tix_reservation_id', Camptix_Actions::RESERVATION_ID );
		$this->assertSame( 'tix_reservation_token', Camptix_Actions::RESERVATION_TOKEN );
		$this->assertSame( 'tix_errors', Camptix_Actions::ERRORS );
	}

	public function testGetAllowedParameters() {
		$parameters = Camptix_Actions::get_allowed_parameters();

		// Check the keys and their associated types
		$this->assertArrayHasKey( Camptix_Actions::TICKET_ACTION, $parameters );
		$this->assertInstanceOf( Camptix_Actions::class, $parameters[ Camptix_Actions::TICKET_ACTION ] );

		$this->assertArrayHasKey( Camptix_Actions::TICKETS_SELECTED, $parameters );
		$this->assertInstanceOf( Camptix_Actions::class, $parameters[ Camptix_Actions::TICKETS_SELECTED ] );

		// More assertions for each expected key
		$this->assertArrayHasKey( Camptix_Actions::COUPON, $parameters );
		$this->assertArrayHasKey( Camptix_Actions::ATTENDEE_ID, $parameters );
		$this->assertArrayHasKey( Camptix_Actions::EDIT_TOKEN, $parameters );
		$this->assertArrayHasKey( Camptix_Actions::ACCESS_TOKEN, $parameters );
		$this->assertArrayHasKey( Camptix_Actions::RESERVATION_ID, $parameters );
		$this->assertArrayHasKey( Camptix_Actions::RESERVATION_TOKEN, $parameters );
		$this->assertArrayHasKey( Camptix_Actions::ERRORS, $parameters );
	}
}
