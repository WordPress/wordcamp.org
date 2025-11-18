<?php

defined( 'WPINC' ) || die();

/** @var string $id */
/** @var string $value */
/** @var string $description */

?>

<input
	value="<?php echo esc_attr( $value ? sprintf( '%.1f%%', $value ) : '0%' ); ?>"
	name="camptix_options[<?php echo esc_attr( $id ); ?>]"
	id="<?php echo esc_attr( $id ); ?>"
	class="regular-text"
	/>
<p class="description">
	<?php echo esc_html( $description ); ?>
</p>

<script>
(function() {
	const percentageInput = document.getElementById('<?php echo esc_js( $id ); ?>');

	percentageInput.addEventListener('blur', () => {
		if ( percentageInput.value.endsWith('%') ) {
			percentageInput.value = percentageInput.value.slice(0, -1);
		}

		percentageInput.value = percentageInput.value > 0 ? parseFloat( percentageInput.value ).toFixed(1) : 0;
		percentageInput.value += '%';
	});

	percentageInput.addEventListener('focus', () => {
		if ( percentageInput.value.endsWith('%') ) {
			percentageInput.value = percentageInput.value.slice(0, -1);
		}
	});
})();
</script>
