<?php
defined( 'WPINC' ) || die();

/** @var array $application_data */
?>

<table class="widefat striped">
	<tbody>
		<?php foreach ( $application_data as $question => $answer ) :
			$question_hr = ucfirst( str_replace( '_', ' ', preg_replace('/q_\d+_/', '$1', $question ) ) ); ?>
			<tr>
				<th style="width:20%;"><p><b><?php echo esc_html( $question_hr ) ?></b></p></th>
				<th>
					<?php if ( is_array( $answer ) ) {
						echo esc_html( implode( ', ', $answer ) );
					} else {
						echo esc_html( $answer );
					} ?>
				</th>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
