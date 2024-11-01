<?php

class Camptix_Actions {

	// Denotes ticketing is happening.
	public const TICKET_ACTION = 'tix_action';

	// Coupons.
	public const COUPON = 'tix_coupon';

	// Third Party for ticket purchases.
	public const SINGLE_TICKET_PURCHASE = 'tix_single_ticket_purchase';

	// Passed along.
	public const TICKETS_SELECTED = 'tix_tickets_selected';

	// Editing ticket information.
	public const ATTENDEE_ID  = 'tix_attendee_id';
	public const EDIT_TOKEN   = 'tix_edit_token';
	public const ACCESS_TOKEN = 'tix_access_token';

	// Reservations.
	public const RESERVATION_ID    = 'tix_reservation_id';
	public const RESERVATION_TOKEN = 'tix_reservation_token';

	// Generic errors.
	public const ERRORS = 'tix_errors';

	private $type;
	private $sanitizer;

	private function __construct( string $type, callable $sanitizer ) {
		$this->type      = $type;
		$this->sanitizer = $sanitizer;
	}

	public static function TEXT(): self {
		return new self( 'text', fn( $value ) => sanitize_text_field( $value ) );
	}

	public static function INTEGER(): self {
		return new self( 'int', fn( $value ) => absint( $value ) );
	}

	public static function ARRAY_INTEGER(): self {
		return new self( 'array_int', fn( $value ) => is_array( $value )
			? array_map( 'absint', $value )
			: array( absint( $value ) )
		);
	}

	public static function ARRAY_STR(): self {
		return new self( 'array_str', fn( $value ) => is_array( $value )
			? array_map( 'sanitize_text_field', $value )
			: array( sanitize_text_field( $value ) )
		);
	}

	public static function get_allowed_parameters(): array {
		return [
			self::TICKET_ACTION     => self::TEXT(),
			self::TICKETS_SELECTED  => self::ARRAY_INTEGER(),
			self::COUPON            => self::TEXT(),
			self::ATTENDEE_ID       => self::INTEGER(),
			self::EDIT_TOKEN        => self::TEXT(),
			self::ACCESS_TOKEN      => self::TEXT(),
			self::RESERVATION_ID    => self::INTEGER(),
			self::RESERVATION_TOKEN => self::TEXT(),
			self::ERRORS            => self::ARRAY_STR(),
		];
	}

	public function get_type(): string {
		return $this->type;
	}

	public function sanitize( $value ) {
		return call_user_func( $this->sanitizer, $value );
	}
}
