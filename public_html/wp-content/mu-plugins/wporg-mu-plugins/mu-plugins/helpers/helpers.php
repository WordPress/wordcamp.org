<?php

namespace WordPressdotorg\MU_Plugins\Helpers;
defined( 'WPINC' ) || die();

/**
 * Join a string with a natural language conjunction at the end.
 *
 * Based on https://stackoverflow.com/a/25057951/450127, modified to include an Oxford comma.
 */
function natural_language_join( array $list, $conjunction = 'and' ) : string {
	if ( empty( $list ) ) {
		return '';
	}

	$oxford_separator = 2 === count( $list ) ? ' ' : ', ';
	$last             = array_pop( $list );

	if ( $list ) {
		return implode( ', ', $list ) . $oxford_separator . $conjunction . ' ' . $last;
	}

	return $last;
}
