<?php
defined( 'WPINC' ) || die();

/** @var array $application_data */
?>
<?php if ( $application_data ) : ?>
<table class="widefat striped">
	<tbody>
		<?php foreach ( $application_data as $question => $answer ) :
			$question_hr = ucfirst( str_replace( '_', ' ', preg_replace('/q_\d+_/', '$1', $question ) ) );
			$answer      = is_array( $answer ) ? implode( ', ', $answer ) : $answer;
			?>
			<tr>
				<th style="width:20%;"><p><b><?php echo esc_html( $question_hr ); ?></b></p></th>
				<th>
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - This is as escaped as it needs to be.
					echo make_clickable( nl2br( esc_html( $answer ) ) );
					?>
				</th>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
<?php else : ?>
	<p><?php esc_html_e( 'No application data found.', 'wordcamporg' ); ?></p>
<?php endif;
