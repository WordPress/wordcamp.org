<?php

defined( 'WPINC' ) || die();

/** @var string $id */
/** @var string $value */
/** @var string $description */

?>

<input type="text" value="<?php echo esc_attr( $value ); ?>" name="camptix_options[<?php echo esc_html( $id ); ?>]">
<p class="description">
	<?php echo esc_html( $description ); ?>
	<br />
	<a href="<?php esc_attr__( 'https://wordpress.org/support/article/formatting-date-and-time/', 'wordcamporg' ); ?>">
		<?php echo esc_html__( 'Documentation on date and time formatting', 'wordcamporg' ); ?>
	</a>
</p>
