<?php

defined( 'WPINC' ) || die();

/** @var string $id */
/** @var string $value */

$is_signature  = ( 'visa-letter-signature' === $id );
$pick_label    = $is_signature ? __( 'Pick a signature image', 'wordcamporg' ) : __( 'Pick a logo', 'wordcamporg' );
$remove_label  = $is_signature ? __( 'Remove signature', 'wordcamporg' ) : __( 'Remove logo', 'wordcamporg' );
$description   = $is_signature
	? __( 'Upload a handwritten signature image (PNG with transparent background recommended). Max size: 200px wide, 80px tall.', 'wordcamporg' )
	: __( 'Expected image size: 250px width, 200px height', 'wordcamporg' );
$preview_style = $is_signature ? 'max-width:200px;max-height:80px;' : 'max-width:250px;max-height:200px;';

?>

<div class="camptix-media">
	<div class="camptix-visa-letter-logo-preview-wrapper" data-imagewrapper>
		<?php
		if ( ! empty( $value ) ) {
			$attachment = wp_get_attachment_image_src( $value, 'full' );
			printf( '<img src="%s" style="%s">', esc_url( $attachment[0] ), esc_attr( $preview_style ) );
		}
		?>
	</div>

	<input data-set type="button" class="button button-secondary" value="<?php echo esc_attr( $pick_label ); ?>" />
	<input
		data-unset
		type="button"
		class="button button-secondary"
		value="<?php echo esc_attr( $remove_label ); ?>"
		<?php
		if ( empty( $value ) ) {
			echo 'style="display:none;"';
		}
		?>
	/>
	<input type="hidden" name=camptix_options[<?php echo esc_attr( $id ); ?>] data-field="image_attachment" value="<?php echo esc_attr( $value ); ?>">
	<p class="description"><?php echo esc_html( $description ); ?></p>
</div>
