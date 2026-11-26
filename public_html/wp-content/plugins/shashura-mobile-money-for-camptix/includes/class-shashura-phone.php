<?php
/**
 * Uganda mobile number helpers.
 *
 * @package CampTix_Shashura
 */

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes, formats and masks Uganda mobile numbers.
 */
class Shasmomo_Phone {

	/**
	 * Normalize to MSISDN digits (2567XXXXXXXX). Returns '' if it is not a UG mobile number.
	 * Accepts 0772 123 456, 772123456, +256 772 123456, 256772123456.
	 *
	 * @param string $input Number as the buyer typed it.
	 * @return string
	 */
	public static function normalize( $input ) {
		$digits = preg_replace( '/\D+/', '', (string) $input );
		if ( strlen( $digits ) > 15 ) {
			return '';
		}

		if ( 0 === strpos( $digits, '256' ) ) {
			$national = substr( $digits, 3 );
		} elseif ( 0 === strpos( $digits, '0' ) ) {
			$national = substr( $digits, 1 );
		} else {
			$national = $digits;
		}

		return preg_match( '/^7\d{8}$/', $national ) ? '256' . $national : '';
	}

	/**
	 * Format for display: +256 772 123 456.
	 *
	 * @param string $msisdn Normalized number.
	 * @return string
	 */
	public static function pretty( $msisdn ) {
		return '+256 ' . substr( $msisdn, 3, 3 ) . ' ' . substr( $msisdn, 6, 3 ) . ' ' . substr( $msisdn, 9 );
	}

	/**
	 * Mask for display: +256 77* *** 456.
	 *
	 * @param string $msisdn Normalized number.
	 * @return string
	 */
	public static function mask( $msisdn ) {
		return '+256 ' . substr( $msisdn, 3, 2 ) . '* *** ' . substr( $msisdn, -3 );
	}
}
