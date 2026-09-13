<?php

defined( 'WPINC' ) || die();

/** @var string $letter_number */
/** @var string $letter_url */

?>

<div class="misc-pub-section">
	<p>
		<?php echo esc_html__( 'Visa letter number', 'wordcamporg' ); ?> <strong><?php echo esc_html( $letter_number ); ?></strong>
	</p>
	<?php if ( ! empty( $letter_url ) ) { ?>
		<a
			href="<?php echo esc_attr( $letter_url ); ?>"
			class="button button-secondary"
			target="_blank"
		>
			<?php echo esc_html__( 'Download visa letter', 'wordcamporg' ); ?>
		</a>
	<?php } ?>
</div>
