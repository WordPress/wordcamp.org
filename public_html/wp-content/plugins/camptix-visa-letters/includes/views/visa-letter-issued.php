<?php

defined( 'WPINC' ) || die();

/** @var string $letter_number */

?>

<div class="camptix-visa-letter-issued">
	<p>
		<strong><?php echo esc_html__( 'Visa invitation letter', 'wordcamporg' ); ?></strong><br />
		<?php
		printf(
			/* translators: %s: visa letter reference number. */
			esc_html__( 'Your visa invitation letter (Ref: %s) has been issued and emailed to you.', 'wordcamporg' ),
			esc_html( $letter_number )
		);
		?>
		<?php echo esc_html__( 'If any details on the letter need to change, please contact the event organizers.', 'wordcamporg' ); ?>
	</p>
</div>
