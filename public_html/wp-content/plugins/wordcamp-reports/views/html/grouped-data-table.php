<?php
namespace WordCamp\Reports\Views\HTML\Grouped_Data_Table;
defined( 'WPINC' ) || die();

/** @var array $groups */
/** @var array? $safe_html */

if ( ! empty( $groups ) && is_array( $groups ) ) {

	$escape = static function ( $value ) use ( $safe_html ) {
		if ( isset( $safe_html ) ) {
			return wp_kses( $value, $safe_html );
		}

		return esc_html( $value );
	};

	foreach ( $groups as $group ) {
		printf( '<h2>%s</h2>', esc_html( $group['title'] ) );
		foreach ( array_diff_key( $group, [ 'title' => '', 'data' => '' ] ) as $extra_data_key => $extra_data_value ) {
			printf(
				'<p><strong>%s:</strong> %s</p>',
				$escape( ucwords( str_replace( '_', ' ', $extra_data_key ) ) ),
				make_clickable( esc_html( $extra_data_value ) )
			);
		}

		$data = $group['data'];
		include __DIR__ . '/data-table.php';
	}
} else {
	echo '<p>No data available.</p>';
}
