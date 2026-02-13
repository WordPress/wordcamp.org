<?php
namespace WordCamp\Reports\Views\HTML\Data_Table;
defined( 'WPINC' ) || die();

/** @var array $data */
/** @var array? $safe_html */

if ( ! empty( $data ) && is_array( $data ) ) {

	$escape = static function ( $value ) use ( $safe_html ) {
		if ( isset( $safe_html ) ) {
			return wp_kses( $value, $safe_html );
		}

		return esc_html( $value );
	};

	echo '<table class=" widefat fixed striped" id="report-data-table">';
	echo '<thead><tr>';
	foreach ( array_keys( reset( $data ) ) as $header ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<th>' . $escape( $header ) . '</th>';
	}
	echo '</tr></thead>';

	echo '<tbody>';
	foreach ( $data as $row ) {
		echo '<tr>';
		foreach ( $row as $cell ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . make_clickable( $escape( $cell ) ) . '</td>';
		}
		echo '</tr>';
	}
	echo '</tbody>';
	echo '</table>';
} else {
	echo '<p>No data available.</p>';
}
