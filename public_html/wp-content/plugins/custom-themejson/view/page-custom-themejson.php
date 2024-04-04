<?php
namespace WordCamp\CustomThemeJSON;

defined( 'WPINC' ) || die();

/**
 * @var string $custom_themejson_url
 */

?>
<div class="wrap">
<h1><?php esc_html_e( 'Custom Theme.json', 'wordcamporg' ); ?></h1>

<form action="" method="POST">
	<?php wp_nonce_field( 'wcrcss-options-submit', 'wcrcss-options-nonce' ); ?>

		<p>
			<label>
				<?php esc_html_e( 'Custom Theme.json URL:', 'wordcamporg' ); ?><br />
				<input type="text" name="wcctjsn-custom-themejson-url" class="large-text" value="<?php echo esc_url( $custom_themejson_url ); ?>" />
			</label>
		</p>

		<?php submit_button( __( 'Update', 'wordcamporg' ) ); ?>
</form>
</div>
