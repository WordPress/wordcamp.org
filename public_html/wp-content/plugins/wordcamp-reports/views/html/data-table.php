<?php
namespace WordCamp\Reports\Views\HTML\Data_Table;
defined( 'WPINC' ) || die();

/** @var array $data */

if ( ! empty( $data ) && is_array( $data ) ) {
	echo '<table class=" widefat fixed striped" id="report-data-table">';
	// Table headers
	echo '<thead><tr>';
	foreach ( array_keys( reset( $data ) ) as $header ) {
		echo '<th>' . esc_html( $header ) . '</th>';
	}
	echo '</tr></thead>';

	// Table rows
	echo '<tbody>';
	foreach ( $data as $row ) {
		echo '<tr>';
		foreach ( $row as $cell ) {
			echo '<td>' . make_clickable( esc_html( $cell ) ) . '</td>';
		}
		echo '</tr>';
	}
	echo '</tbody>';
	echo '</table>';
} else {
	echo '<p>No data available.</p>';
}